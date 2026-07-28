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

/**
 * Settings owner and validator.
 *
 * `defaults()` and `sanitize()` are pure and WordPress-free (unit-testable
 * without a bootstrap). `install()` and `uninstall()` read/write the option
 * and are the only methods that touch WordPress. Sanitization is forgiving:
 * it never throws, cleaning or dropping invalid input so persistence always
 * succeeds.
 *
 * M1 Step 1A scope: two keys only. Every other configuration key documented
 * in the architecture plan (trusted proxies, Cloudflare, MaxMind, cache,
 * remote provider) is introduced in the milestone that implements it.
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
	const SCHEMA_VERSION = 1;

	/**
	 * The default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'schema_version'  => self::SCHEMA_VERSION,
			'default_country' => '',
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
	 * @return array<string, mixed> The complete, normalized two-key schema.
	 */
	public static function sanitize( $data ): array {
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		return array(
			'schema_version'  => self::SCHEMA_VERSION,
			'default_country' => self::sanitize_country( $data['default_country'] ?? '' ),
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
