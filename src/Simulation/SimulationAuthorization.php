<?php
/**
 * Capability gate for country simulation.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Simulation;

/**
 * Determines whether the current request may activate or consume simulation.
 *
 * @internal
 * @final
 */
final class SimulationAuthorization {

	/**
	 * Capability required to start, change, or consume simulation.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Returns whether the current user may use simulation for this request.
	 *
	 * @return bool
	 */
	public function is_authorized(): bool {
		return is_user_logged_in() && current_user_can( self::CAPABILITY );
	}
}
