<?php
/**
 * Admin POST handlers for country simulation.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Simulation;

use UniversalGeo\Admin\AdminNotices;
use UniversalGeo\Admin\AdminPageSlugs;
use UniversalGeo\Resolver\GeoValidator;

/**
 * Start, change, and stop simulation via nonce-protected POST actions.
 *
 * @internal
 * @final
 */
final class SimulationController {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param SimulationCookie $cookie  Signed cookie writer.
	 * @param SimulationState  $state   Active simulation reader.
	 * @param AdminNotices     $notices PRG redirects.
	 */
	public function __construct(
		private readonly SimulationCookie $cookie,
		private readonly SimulationState $state,
		private readonly AdminNotices $notices
	) {
	}

	/**
	 * Registers admin_post handlers.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		add_action( 'admin_post_universal_geo_simulation_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_universal_geo_simulation_change', array( $this, 'handle_change' ) );
		add_action( 'admin_post_universal_geo_simulation_stop', array( $this, 'handle_stop' ) );
	}

	/**
	 * Starts simulation for the selected country.
	 *
	 * @return void
	 */
	public function handle_start(): void {
		$this->handle_set( 'simulation_started' );
	}

	/**
	 * Changes the simulated country.
	 *
	 * @return void
	 */
	public function handle_change(): void {
		$this->handle_set( 'simulation_changed' );
	}

	/**
	 * Stops simulation and clears the cookie.
	 *
	 * @return void
	 */
	public function handle_stop(): void {
		if ( ! current_user_can( SimulationAuthorization::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_simulation_stop' );

		$this->cookie->clear();

		$this->redirect_with_notice( 'simulation_stopped', 'success' );
	}

	/**
	 * Shared start/change handler.
	 *
	 * @param string $success_key Notice key on success.
	 *
	 * @return void
	 */
	private function handle_set( string $success_key ): void {
		if ( ! current_user_can( SimulationAuthorization::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_simulation' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated via GeoValidator below.
		$raw = isset( $_POST['simulation_country'] ) ? wp_unslash( $_POST['simulation_country'] ) : '';

		$country = GeoValidator::country( is_string( $raw ) ? sanitize_text_field( $raw ) : '' );

		if ( null === $country ) {
			$this->redirect_with_notice( 'simulation_invalid_country', 'warning' );
		}

		$this->cookie->write( $country );

		$this->redirect_with_notice( $success_key, 'success' );
	}

	/**
	 * Redirects to the Simulation tab with a notice.
	 *
	 * @param string $message_key Notice key.
	 * @param string $type        Notice type.
	 *
	 * @return void
	 */
	private function redirect_with_notice( string $message_key, string $type ): void {
		$url = add_query_arg(
			array(
				'page'              => AdminPageSlugs::DETECTION,
				'tab'               => 'simulation',
				'universal_geo_msg' => $message_key,
				'universal_geo_typ' => $type,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
