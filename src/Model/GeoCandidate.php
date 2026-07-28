<?php
/**
 * Immutable geographic facts reported by a single provider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Model;

/**
 * Raw geographic facts as reported by a single provider.
 *
 * Pure value object: no WordPress dependency, no provider identity, no
 * confidence, no policy. Providers return facts only — validating and
 * normalizing them (GeoValidator) is a resolver responsibility from a later
 * milestone, not this class's.
 *
 * @final
 */
final class GeoCandidate {

	/**
	 * Constructs a candidate from raw, unvalidated provider facts.
	 *
	 * @param string|null $country_code Country code as reported by the provider, unvalidated.
	 * @param string|null $region_code  Subdivision code as reported by the provider, unvalidated.
	 */
	public function __construct(
		public readonly ?string $country_code,
		public readonly ?string $region_code
	) {
	}
}
