<?php
/**
 * Country selector options for simulation.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Simulation;

use UniversalGeo\Resolver\GeoValidator;

/**
 * Policy-neutral ISO country catalogue for the simulation UI.
 *
 * @internal
 * @final
 */
final class CountryCatalog {

	/**
	 * Returns country options sorted by label for HTML selects.
	 *
	 * @return array<string, string> ISO alpha-2 code => translated label.
	 */
	public function options(): array {
		$labels = CountryNames::labels();
		$codes  = GeoValidator::country_codes();

		$options = array();

		foreach ( $codes as $code ) {
			$options[ $code ] = $labels[ $code ] ?? $code;
		}

		uasort(
			$options,
			static function ( string $a, string $b ): int {
				return strcasecmp( $a, $b );
			}
		);

		return $options;
	}

	/**
	 * Returns the display label for one country code.
	 *
	 * @param string $code ISO alpha-2 code.
	 *
	 * @return string
	 */
	public function label( string $code ): string {
		$normalized = GeoValidator::country( $code );

		if ( null === $normalized ) {
			return $code;
		}

		$labels = CountryNames::labels();

		return $labels[ $normalized ] ?? $normalized;
	}
}
