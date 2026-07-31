<?php
/**
 * Admin bar indicator for active country simulation.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Simulation\CountryCatalog;
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationState;
use WP_Admin_Bar;

/**
 * Shows a visible badge when simulation is active for the current administrator.
 *
 * @internal
 * @final
 */
final class SimulationAdminBar {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param SimulationState $state   Active simulation state.
	 * @param CountryCatalog  $catalog Country labels.
	 */
	public function __construct(
		private readonly SimulationState $state,
		private readonly CountryCatalog $catalog
	) {
	}

	/**
	 * Registers the admin_bar_menu callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'render_node' ), 100 );
	}

	/**
	 * Adds the simulation indicator node when active.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar instance.
	 *
	 * @return void
	 */
	public function render_node( WP_Admin_Bar $admin_bar ): void {
		if ( ! is_user_logged_in() || ! current_user_can( SimulationAuthorization::CAPABILITY ) ) {
			return;
		}

		$country = $this->state->active_country();

		if ( null === $country ) {
			return;
		}

		$label = $this->catalog->label( $country );

		$admin_bar->add_node(
			array(
				'id'    => 'universal-geo-simulation',
				'title' => sprintf(
					/* translators: %s: simulated country label */
					__( 'Geo Simulation: %s', 'universal-geo-context' ),
					$label
				),
				'href'  => admin_url(
					'admin.php?page=' . AdminPageSlugs::DETECTION . '&tab=simulation'
				),
				'meta'  => array(
					'class' => 'universal-geo-simulation-active',
				),
			)
		);
	}
}
