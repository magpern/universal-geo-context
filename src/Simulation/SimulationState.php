<?php
/**
 * Active simulation state for the current request.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Simulation;

/**
 * Combines cookie verification with per-request authorization.
 *
 * @internal
 * @final
 */
final class SimulationState {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param SimulationCookie        $cookie Cookie reader/writer.
	 * @param SimulationAuthorization $auth   Capability gate.
	 */
	public function __construct(
		private readonly SimulationCookie $cookie,
		private readonly SimulationAuthorization $auth
	) {
	}

	/**
	 * Returns whether simulation is active for this authorized request.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return null !== $this->active_country();
	}

	/**
	 * Returns the active simulated country, or null when inactive/unauthorized.
	 *
	 * @return string|null Normalized ISO alpha-2 country code.
	 */
	public function active_country(): ?string {
		if ( ! $this->auth->is_authorized() ) {
			return null;
		}

		return $this->cookie->read();
	}
}
