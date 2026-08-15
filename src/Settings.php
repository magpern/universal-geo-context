<?php
/**
 * Universal Geo Context plugin settings.
 *
 * Sole owner of the `universal_geo_settings` option.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo;

use UniversalGeo\Http\IpUtils;
use UniversalGeo\Resolver\GeoValidator;

/**
 * Settings owner and validator.
 *
 * `defaults()` and `sanitize()` are pure and WordPress-free (unit-testable
 * without a bootstrap) — `IpUtils`, used for trusted-proxy CIDR shape
 * validation, and `GeoValidator`, used for real country-code membership
 * (M5), are both themselves pure and WordPress-free, so this stays within
 * the purity boundary. `install()` and `uninstall()` read/write the option
 * and are the only methods that touch WordPress. Sanitization is forgiving:
 * it never throws, cleaning or dropping invalid input so persistence always
 * succeeds.
 *
 * Schema v4 (M4) scope: twelve keys — schema_version, default_country (M1),
 * trusted_proxies, trust_cloudflare, derived_cache_enabled, and
 * derived_cache_ttl (M2), `maxmind_db_path` (M3), plus (M4)
 * `remote_enabled`, `remote_account_id`, `remote_license_key`,
 * `remote_transfer_acknowledged`, and `remote_timeout`.
 *
 * Schema v5 (M6) adds six keys for managed GeoLite2 database downloads:
 * `maxmind_account_id` / `maxmind_license_key` (the new canonical, shared
 * MaxMind credential pair — see "Credential migration" below),
 * `maxmind_managed_enabled`, `maxmind_managed_auto_update_enabled`,
 * `maxmind_managed_auto_update_frequency`, and
 * `maxmind_managed_retain_previous`. `remote_account_id` and
 * `remote_license_key` remain in the schema, unchanged, as deprecated
 * fallback/migration sources — a compatibility-period decision (kept through
 * at least v1.2.0, `docs/COMPATIBILITY.md`), not a literal one-shot key
 * rename, so that no other code (`AdminScreen`, `ReferenceRemoteProvider`'s
 * credential resolution) needs to change in this same step.
 *
 * **Credential migration (M6):** a MaxMind account has one account
 * id/license key regardless of which MaxMind product it authenticates
 * against, so `maxmind_account_id`/`maxmind_license_key` are the one
 * canonical pair both the remote lookup provider and managed downloads
 * consume (via `Plugin::resolved_maxmind_credentials()`). `sanitize()`
 * performs the migration itself, purely: when both canonical fields are
 * blank in the input **and** both legacy `remote_account_id`/
 * `remote_license_key` fields are non-blank in the same input, the
 * sanitized output's canonical fields are populated from the legacy
 * values. Nothing is discarded — the legacy fields remain present in the
 * output too, unchanged, so this is a one-way, non-destructive copy, not a
 * rename; a site never silently loses a working remote-provider credential
 * because a settings save happened to run before the admin visited the new
 * "MaxMind account" UI (M6F).
 *
 * **The structural acknowledgement rule (M4 frozen decision, generalized in
 * M6):** `sanitize()` forces `remote_enabled` to `false` unless
 * `remote_transfer_acknowledged` itself sanitizes to `true` — a pure
 * sanitization rule (a boolean AND against another field in the same input),
 * not I/O, not a merge against previously stored state. An admin cannot
 * enable the remote provider in the same request that first sets the
 * acknowledgement to false, and cannot enable it at all without the
 * acknowledgement present in that same submission. M6 adds the identical
 * shape for managed downloads: `maxmind_managed_auto_update_enabled` can
 * never sanitize to `true` unless `maxmind_managed_enabled` also sanitizes
 * to `true` on that same input. The two AND-gates are fully independent —
 * enabling/acknowledging the remote provider has no bearing on managed
 * downloads, and vice versa.
 *
 * **Purity boundary** (Revision 3 §11, audit finding F6; M3 architecture
 * report §6 3B): sanitize() performs *syntactic* checks only — CIDR shape,
 * boolean cast, TTL range, and (M3) absolute-path shape — never filesystem
 * I/O, never a merge against a previously stored value. `maxmind_db_path` is
 * validated here only as a string shape (trimmed, absolute Unix-style, no
 * null bytes); `realpath()`, `is_file()`, `is_readable()`, and
 * WP_CONTENT_DIR containment are exclusively
 * `AdminScreen::handle_save_settings()`'s job, at the one point in the
 * codebase filesystem I/O against an admin-supplied path is allowed. The
 * same boundary applies to the M4 credential fields: "keep the previous
 * value when the submitted field is blank" (credential clearing behavior) is
 * an `AdminScreen`-only merge against `$previous`, never performed here —
 * `sanitize()` treats a blank credential exactly like any other blank
 * string, syntactically.
 *
 * @internal
 * @final
 */
