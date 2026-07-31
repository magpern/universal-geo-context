<?php
/**
 * Renders Detection Inspector explanations in wp-admin.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Explanation\ExplanationFormatter;
use UniversalGeo\Explanation\ProviderExplanation;
use UniversalGeo\Explanation\ResolutionExplanation;

/**
 * Presentation-only renderer — no resolution or probe side effects.
 *
 * @internal
 * @final
 */
final class DetectionInspectorRenderer {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param ReportRenderer         $report_renderer Definition list renderer.
	 * @param ExplanationFormatter   $formatter       Status badge labels.
	 * @param DiagnosticsService     $diagnostics     Field labels.
	 * @param AdminComponentRenderer $components      Design-system components.
	 * @param TimelineRenderer       $timeline        Timeline component.
	 */
	public function __construct(
		private readonly ReportRenderer $report_renderer,
		private readonly ExplanationFormatter $formatter,
		private readonly DiagnosticsService $diagnostics,
		private readonly AdminComponentRenderer $components,
		private readonly TimelineRenderer $timeline
	) {
	}

	/**
	 * Renders the full Detection Inspector for the live tab.
	 *
	 * @param ResolutionExplanation $explanation Built explanation model.
	 *
	 * @return void
	 */
	public function render( ResolutionExplanation $explanation ): void {
		echo '<div class="ugc-detection-inspector">';

		$this->render_probe_notice( $explanation );
		$this->render_context_section( $explanation );
		$this->render_timeline_section( $explanation );
		$this->render_providers( $explanation );
		$this->render_cache( $explanation );
		$this->render_trusted_proxies( $explanation );
		$this->render_environment( $explanation );

		echo '</div>';
	}

