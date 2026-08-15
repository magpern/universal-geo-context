<?php
/**
 * Diagnostics admin page.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\OperationalStatus;
use UniversalGeo\Diagnostics\OperationalStatusService;

/**
 * Full diagnostics report with design-system presentation.
 *
 * @internal
 * @final
 */
final class DiagnosticsPage implements Page {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param DiagnosticsService       $diagnostics        Full report supplier.
	 * @param ReportRenderer           $renderer           Definition-list renderer.
	 * @param AdminHeaderRenderer      $header             Shared page header.
	 * @param AdminActionRenderer      $actions            Shared action controls.
	 * @param AdminComponentRenderer   $components         Design-system components.
	 * @param OperationalStatusService $operational_status Readiness evaluation (passive).
	 */
	public function __construct(
		private readonly DiagnosticsService $diagnostics,
		private readonly ReportRenderer $renderer,
		private readonly AdminHeaderRenderer $header,
		private readonly AdminActionRenderer $actions,
		private readonly AdminComponentRenderer $components,
		private readonly OperationalStatusService $operational_status
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
	 * Renders the page. Uses passive diagnostics (no provider probe on page load).
	 * Live provider probe results are available through the "Refresh Providers"
	 * POST action; see handle_refresh_providers() in OverviewPage for the
	 * canonical admin refresh mechanism.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		$report      = $this->diagnostics->report();
		$report_text = $this->build_copy_text( $report );
		$status      = $this->diagnostics->worst_site_health_status();
		$readiness   = $this->operational_status->evaluate();
		$shell       = $this->header->shell();

		$shell->open_wrap();
		$shell->open();
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

		$shell->open_content( true );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->readiness_summary_panel( $readiness, AdminPageRegistry::page_url( AdminPageSlugs::DIAGNOSTICS ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->health_summary_panel( $status, AdminPageRegistry::page_url( AdminPageSlugs::DIAGNOSTICS ) );

		$this->render_readiness_card( $readiness );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->copy_report_panel( 'ugc-diagnostics-copy-report', $report_text );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->feature_section_open( __( 'Network & client', 'universal-geo-context' ), __( 'Client address, trusted proxies, and forwarding headers.', 'universal-geo-context' ) );

		$this->render_section_card( __( 'Client address', 'universal-geo-context' ), $report['client_address'] );
		$this->render_section_card( __( 'Trusted proxies', 'universal-geo-context' ), $report['trusted_proxies'] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open( __( 'Forwarding headers', 'universal-geo-context' ), '' );
		foreach ( $report['forwarding_headers'] as $row ) {
			$this->renderer->render_definition_list( $row );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();

		$this->render_section_card( __( 'Cloudflare', 'universal-geo-context' ), $report['cloudflare'] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->feature_section_close();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->feature_section_open( __( 'Providers & data sources', 'universal-geo-context' ), __( 'MaxMind, remote provider, WooCommerce, and provider health.', 'universal-geo-context' ) );

		$this->render_section_card( __( 'WooCommerce', 'universal-geo-context' ), $report['woocommerce'] );
		$this->render_section_card( __( 'MaxMind', 'universal-geo-context' ), $report['maxmind'] );
		$this->render_section_card( __( 'Managed database', 'universal-geo-context' ), $report['maxmind_managed'] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open( __( 'Remote provider', 'universal-geo-context' ), '' );
		if ( $report['remote']['enabled'] ) {
			$remote_note = $this->components->info_panel(
				'',
				__( 'The remote provider is enabled. Click "Refresh Providers" above to probe all providers for live results, which may contact this remote service.', 'universal-geo-context' )
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
			echo $remote_note;
		}
		$this->renderer->render_definition_list( $report['remote'] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open( __( 'Providers', 'universal-geo-context' ), '' );
		foreach ( $report['providers'] as $row ) {
			$this->renderer->render_definition_list( $row );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();

		$this->render_provider_health_section( $report['provider_health'] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->feature_section_close();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->feature_section_open( __( 'Runtime', 'universal-geo-context' ), __( 'Cache and environment details.', 'universal-geo-context' ) );

		$this->render_section_card( __( 'Cache', 'universal-geo-context' ), $report['cache'] );
		$this->render_section_card( __( 'Simulation', 'universal-geo-context' ), $report['simulation'] );
		$this->render_section_card( __( 'Environment', 'universal-geo-context' ), $report['environment'] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->feature_section_close();

		$shell->close_content();
		$shell->close();
		$shell->close_wrap();
	}

	/**
	 * Renders the operational readiness summary card.
	 *
	 * @param OperationalStatus $readiness Readiness snapshot.
	 *
	 * @return void
	 */
	private function render_readiness_card( OperationalStatus $readiness ): void {
		$values = array(
			'state'             => $readiness->state,
			'consumer_usable'   => $readiness->consumer_usable,
			'simulation_active' => $readiness->simulation_active,
			'summary'           => $readiness->summary,
		);

		foreach ( $readiness->issues as $index => $issue ) {
			$values[ 'issue_' . $index . '_code' ]        = $issue->code;
			$values[ 'issue_' . $index . '_severity' ]    = $issue->severity;
			$values[ 'issue_' . $index . '_message' ]     = $issue->message;
			$values[ 'issue_' . $index . '_remediation' ] = $issue->remediation;
		}

		$this->render_section_card( __( 'Operational readiness', 'universal-geo-context' ), $values );
	}

	/**
	 * Renders one diagnostics settings card with a definition list body.
	 *
	 * @param string               $title  Card title.
	 * @param array<string, mixed> $values Definition list values.
	 *
	 * @return void
	 */
	private function render_section_card( string $title, array $values ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open( $title, '' );
		$this->renderer->render_definition_list( $values );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();
	}

	/**
	 * Renders the provider health section with empty state support.
	 *
	 * @param array<string, array<string, mixed>> $provider_health Provider health rows.
	 *
	 * @return void
	 */
	private function render_provider_health_section( array $provider_health ): void {
		$badge = $this->components->status_badge(
			array() === $provider_health ? __( 'No failures', 'universal-geo-context' ) : __( 'Issues recorded', 'universal-geo-context' ),
			array() === $provider_health ? 'active' : 'warning'
		);

		$card_open = $this->components->settings_card_open(
			__( 'Provider health', 'universal-geo-context' ),
			__( 'Recorded provider failures from recent resolution attempts.', 'universal-geo-context' ),
			$badge
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $card_open;

		if ( array() === $provider_health ) {
			ob_start();
			$this->actions->render_refresh_providers_form(
				AdminPageSlugs::DIAGNOSTICS,
				__( 'Refresh Providers', 'universal-geo-context' )
			);
			echo ' ';
			$this->actions->render_link_button(
				AdminPageRegistry::page_url( AdminPageSlugs::PROVIDERS ),
				__( 'Learn more', 'universal-geo-context' )
			);
			$actions = ob_get_clean();

			$empty_state = $this->components->empty_state(
				'dashicons-yes-alt',
				__( 'No provider failures recorded', 'universal-geo-context' ),
				__( 'Provider health summaries appear after you refresh diagnostics.', 'universal-geo-context' ),
				$actions
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
			echo $empty_state;
		} else {
			foreach ( $provider_health as $provider_id => $row ) {
				printf( '<h5 class="ugc-ui-provider-card__title">%s</h5>', esc_html( (string) $provider_id ) );
				$this->renderer->render_definition_list( $row );
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();
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