final class Settings {
	/**
	 * Option name.
	 */
	const OPTION_NAME = 'universal_geo_settings';

	/**
	 * Current schema version.
	 */
	const SCHEMA_VERSION = 5;

	/**
	 * Accepted values for maxmind_managed_auto_update_frequency (M6). No
	 * 'daily' option — GeoLite2 Country publishes at most twice a week
	 * (Tue/Fri); a daily check would just poll for no new data most of the
	 * time.
	 */
	private const UPDATE_FREQUENCIES = array( 'weekly', 'twice_weekly' );

	/**
	 * Default maxmind_managed_auto_update_frequency (M6).
	 */
	private const DEFAULT_UPDATE_FREQUENCY = 'weekly';

	/**
	 * Default derived-context cache TTL in seconds (Revision 3 §11).
	 */
	private const DEFAULT_CACHE_TTL = 900;

	/**
	 * Lowest accepted derived_cache_ttl, seconds. A TTL below this defeats
	 * the point of caching without being invalid enough to reject outright.
	 */
	private const MIN_CACHE_TTL = 60;

	/**
	 * Highest accepted derived_cache_ttl, seconds (24 hours) — bounds how
	 * stale a cached country can ever get.
	 */
	private const MAX_CACHE_TTL = 86400;

	/**
	 * Default remote-provider request timeout, in seconds (M4 frozen policy).
	 */
	private const DEFAULT_REMOTE_TIMEOUT = 2;

	/**
	 * Lowest accepted remote_timeout, seconds (M4 frozen policy).
	 */
	private const MIN_REMOTE_TIMEOUT = 1;

	/**
	 * Highest accepted remote_timeout, seconds (M4 frozen policy) — the
	 * page-view latency bound (G10): a single remote lookup must never be
	 * able to hold a request open longer than this, even misconfigured.
	 */
	private const MAX_REMOTE_TIMEOUT = 5;

	/**
	 * The default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'schema_version'                        => self::SCHEMA_VERSION,
			'default_country'                       => '',
			'trusted_proxies'                       => array(),
			'trust_cloudflare'                      => false,
			'derived_cache_enabled'                 => true,
			'derived_cache_ttl'                     => self::DEFAULT_CACHE_TTL,
			'maxmind_db_path'                       => '',
			'remote_enabled'                        => false,
			'remote_account_id'                     => '',
			'remote_license_key'                    => '',
			'remote_transfer_acknowledged'          => false,
			'remote_timeout'                        => self::DEFAULT_REMOTE_TIMEOUT,
			'maxmind_account_id'                    => '',
			'maxmind_license_key'                   => '',
			'maxmind_managed_enabled'               => false,
			'maxmind_managed_auto_update_enabled'   => false,
			'maxmind_managed_auto_update_frequency' => self::DEFAULT_UPDATE_FREQUENCY,
			'maxmind_managed_retain_previous'       => true,
		);
	}

	/**
	 * Cleans arbitrary input into a valid, persistable settings structure.
	 *
	 * Pure and WordPress-free. Never throws. Unknown keys are dropped;
	 * missing keys are filled from defaults(); schema_version is always
	 * forced to the current value, never taken from input.
	 *
	 * @param mixed $data Arbitrary input.
	 *
	 * @return array<string, mixed> The complete, normalized settings schema.
	 */
	public static function sanitize( $data ): array {
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$transfer_acknowledged = self::sanitize_bool( $data['remote_transfer_acknowledged'] ?? false );
		$managed_enabled       = self::sanitize_bool( $data['maxmind_managed_enabled'] ?? false );

		$legacy_account_id  = self::sanitize_credential( $data['remote_account_id'] ?? '' );
		$legacy_license_key = self::sanitize_credential( $data['remote_license_key'] ?? '' );

		list( $maxmind_account_id, $maxmind_license_key ) = self::migrated_maxmind_credentials(
			self::sanitize_credential( $data['maxmind_account_id'] ?? '' ),
			self::sanitize_credential( $data['maxmind_license_key'] ?? '' ),
			$legacy_account_id,
			$legacy_license_key
		);

		return array(
			'schema_version'                        => self::SCHEMA_VERSION,
			'default_country'                       => self::sanitize_country( $data['default_country'] ?? '' ),
			'trusted_proxies'                       => self::sanitize_trusted_proxies( $data['trusted_proxies'] ?? array() ),
			'trust_cloudflare'                      => self::sanitize_bool( $data['trust_cloudflare'] ?? false ),
			'derived_cache_enabled'                 => self::sanitize_bool( $data['derived_cache_enabled'] ?? true ),
			'derived_cache_ttl'                     => self::sanitize_ttl( $data['derived_cache_ttl'] ?? self::DEFAULT_CACHE_TTL ),
			'maxmind_db_path'                       => self::sanitize_maxmind_db_path( $data['maxmind_db_path'] ?? '' ),
			// The structural acknowledgement rule (M4, frozen): remote_enabled
			// can never sanitize to true unless the acknowledgement does too —
			// a pure boolean AND, evaluated on this same input, never against
			// previously stored state.
			'remote_enabled'                        => self::sanitize_bool( $data['remote_enabled'] ?? false ) && $transfer_acknowledged,
			'remote_account_id'                     => $legacy_account_id,
			'remote_license_key'                    => $legacy_license_key,
			'remote_transfer_acknowledged'          => $transfer_acknowledged,
			'remote_timeout'                        => self::sanitize_remote_timeout( $data['remote_timeout'] ?? self::DEFAULT_REMOTE_TIMEOUT ),
			'maxmind_account_id'                    => $maxmind_account_id,
			'maxmind_license_key'                   => $maxmind_license_key,
			'maxmind_managed_enabled'               => $managed_enabled,
			// M6's identical AND-gate: auto-update can never sanitize to true
			// unless managed downloads are also enabled on this same input.
			'maxmind_managed_auto_update_enabled'   => self::sanitize_bool( $data['maxmind_managed_auto_update_enabled'] ?? false ) && $managed_enabled,
			'maxmind_managed_auto_update_frequency' => self::sanitize_update_frequency( $data['maxmind_managed_auto_update_frequency'] ?? self::DEFAULT_UPDATE_FREQUENCY ),
			'maxmind_managed_retain_previous'       => self::sanitize_bool( $data['maxmind_managed_retain_previous'] ?? true ),
		);
	}

