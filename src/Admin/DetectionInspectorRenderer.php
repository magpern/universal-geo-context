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
use UniversalGeo\Explanation\ResolutionStage;

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
	 * @param ReportRenderer       $report_renderer Definition list renderer.
	 * @param ExplanationFormatter $formatter       Status badge labels.
	 * @param DiagnosticsService   $diagnostics     Field labels.
	 */
	public function __construct(
		private readonly ReportRenderer $report_renderer,
		private readonly ExplanationFormatter $formatter,
		private readonly DiagnosticsService $diagnostics
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
		echo '<div class="universal-geo-detection-inspector">';

		$this->render_probe_notice( $explanation );
		$this->render_context_section( $explanation );
		$this->render_timeline( $explanation );
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

		printf(
			'<div class="notice notice-info inline"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: ok count, 2: total providers */
					__( 'Last explicit refresh: %1$d of %2$d providers returned a country.', 'universal-geo-context' ),
					(int) $explanation->probe_summary['ok_count'],
					(int) $explanation->probe_summary['total']
				)
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
		$this->render_card(
			__( 'Current effective context', 'universal-geo-context' ),
			function () use ( $explanation ): void {
				$this->report_renderer->render_definition_list(
					array_merge(
						$this->context_values( $explanation->effective_context ),
						array(
							'simulation_active' => $explanation->simulation_active ? 'yes' : 'no',
						)
					)
				);
			}
		);

		if ( $explanation->simulation_active ) {
			$this->render_card(
				__( 'Real context (before simulation)', 'universal-geo-context' ),
				function () use ( $explanation ): void {
					printf(
						'<p><em>%s</em></p>',
						esc_html__( 'Simulation is active — providers did not return the simulated country below.', 'universal-geo-context' )
					);
					$this->report_renderer->render_definition_list( $this->context_values( $explanation->real_context ) );
				}
			);
		}
	}

	/**
	 * Renders the resolution timeline.
	 *
	 * @param ResolutionExplanation $explanation Explanation model.
	 *
	 * @return void
	 */
	private function render_timeline( ResolutionExplanation $explanation ): void {
		$this->render_card(
			__( 'Resolution timeline', 'universal-geo-context' ),
			function () use ( $explanation ): void {
				echo '<ol class="universal-geo-timeline">';

				foreach ( $explanation->timeline as $stage ) {
					if ( ! $stage instanceof ResolutionStage ) {
						continue;
					}

					$class = $this->formatter->timeline_status_class( $stage->status );
					printf(
						'<li><span class="universal-geo-badge universal-geo-badge--%1$s">%2$s</span> <strong>%3$s</strong>',
						esc_attr( $class ),
						esc_html( $this->formatter->timeline_status_label( $stage->status ) ),
						esc_html( $stage->label )
					);

					if ( '' !== $stage->detail ) {
						printf( '<br /><span class="description">%s</span>', esc_html( $stage->detail ) );
					}

					echo '</li>';
				}

				echo '</ol>';
			}
		);
	}

	/**
	 * Renders the provider results table.
	 *
	 * @param ResolutionExplanation $explanation Explanation model.
	 *
	 * @return void
	 */
	private function render_providers( ResolutionExplanation $explanation ): void {
		$this->render_card(
			__( 'Provider results', 'universal-geo-context' ),
			function () use ( $explanation ): void {
				if ( $explanation->has_live_probe ) {
					echo '<p class="description">' . esc_html__( 'Inferred from the current real resolution path immediately after explicit refresh (one live probe ran when you clicked Refresh).', 'universal-geo-context' ) . '</p>';
				} else {
					echo '<p class="description">' . esc_html__( 'Inferred from the current real resolution path. Run Refresh now for a full live probe of every provider.', 'universal-geo-context' ) . '</p>';
				}

				echo '<table class="widefat striped"><thead><tr>';
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
			}
		);
	}

	/**
	 * Renders the cache observability section.
	 *
	 * @param ResolutionExplanation $explanation Explanation model.
	 *
	 * @return void
	 */
	private function render_cache( ResolutionExplanation $explanation ): void {
		$this->render_card(
			__( 'Cache', 'universal-geo-context' ),
			function () use ( $explanation ): void {
				echo '<p class="description">' . esc_html__( 'Simulation never writes to the geo cache. Values below describe real resolution caching only.', 'universal-geo-context' ) . '</p>';
				$this->report_renderer->render_definition_list( $explanation->cache );
			}
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
		$this->render_card(
			__( 'Trusted proxies', 'universal-geo-context' ),
			function () use ( $explanation ): void {
				$this->report_renderer->render_definition_list( $explanation->trusted_proxies );
				echo '<h3>' . esc_html__( 'Forwarding headers', 'universal-geo-context' ) . '</h3>';

				foreach ( $explanation->forwarding_headers as $row ) {
					if ( is_array( $row ) ) {
						$this->report_renderer->render_definition_list( $row );
					}
				}

				echo '<h3>' . esc_html__( 'Cloudflare', 'universal-geo-context' ) . '</h3>';
				$this->report_renderer->render_definition_list( $explanation->cloudflare );
			}
		);
	}

	/**
	 * Renders the environment section.
	 *
	 * @param ResolutionExplanation $explanation Explanation model.
	 *
	 * @return void
	 */
	private function render_environment( ResolutionExplanation $explanation ): void {
		$this->render_card(
			__( 'Environment', 'universal-geo-context' ),
			function () use ( $explanation ): void {
				$this->report_renderer->render_definition_list( $explanation->environment );
				printf(
					'<p><strong>%1$s</strong> %2$s</p>',
					esc_html__( 'Site Health:', 'universal-geo-context' ),
					esc_html( $this->diagnostics->worst_site_health_status() )
				);
			}
		);
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
	 * Renders one inspector card.
	 *
	 * @param string   $heading  Card title.
	 * @param callable $callback Body renderer.
	 *
	 * @return void
	 */
	private function render_card( string $heading, callable $callback ): void {
		echo '<div class="postbox universal-geo-inspector-card" style="max-width:960px;margin-top:1.5em;"><div class="postbox-header"><h2 class="hndle">';
		echo esc_html( $heading );
		echo '</h2></div><div class="inside">';
		$callback();
		echo '</div></div>';
	}
}
