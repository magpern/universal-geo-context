<?php
/**
 * Observational provider row for the Detection Inspector.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Explanation;

/**
 * One provider's diagnostic summary — built from probe data or inference.
 *
 * @internal
 * @final
 */
final class ProviderExplanation {

	/**
	 * Stores one provider explanation row.
	 *
	 * @param string      $provider_id     Provider identifier.
	 * @param bool        $available       Whether is_available() is true.
	 * @param bool        $attempted       Whether resolve() ran for this request path.
	 * @param string|null $country_code    Country returned or null.
	 * @param string|null $failure_reason  Probe reason or health-store message.
	 * @param float|null  $confidence      Assigned confidence when this provider won.
	 * @param bool        $is_winner       Whether this provider produced the real context.
	 * @param string      $skipped_reason  Why the provider was skipped or not attempted.
	 * @param int|null    $response_time_ms Always null today — reserved for future timing.
	 */
	public function __construct(
		public readonly string $provider_id,
		public readonly bool $available,
		public readonly bool $attempted,
		public readonly ?string $country_code,
		public readonly ?string $failure_reason,
		public readonly ?float $confidence,
		public readonly bool $is_winner,
		public readonly string $skipped_reason,
		public readonly ?int $response_time_ms = null
	) {
	}
}