	/**
	 * Migrates the shared MaxMind credential pair from the legacy
	 * remote-provider-only fields, non-destructively: only when both
	 * canonical fields are blank and both legacy fields are non-blank. The
	 * legacy fields are never cleared by this — they remain in the output
	 * schema unchanged (see the class docblock's "Credential migration"
	 * section) — so this is a one-way copy into the new canonical fields,
	 * never a rename.
	 *
	 * @param string $canonical_account_id  The already-sanitized maxmind_account_id from the input.
	 * @param string $canonical_license_key The already-sanitized maxmind_license_key from the input.
	 * @param string $legacy_account_id     The already-sanitized remote_account_id from the input.
	 * @param string $legacy_license_key    The already-sanitized remote_license_key from the input.
	 *
	 * @return array{0: string, 1: string} The effective [account_id, license_key] pair.
	 */
	private static function migrated_maxmind_credentials(
		string $canonical_account_id,
		string $canonical_license_key,
		string $legacy_account_id,
		string $legacy_license_key
	): array {
		$canonical_blank = '' === $canonical_account_id && '' === $canonical_license_key;
		$legacy_present  = '' !== $legacy_account_id && '' !== $legacy_license_key;

		if ( $canonical_blank && $legacy_present ) {
			return array( $legacy_account_id, $legacy_license_key );
		}

		return array( $canonical_account_id, $canonical_license_key );
	}

	/**
	 * Normalizes maxmind_managed_auto_update_frequency: an unrecognized value
	 * falls back to the default ('weekly') outright — the same "reject
	 * outright, no partial validity" shape sanitize_country() uses for a
	 * non-membership country code.
	 *
	 * @param mixed $raw Arbitrary input.
	 *
	 * @return string
	 */
	private static function sanitize_update_frequency( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return self::DEFAULT_UPDATE_FREQUENCY;
		}

		$value = strtolower( trim( $raw ) );

