<?php
/**
 * Post-resolution visitor-context transformation for country simulation.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Simulation;

use UniversalGeo\Model\VisitorContext;

/**
 * Applies an administrator's simulated country after real resolution completes.
 *
 * @internal
 * @final
 */
final class SimulationContextFilter {

	/**
	 * Source identifier for simulated contexts.
	 */
	public const SOURCE = 'simulation';

	/**
	 * Confidence for an explicit administrator override (not geographic evidence).
	 */
	public const CONFIDENCE = 1.0;

	/**
	 * Filter priority — late enough that core resolution is complete, early
	 * enough that downstream plugins still see the simulated value.
	 */
	public const FILTER_PRIORITY = 100;

	/**
	 * Stores the injected dependencies.
	 *
	 * @param SimulationState $state Active simulation state.
	 */
	public function __construct(
		private readonly SimulationState $state
	) {
	}

	/**
	 * Registers the universal_geo_context filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'universal_geo_context', array( $this, 'apply' ), self::FILTER_PRIORITY );
	}

	/**
	 * Replaces the country when an authorized simulation session is active.
	 *
	 * Semantics:
	 * - source: {@see self::SOURCE}
	 * - confidence: {@see self::CONFIDENCE} (explicit admin intent, not provider evidence)
	 * - region_code: null (never retain a real region incompatible with simulation)
	 * - is_cached: false (simulation is not a geo-cache artifact)
	 *
	 * @param mixed $context Real resolved context.
	 *
	 * @return mixed
	 */
	public function apply( $context ) {
		if ( ! $context instanceof VisitorContext ) {
			return $context;
		}

		$country = $this->state->active_country();

		if ( null === $country ) {
			return $context;
		}

		return new VisitorContext(
			$country,
			null,
			self::SOURCE,
			self::CONFIDENCE,
			false
		);
	}
}
