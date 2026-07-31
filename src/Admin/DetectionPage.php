<?php
/**
 * Detection & Testing admin page (placeholder content in M7).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Tab shell stabilizing navigation until M8 (Simulation) and M9 (Live Detection).
 *
 * @internal
 * @final
 */
final class DetectionPage implements Page {

	/**
	 * Returns the page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return AdminPageSlugs::DETECTION;
	}

	/**
	 * Returns the page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Detection & Testing', 'universal-geo-context' );
	}

	/**
	 * Returns the submenu label.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Detection & Testing', 'universal-geo-context' );
	}

	/**
	 * Returns the required capability.
	 *
	 * @return string
	 */
	public function capability(): string {
		return 'manage_options';
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		$tab = $this->active_tab();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $this->title() ) . '</h1>';
		$this->render_tab_nav( $tab );

		if ( 'simulation' === $tab ) {
			$this->render_simulation_placeholder();
		} else {
			$this->render_live_detection_placeholder();
		}

		echo '</div>';
	}

	/**
	 * Returns the active tab from the query string.
	 *
	 * @return string
	 */
	private function active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'live';

		return 'simulation' === $tab ? 'simulation' : 'live';
	}

	/**
	 * Renders the Live Detection and Simulation tab navigation.
	 *
	 * @param string $active 'live' or 'simulation'.
	 *
	 * @return void
	 */
	private function render_tab_nav( string $active ): void {
		$base = admin_url( 'admin.php?page=' . $this->slug() );

		echo '<h2 class="nav-tab-wrapper">';
		printf(
			'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
			esc_url( $base ),
			esc_attr( 'live' === $active ? 'nav-tab-active' : '' ),
			esc_html__( 'Live Detection', 'universal-geo-context' )
		);
		printf(
			'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
			esc_url( add_query_arg( 'tab', 'simulation', $base ) ),
			esc_attr( 'simulation' === $active ? 'nav-tab-active' : '' ),
			esc_html__( 'Simulation', 'universal-geo-context' )
		);
		echo '</h2>';
	}

	/**
	 * Private function render live detection placeholder(.
	 *
	 * @return void
	 */
	private function render_live_detection_placeholder(): void {
		printf(
			'<div class="card"><p>%s</p></div>',
			esc_html__(
				'The Live Detection inspector is planned for v1.4.0. It will let you probe an arbitrary IP address through the provider chain without affecting real visitor resolution.',
				'universal-geo-context'
			)
		);
	}

	/**
	 * Private function render simulation placeholder(.
	 *
	 * @return void
	 */
	private function render_simulation_placeholder(): void {
		printf(
			'<div class="card"><p>%s</p></div>',
			esc_html__(
				'Country simulation for testing downstream plugins is planned for v1.3.0. It will let administrators verify consumer plugins against arbitrary countries without VPNs or proxy manipulation.',
				'universal-geo-context'
			)
		);
	}
}
