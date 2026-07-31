<?php
/**
 * Signed browser cookie for administrator country simulation.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Simulation;

use UniversalGeo\Resolver\GeoValidator;

/**
 * Stores only a validated ISO country code in a signed, HttpOnly cookie.
 *
 * @internal
 * @final
 */
final class SimulationCookie {

	/**
	 * Cookie name.
	 */
	public const NAME = 'universal_geo_sim';

	/**
	 * Payload format version.
	 */
	public const VERSION = 1;

	/**
	 * Reads and verifies the simulation cookie.
	 *
	 * @return string|null Normalized ISO alpha-2 country code, or null when absent/invalid.
	 */
	public function read(): ?string {
		if ( ! isset( $_COOKIE[ self::NAME ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified via signature below.
		$raw = (string) wp_unslash( $_COOKIE[ self::NAME ] );

		if ( '' === $raw ) {
			return null;
		}

		$parts = explode( '.', $raw, 3 );

		if ( 3 !== count( $parts ) ) {
			return null;
		}

		list( $version, $country, $signature ) = $parts;

		if ( (string) self::VERSION !== $version ) {
			return null;
		}

		$country = GeoValidator::country( $country );

		if ( null === $country ) {
			return null;
		}

		$expected = $this->sign( $version, $country );

		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		return $country;
	}

	/**
	 * Writes a signed simulation cookie for the given country.
	 *
	 * @param string $country Validated ISO alpha-2 country code.
	 *
	 * @return void
	 */
	public function write( string $country ): void {
		$country = GeoValidator::country( $country );

		if ( null === $country ) {
			return;
		}

		$version   = (string) self::VERSION;
		$signature = $this->sign( $version, $country );
		$value     = $version . '.' . $country . '.' . $signature;

		$this->set_cookie( $value );
	}

	/**
	 * Clears the simulation cookie.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->set_cookie( '', time() - YEAR_IN_SECONDS );
	}

	/**
	 * Signs the cookie payload using WordPress salts.
	 *
	 * @param string $version Payload version.
	 * @param string $country Normalized country code.
	 *
	 * @return string Hex signature (first 32 chars of HMAC-SHA256).
	 */
	private function sign( string $version, string $country ): string {
		$payload = $version . '|' . $country;

		return substr( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Sends the Set-Cookie header with secure defaults.
	 *
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry timestamp; 0 for session cookie.
	 *
	 * @return void
	 */
	private function set_cookie( string $value, int $expires = 0 ): void {
		if ( ! headers_sent() ) {
			$path   = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
			$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

			setcookie(
				self::NAME,
				$value,
				array(
					'expires'  => $expires,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		if ( '' === $value ) {
			unset( $_COOKIE[ self::NAME ] );
		} else {
			$_COOKIE[ self::NAME ] = $value;
		}
	}
}