	/**
	 * Renders the post-refresh probe summary notice.
	 *
	 * @param ResolutionExplanation $explanation Explanation model.
	 *
	 * @return void
	 */
	private function render_probe_notice( ResolutionExplanation $explanation ): void {
		if ( null === $explanation->probe_summary ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->info_panel(
			__( 'Last explicit refresh', 'universal-geo-context' ),
			sprintf(
				/* translators: 1: ok count, 2: total providers */
				__( '%1$d of %2$d providers returned a country.', 'universal-geo-context' ),
				(int) $explanation->probe_summary['ok_count'],
				(int) $explanation->probe_summary['total']
			)
		);
	}

	/**
	 * Renders effective and real context cards.
	 *
	 * @param ResolutionExplanation $explanation Explanation model.
	 *
	 * @return void
	 */
	private function render_context_section( ResolutionExplanation $explanation ): void {
		$sim_badge = $this->components->status_badge(
			$explanation->simulation_active ? __( 'Simulation active', 'universal-geo-context' ) : __( 'Live resolution', 'universal-geo-context' ),
			$explanation->simulation_active ? 'warning' : 'active'
		);

		$this->render_data_card(
			__( 'Current effective context', 'universal-geo-context' ),
			__( 'What downstream consumers see right now.', 'universal-geo-context' ),
			$sim_badge,
			array_merge(
				$this->context_values( $explanation->effective_context ),
				array( 'simulation_active' => $explanation->simulation_active ? 'yes' : 'no' )
			)
		);

		if ( $explanation->simulation_active ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
			echo $this->components->warning_panel(
				__( 'Simulation override', 'universal-geo-context' ),
				__( 'Simulation is active — providers did not return the simulated country below.', 'universal-geo-context' )
			);

			$this->render_data_card(
				__( 'Real context (before simulation)', 'universal-geo-context' ),
				__( 'Resolution path without the simulation override.', 'universal-geo-context' ),
				$this->components->status_badge( __( 'Real path', 'universal-geo-context' ), 'recommended' ),
				$this->context_values( $explanation->real_context )
			);
		}
	}

	/**
	 * Renders the resolution timeline card.
	 *
	 * @param ResolutionExplanation $explanation Explanation model.
	 *
	 * @return void
	 */
	private function render_timeline_section( ResolutionExplanation $explanation ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open(
			__( 'Resolution timeline', 'universal-geo-context' ),
			__( 'Ordered stages from client address to effective country.', 'universal-geo-context' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in timeline renderer.
		echo $this->timeline->render( $explanation->timeline );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();
	}

	/**
	 * Renders the provider results table.
	 *
	 * @param ResolutionExplanation $explanation Explanation model.
	 *
	 * @return void
	 */
	private function render_providers( ResolutionExplanation $explanation ): void {
		$description = $explanation->has_live_probe
			? __( 'Inferred from the current real resolution path immediately after explicit refresh (one live probe ran when you clicked Refresh).', 'universal-geo-context' )
			: __( 'Inferred from the current real resolution path. Run Refresh now for a full live probe of every provider.', 'universal-geo-context' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open(
			__( 'Provider results', 'universal-geo-context' ),
			$description
		);

		echo '<table class="ugc-ui-data-table"><thead><tr>';
		foreach ( array( 'Provider', 'Available', 'Attempted', 'Country', 'Confidence', 'Status', 'Notes' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $explanation->providers as $provider ) {
			if ( ! $provider instanceof ProviderExplanation ) {
				continue;
			}

			$status = $provider->is_winner
				? __( 'Winner', 'universal-geo-context' )
				: $this->formatter->skipped_reason_label( $provider->skipped_reason );

			if ( '' === $status && $provider->failure_reason ) {
				$status = (string) $provider->failure_reason;
			}

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $provider->provider_id ) );
			printf( '<td>%s</td>', esc_html( $provider->available ? 'yes' : 'no' ) );
			printf( '<td>%s</td>', esc_html( $provider->attempted ? 'yes' : 'no' ) );
			printf( '<td>%s</td>', esc_html( $provider->country_code ?? '—' ) );
			printf( '<td>%s</td>', esc_html( null !== $provider->confidence ? (string) $provider->confidence : '—' ) );
			printf( '<td>%s</td>', esc_html( $status ) );
			printf( '<td>%s</td>', esc_html( $provider->failure_reason ?? '' ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();
	}

	/**
	 * Renders the cache observability section.
	 *
	 * @param ResolutionExplanation $explanation Explanation model.
	 *
	 * @return void
	 */
	private function render_cache( ResolutionExplanation $explanation ): void {
		$this->render_data_card(
			__( 'Cache', 'universal-geo-context' ),
			__( 'Simulation never writes to the geo cache. Values below describe real resolution caching only.', 'universal-geo-context' ),
			$this->components->status_badge( __( 'Observability', 'universal-geo-context' ), 'recommended' ),
			$explanation->cache
		);
	}

	/**
	 * Renders trusted proxy and forwarding sections.
	 *
	 * @param ResolutionExplanation $explanation Explanation model.
	 *
	 * @return void
	 */
	private function render_trusted_proxies( ResolutionExplanation $explanation ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open(
			__( 'Trusted proxies', 'universal-geo-context' ),
			__( 'How the client address was derived from forwarding headers.', 'universal-geo-context' )
		);

		$this->report_renderer->render_definition_list( $explanation->trusted_proxies );

		echo $this->components->page_intro( __( 'Forwarding headers', 'universal-geo-context' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( $explanation->forwarding_headers as $row ) {
			if ( is_array( $row ) ) {
				$this->report_renderer->render_definition_list( $row );
			}
		}

		echo $this->components->page_intro( __( 'Cloudflare', 'universal-geo-context' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->report_renderer->render_definition_list( $explanation->cloudflare );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();
	}

	/**
	 * Renders the environment section.
	 *
	 * @param ResolutionExplanation $explanation Explanation model.
	 *
	 * @return void
	 */
	private function render_environment( ResolutionExplanation $explanation ): void {
		$health = $this->diagnostics->worst_site_health_status();
		$badge  = match ( $health ) {
			'critical'    => $this->components->status_badge( __( 'Critical', 'universal-geo-context' ), 'error' ),
			'recommended' => $this->components->status_badge( __( 'Needs attention', 'universal-geo-context' ), 'warning' ),
			default       => $this->components->status_badge( __( 'Good', 'universal-geo-context' ), 'active' ),
		};

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open(
			__( 'Environment', 'universal-geo-context' ),
			sprintf(
				/* translators: %s: site health status */
				__( 'Runtime integration status. Site Health: %s', 'universal-geo-context' ),
				$health
			),
			$badge
		);

		$this->report_renderer->render_definition_list( $explanation->environment );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();
	}

	/**
	 * Converts a VisitorContext to display values.
	 *
	 * @param \UniversalGeo\Model\VisitorContext $context Visitor context.
	 *
	 * @return array<string, mixed>
	 */
	private function context_values( $context ): array {
		return array(
			'country_code' => $context->country_code,
			'region_code'  => $context->region_code,
			'source'       => $context->source,
			'confidence'   => $context->confidence,
			'is_cached'    => $context->is_cached,
		);
	}

	/**
	 * Renders one settings card with definition list body.
	 *
	 * @param string               $title       Card title.
	 * @param string               $description Card description.
	 * @param string               $badge_html  Header badge markup.
	 * @param array<string, mixed> $values      Definition list values.
	 *
	 * @return void
	 */
	private function render_data_card( string $title, string $description, string $badge_html, array $values ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open( $title, $description, $badge_html );
		$this->report_renderer->render_definition_list( $values );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();
	}
}
