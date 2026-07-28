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
 * M2 sub-step 2C scope: six keys — schema_version, default_country (M1),
 * plus trusted_proxies, trust_cloudflare, derived_cache_enabled, and
 * derived_cache_ttl (Revision 3 §11's "only two knobs exposed" for caching,
 * plus the two trust-boundary settings). `maxmind_db_path` and `remote`
 * remain M3/M4.
 *
 * **Purity boundary** (Revision 3 §11, audit finding F6): sanitize()
 * performs *syntactic* checks only — CIDR shape, boolean cast, TTL range —
 * never filesystem I/O. That rule has no bearing yet (no path-shaped
 * setting exists until `maxmind_db_path` in M3), but is stated here so the
 * boundary is established before the setting that could tempt violating it
 * arrives.
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
	const SCHEMA_VERSION = 2;

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
			'schema_version'        => self::SCHEMA_VERSION,
			'default_country'       => '',
			'trusted_proxies'       => array(),
			'trust_cloudflare'      => false,
			'derived_cache_enabled' => true,
			'derived_cache_ttl'     => self::DEFAULT_CACHE_TTL,
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
	 * @return array<string, mixed> The complete, normalized six-key schema.
	 */
	public static function sanitize( $data ): array {
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		return array(
			'schema_version'        => self::SCHEMA_VERSION,
			'default_country'       => self::sanitize_country( $data['default_country'] ?? '' ),
			'trusted_proxies'       => self::sanitize_trusted_proxies( $data['trusted_proxies'] ?? array() ),
			'trust_cloudflare'      => self::sanitize_bool( $data['trust_cloudflare'] ?? false ),
			'derived_cache_enabled' => self::sanitize_bool( $data['derived_cache_enabled'] ?? true ),
			'derived_cache_ttl'     => self::sanitize_ttl( $data['derived_cache_ttl'] ?? self::DEFAULT_CACHE_TTL ),
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
	 * Deletes the single option this plugin owns.
	 *
	 * M1 Step 1A owns exactly one persisted resource. No other option,
	 * table, or metadata exists yet, so nothing else is deleted here.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_option( self::OPTION_NAME );
	}
}
