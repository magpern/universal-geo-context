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

/**
 * Settings owner and validator.
 *
 * `defaults()` and `sanitize()` are pure and WordPress-free (unit-testable
 * without a bootstrap) — `IpUtils`, used for trusted-proxy CIDR shape
 * validation, is itself pure and WordPress-free, so this stays within the
 * purity boundary. `install()` and `uninstall()` read/write the option and
 * are the only methods that touch WordPress. Sanitization is forgiving: it
 * never throws, cleaning or dropping invalid input so persistence always
 * succeeds.
 *
 * Schema v4 (M4) scope: eleven keys — schema_version, default_country (M1),
 * trusted_proxies, trust_cloudflare, derived_cache_enabled, and
 * derived_cache_ttl (M2), `maxmind_db_path` (M3), plus (M4)
 * `remote_enabled`, `remote_account_id`, `remote_license_key`, and
 * `remote_transfer_acknowledged`.
 *
 * **The structural acknowledgement rule (M4 frozen decision):**
 * `sanitize()` forces `remote_enabled` to `false` unless
 * `remote_transfer_acknowledged` itself sanitizes to `true` — a pure
 * sanitization rule (a boolean AND against another field in the same input),
 * not I/O, not a merge against previously stored state. An admin cannot
 * enable the remote provider in the same request that first sets the
 * acknowledgement to false, and cannot enable it at all without the
 * acknowledgement present in that same submission.
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
	const SCHEMA_VERSION = 4;

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
	 * The default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'schema_version'               => self::SCHEMA_VERSION,
			'default_country'              => '',
			'trusted_proxies'              => array(),
			'trust_cloudflare'             => false,
			'derived_cache_enabled'        => true,
			'derived_cache_ttl'            => self::DEFAULT_CACHE_TTL,
			'maxmind_db_path'              => '',
			'remote_enabled'               => false,
			'remote_account_id'            => '',
			'remote_license_key'           => '',
			'remote_transfer_acknowledged' => false,
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
	 * @return array<string, mixed> The complete, normalized seven-key schema.
	 */
	public static function sanitize( $data ): array {
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$transfer_acknowledged = self::sanitize_bool( $data['remote_transfer_acknowledged'] ?? false );

		return array(
			'schema_version'               => self::SCHEMA_VERSION,
			'default_country'              => self::sanitize_country( $data['default_country'] ?? '' ),
			'trusted_proxies'              => self::sanitize_trusted_proxies( $data['trusted_proxies'] ?? array() ),
			'trust_cloudflare'             => self::sanitize_bool( $data['trust_cloudflare'] ?? false ),
			'derived_cache_enabled'        => self::sanitize_bool( $data['derived_cache_enabled'] ?? true ),
			'derived_cache_ttl'            => self::sanitize_ttl( $data['derived_cache_ttl'] ?? self::DEFAULT_CACHE_TTL ),
			'maxmind_db_path'              => self::sanitize_maxmind_db_path( $data['maxmind_db_path'] ?? '' ),
			// The structural acknowledgement rule (M4, frozen): remote_enabled
			// can never sanitize to true unless the acknowledgement does too —
			// a pure boolean AND, evaluated on this same input, never against
			// previously stored state.
			'remote_enabled'               => self::sanitize_bool( $data['remote_enabled'] ?? false ) && $transfer_acknowledged,
			'remote_account_id'            => self::sanitize_credential( $data['remote_account_id'] ?? '' ),
			'remote_license_key'           => self::sanitize_credential( $data['remote_license_key'] ?? '' ),
			'remote_transfer_acknowledged' => $transfer_acknowledged,
		);
	}

	/**
	 * Normalizes a country code: uppercase, empty allowed, malformed rejected.
	 *
	 * @param mixed $raw Raw country value.
	 *
	 * @return string Empty string, or an uppercase ISO 3166-1 alpha-2 shape.
	 */
	private static function sanitize_country( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$country = strtoupper( trim( $raw ) );

		if ( '' === $country ) {
			return '';
		}

		return 1 === preg_match( '~^[A-Z]{2}$~', $country ) ? $country : '';
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
	 * (`ProviderHealthStore`'s option, M3), and — as of M4 — the circuit
	 * breaker's `universal_geo_remote_circuit` option: `CircuitBreaker` is
	 * the sole runtime writer of that option, but its deletion is assigned to
	 * this class (the frozen M4 ownership split), the same "owns writing,
	 * doesn't own deleting" shape `ProviderHealthStore` already established.
	 * `GeoCache::uninstall()` and `AdminScreen::uninstall()` own the
	 * remaining M2/M3 gap this same invariant left open (cache salt/epoch,
	 * the first-run notice meta) — `uninstall.php` calls all three, closing
	 * the gap in full as of M4.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_option( self::OPTION_NAME );
		delete_option( 'universal_geo_provider_health' );
		delete_option( 'universal_geo_remote_circuit' );
	}
}
