<?php
/**
 * Diagnostics admin page.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Diagnostics\DiagnosticsService;

/**
 * Full diagnostics report (unchanged M6 content scope, new navigation).
 *
 * @internal
 * @final
 */
final class DiagnosticsPage implements Page {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param DiagnosticsService $diagnostics Full report supplier.
	 * @param ReportRenderer     $renderer    Definition-list renderer.
	 */
	public function __construct(
		private readonly DiagnosticsService $diagnostics,
		private readonly ReportRenderer $renderer
	) {
	}

	/**
	 * Returns the page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return AdminPageSlugs::DIAGNOSTICS;
	}

	/**
	 * Returns the page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Diagnostics', 'universal-geo-context' );
	}

	/**
	 * Returns the submenu label.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Diagnostics', 'universal-geo-context' );
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

		$report = $this->diagnostics->report();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $this->title() ) . '</h1>';

		echo '<h2>' . esc_html__( 'Client address', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $report['client_address'] );

		echo '<h2>' . esc_html__( 'Trusted proxies', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $report['trusted_proxies'] );

		echo '<h2>' . esc_html__( 'Forwarding headers', 'universal-geo-context' ) . '</h2>';
		foreach ( $report['forwarding_headers'] as $row ) {
			$this->renderer->render_definition_list( $row );
		}

		echo '<h2>' . esc_html__( 'Cloudflare', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $report['cloudflare'] );

		echo '<h2>' . esc_html__( 'WooCommerce', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $report['woocommerce'] );

		echo '<h2>' . esc_html__( 'MaxMind', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $report['maxmind'] );

		echo '<h2>' . esc_html__( 'Managed database', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $report['maxmind_managed'] );

		echo '<h2>' . esc_html__( 'Remote provider', 'universal-geo-context' ) . '</h2>';
		if ( $report['remote']['enabled'] ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__(
					'The remote provider is enabled — viewing this page performs one live request to the configured remote service, as part of the provider probe table below.',
					'universal-geo-context'
				)
			);
		}
		$this->renderer->render_definition_list( $report['remote'] );

		echo '<h2>' . esc_html__( 'Providers', 'universal-geo-context' ) . '</h2>';
		foreach ( $report['providers'] as $row ) {
			$this->renderer->render_definition_list( $row );
		}

		echo '<h2>' . esc_html__( 'Provider health', 'universal-geo-context' ) . '</h2>';
		foreach ( $report['provider_health'] as $provider_id => $row ) {
			echo '<h3>' . esc_html( (string) $provider_id ) . '</h3>';
			$this->renderer->render_definition_list( $row );
		}

		echo '<h2>' . esc_html__( 'Cache', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $report['cache'] );

		echo '<h2>' . esc_html__( 'Environment', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $report['environment'] );

		echo '</div>';
	}
}