		return in_array( $value, self::UPDATE_FREQUENCIES, true ) ? $value : self::DEFAULT_UPDATE_FREQUENCY;
	}

	/**
	 * Normalizes a country code: uppercase, empty allowed, anything that is
	 * not a real ISO 3166-1 alpha-2 country (checked via `GeoValidator`'s
	 * own embedded allowlist — the same membership test the resolver loop
	 * applies to every provider candidate, M5 D2) is rejected to '', exactly
	 * like a structurally malformed value always has been. This closes the
	 * M1–M4 gap where a syntactically-shaped-but-nonexistent code (e.g.
	 * 'ZZ') would sanitize successfully here and only be silently dropped
	 * much later, inside the resolver loop, with no signal anywhere.
	 * `AdminScreen::handle_save_settings()` is responsible for detecting the
	 * rejection and surfacing it to the administrator — this method itself
	 * stays silent-and-forgiving, per the class's own sanitization contract.
	 *
	 * @param mixed $raw Raw country value.
	 *
	 * @return string Empty string, or a real, uppercase ISO 3166-1 alpha-2 (or 'XK') country code.
	 */
	private static function sanitize_country( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		return GeoValidator::country( $raw ) ?? '';
	}

	/**
	 * Normalizes the trusted_proxies list: keeps only syntactically valid
	 * CIDRs or bare IPs, dropping anything malformed rather than rejecting
	 * the whole list. Order is not meaningful (TrustedProxies checks
	 * membership, never sequence) but is preserved as given, duplicates
	 * removed.
	 *
	 * @param mixed $raw Arbitrary input, ideally a string[].
	 *
	 * @return string[]
	 */
	private static function sanitize_trusted_proxies( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$result = array();

		foreach ( $raw as $entry ) {
			$cidr = self::sanitize_cidr( $entry );

			if ( null !== $cidr ) {
				$result[] = $cidr;
			}
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Validates and canonicalizes a single trusted-proxy entry: a bare IP,
	 * or a CIDR whose prefix is syntactically valid for its address family
	 * and strictly greater than zero — `/0` ("trust the whole internet") is
	 * always a misconfiguration and is rejected outright, not merely
	 * discouraged. Malformed input (wrong type, unparseable address,
	 * out-of-range or non-numeric prefix) returns null and is dropped by
	 * the caller.
	 *
	 * @param mixed $raw One trusted_proxies entry.
	 *
	 * @return string|null The canonical entry (normalized address, optionally with a validated prefix), or null when malformed.
	 */
	private static function sanitize_cidr( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$value = trim( $raw );

		if ( '' === $value ) {
			return null;
		}

		$slash_position = strrpos( $value, '/' );
		$subnet_raw     = false === $slash_position ? $value : substr( $value, 0, $slash_position );
		$subnet         = IpUtils::normalize( $subnet_raw );

		if ( null === $subnet ) {
			return null;
		}

		if ( false === $slash_position ) {
			return $subnet;
		}

		$is_ipv4    = false !== filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		$max_prefix = $is_ipv4 ? 32 : 128;
		$raw_prefix = trim( substr( $value, $slash_position + 1 ) );

		if ( 1 !== preg_match( '/^\d{1,3}$/', $raw_prefix ) ) {
			return null;
		}

		$prefix = (int) $raw_prefix;

		if ( $prefix < 1 || $prefix > $max_prefix ) {
			return null;
		}

		return $subnet . '/' . $prefix;
	}

	/**
	 * Casts arbitrary input to a boolean, tolerantly (never throws).
	 *
	 * @param mixed $raw Arbitrary input.
	 *
	 * @return bool
	 */
	private static function sanitize_bool( $raw ): bool {
		return (bool) $raw;
	}

	/**
	 * Normalizes derived_cache_ttl: a non-numeric or non-positive value
	 * falls back to the default outright (Revision 3 §11's "negative →
	 * default"); a valid positive value is clamped into
	 * [MIN_CACHE_TTL, MAX_CACHE_TTL].
	 *
	 * @param mixed $raw Arbitrary input.
	 *
	 * @return int
	 */
	private static function sanitize_ttl( $raw ): int {
		$is_numeric = is_int( $raw ) || is_float( $raw )
			|| ( is_string( $raw ) && 1 === preg_match( '/^-?\d+$/', trim( $raw ) ) );

		if ( ! $is_numeric ) {
			return self::DEFAULT_CACHE_TTL;
		}

		$ttl = (int) $raw;

		if ( $ttl <= 0 ) {
			return self::DEFAULT_CACHE_TTL;
		}

		return max( self::MIN_CACHE_TTL, min( self::MAX_CACHE_TTL, $ttl ) );
	}

	/**
	 * Normalizes remote_timeout (M4 frozen policy — the page-view latency
	 * bound, G10): a non-numeric value falls back to the default (2s)
	 * outright; any numeric value, in or out of range, is clamped into
	 * [MIN_REMOTE_TIMEOUT, MAX_REMOTE_TIMEOUT] (1–5s) — unlike
	 * derived_cache_ttl, an out-of-range numeric value clamps to the nearest
	 * bound rather than falling back to the default, since "the admin typed
	 * a number that's merely too large/small" is a different failure mode
	 * than "the admin typed nothing sensible at all".
	 *
	 * @param mixed $raw Arbitrary input.
	 *
	 * @return int
	 */
	private static function sanitize_remote_timeout( $raw ): int {
		$is_numeric = is_int( $raw ) || is_float( $raw )
			|| ( is_string( $raw ) && 1 === preg_match( '/^-?\d+$/', trim( $raw ) ) );

		if ( ! $is_numeric ) {
			return self::DEFAULT_REMOTE_TIMEOUT;
		}

		return max( self::MIN_REMOTE_TIMEOUT, min( self::MAX_REMOTE_TIMEOUT, (int) $raw ) );
	}

	/**
	 * Normalizes maxmind_db_path: syntactic shape only, no filesystem I/O
	 * (the purity boundary this class's docblock states). Empty means
	 * auto-detect. Trims whitespace; rejects non-strings, null bytes, and
	 * anything not an absolute Unix-style path (leading '/') — including
	 * relative paths and, structurally, arrays (already excluded by the
	 * is_string() check). Never calls realpath(), is_file(), is_readable(),
	 * or any WordPress filesystem function; that validation belongs
	 * exclusively to AdminScreen::handle_save_settings().
	 *
	 * @param mixed $raw Arbitrary input.
	 *
	 * @return string
	 */
	private static function sanitize_maxmind_db_path( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		if ( str_contains( $raw, "\0" ) ) {
			return '';
		}

		$value = trim( $raw );

		if ( '' === $value ) {
			return '';
		}

		return str_starts_with( $value, '/' ) ? $value : '';
	}

	/**
	 * Normalizes a remote-provider credential (account id or license key):
	 * syntactic shape only, mirroring sanitize_maxmind_db_path()'s purity —
	 * trimmed, non-strings and null bytes rejected. No length or character-set
	 * restriction is enforced here; MaxMind's own account id/license key
	 * format is not this class's concern to police.
	 *
	 * @param mixed $raw Arbitrary input.
	 *
	 * @return string
	 */
	private static function sanitize_credential( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		if ( str_contains( $raw, "\0" ) ) {
			return '';
		}

		return trim( $raw );
	}

	/**
	 * Sanitizes and persists a settings value in one step.
	 *
	 * The sole write path for the settings option outside install() —
	 * AdminScreen and any future settings writer call this rather than
	 * update_option() directly, keeping Settings the exclusive owner of
	 * every update_option()/add_option() call for this option (the
	 * privacy-floor option-writer allowlist, ADR-0005).
	 *
	 * @param mixed $data Arbitrary input, as from a form submission.
	 *
	 * @return array<string, mixed> The sanitized value that was persisted.
	 */
	public static function save( $data ): array {
		$sanitized = self::sanitize( $data );

		update_option( self::OPTION_NAME, $sanitized );

		return $sanitized;
	}

	/**
	 * Ensures the option exists and holds a sanitized value.
	 *
	 * Creates the option with defaults() when absent. When present,
	 * sanitizes the stored value — valid data is preserved unchanged,
	 * malformed data is normalized. Safe to call on every activation.
	 *
	 * @return void
	 */
	public static function install(): void {
		$stored = get_option( self::OPTION_NAME, false );

		$sanitized = self::sanitize( false === $stored ? array() : $stored );

		update_option( self::OPTION_NAME, $sanitized );
	}

	/**
	 * Deletes the options this class's own uninstall ownership covers.
	 *
	 * All-or-nothing retention (CLAUDE.md core invariant 4): the settings
	 * option (this class's own), `universal_geo_provider_health`
	 * (`ProviderHealthStore`'s option, M3), the circuit breaker's
	 * `universal_geo_remote_circuit` option (M4), and — as of M6 —
	 * `UpdateLock`'s `universal_geo_maxmind_update_lock` option and
	 * `DatabaseManager`'s `universal_geo_maxmind_update_state` option: each
	 * of those classes is the sole runtime writer of its own option, but
	 * deletion is assigned to this class (the same "owns writing, doesn't
	 * own deleting" ownership split `ProviderHealthStore`/`CircuitBreaker`
	 * already established). `GeoCache::uninstall()`, `AdminScreen::uninstall()`,
	 * and — as of M6 — `UpdateScheduler::uninstall()` (clears the cron hook)
	 * and `DatabaseManager::uninstall_files()` (deletes the managed
	 * directory's files) own the remaining gaps this same invariant left
	 * open; `uninstall.php` calls all of them, closing the gap in full.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_option( self::OPTION_NAME );
		delete_option( 'universal_geo_provider_health' );
		delete_option( 'universal_geo_remote_circuit' );
		delete_option( 'universal_geo_maxmind_update_lock' );
		delete_option( 'universal_geo_maxmind_update_state' );
	}
}
