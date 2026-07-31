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
	 * @param DiagnosticsService  $diagnostics Full report supplier.
	 * @param ReportRenderer      $renderer    Definition-list renderer.
	 * @param AdminHeaderRenderer $header      Shared page header.
	 * @param AdminActionRenderer $actions     Shared action controls.
	 */
	public function __construct(
		private readonly DiagnosticsService $diagnostics,
		private readonly ReportRenderer $renderer,
		private readonly AdminHeaderRenderer $header,
		private readonly AdminActionRenderer $actions
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

		$report      = $this->diagnostics->report();
		$report_text = $this->build_copy_text( $report );

		echo '<div class="wrap">';
		$this->header->render(
			$this->slug(),
			$this->title(),
			function (): void {
				$this->actions->render_link_button(
					AdminPageRegistry::page_url( AdminPageSlugs::DIAGNOSTICS ),
					__( 'Refresh Diagnostics', 'universal-geo-context' )
				);
			}
		);

		echo '<details class="universal-geo-diagnostics-copy-wrap" style="max-width:960px;margin:0 0 1.5em;">';
		echo '<summary><strong>' . esc_html__( 'Copy report', 'universal-geo-context' ) . '</strong></summary>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Select the text below and copy it for support tickets. Values are already masked.', 'universal-geo-context' )
		);
		printf(
			'<textarea class="universal-geo-diagnostics-copy" readonly rows="12" aria-label="%s">%s</textarea>',
			esc_attr__( 'Diagnostics report (read-only)', 'universal-geo-context' ),
			esc_textarea( $report_text )
		);
		echo '</details>';

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
		if ( array() === $report['provider_health'] ) {
			echo '<div class="universal-geo-empty-state">';
			echo '<p>' . esc_html__( 'No provider failures have been recorded.', 'universal-geo-context' ) . '</p>';
			echo '<div class="universal-geo-action-buttons">';
			$this->actions->render_refresh_providers_form(
				AdminPageSlugs::DIAGNOSTICS,
				__( 'Refresh Providers', 'universal-geo-context' )
			);
			$this->actions->render_link_button(
				AdminPageRegistry::page_url( AdminPageSlugs::PROVIDERS ),
				__( 'Learn more', 'universal-geo-context' )
			);
			echo '</div></div>';
		} else {
			foreach ( $report['provider_health'] as $provider_id => $row ) {
				echo '<h3>' . esc_html( (string) $provider_id ) . '</h3>';
				$this->renderer->render_definition_list( $row );
			}
		}

		echo '<h2>' . esc_html__( 'Cache', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $report['cache'] );

		echo '<h2>' . esc_html__( 'Environment', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $report['environment'] );

		echo '</div>';
	}

	/**
	 * Builds a plain-text diagnostics summary for manual copy.
	 *
	 * @param array<string, mixed> $report Full diagnostics report.
	 *
	 * @return string
	 */
	private function build_copy_text( array $report ): string {
		$lines = array( 'Universal Geo Context — Diagnostics Report', '' );

		foreach ( $report as $section_key => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$lines[] = strtoupper( str_replace( '_', ' ', (string) $section_key ) );

			if ( isset( $section[0] ) && is_array( $section[0] ) ) {
				foreach ( $section as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$lines[] = $this->format_copy_row( $row );
				}
			} else {
				$lines[] = $this->format_copy_row( $section );
			}

			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Formats one associative row for plain-text export.
	 *
	 * @param array<string, mixed> $row Label/value pairs.
	 *
	 * @return string
	 */
	private function format_copy_row( array $row ): string {
		$parts = array();

		foreach ( $row as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$parts[] = sprintf( '%s: %s', (string) $key, (string) $value );
			}
		}

		return implode( ' | ', $parts );
	}
}
