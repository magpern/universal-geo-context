<?php
/**
 * Overview admin dashboard.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Plugin;
use UniversalGeo\Resolver\ContextResolver;

/**
 * Presentation-only dashboard over existing diagnostics and context services.
 *
 * @internal
 * @final
 */
final class OverviewPage implements Page {

	/**
	 * @param DiagnosticsService $diagnostics Supplies overview slices and Site Health verdicts.
	 * @param ContextResolver    $resolver    Used only for explicit Refresh now probe action.
	 * @param ReportRenderer     $renderer    Renders definition lists inside cards.
	 * @param AdminNotices       $notices     PRG redirects after refresh.
	 */
	public function __construct(
		private readonly DiagnosticsService $diagnostics,
		private readonly ContextResolver $resolver,
		private readonly ReportRenderer $renderer,
		private readonly AdminNotices $notices
	) {
	}

	/**
	 * Wires the explicit provider-refresh handler.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		add_action( 'admin_post_universal_geo_refresh_providers', array( $this, 'handle_refresh_providers' ) );
	}

	/**
	 * Returns the page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return AdminPageSlugs::OVERVIEW;
	}

	/**
	 * Returns the page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Universal Geo Context', 'universal-geo-context' );
	}

	/**
	 * Returns the submenu label.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Overview', 'universal-geo-context' );
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

		$sections = $this->diagnostics->overview_sections();
		$context  = Plugin::instance()->context();
		$probe    = $this->last_refresh_summary_from_request();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $this->title() ) . '</h1>';
		$this->render_health_badge( $this->diagnostics->worst_site_health_status() );

		echo '<div class="universal-geo-overview-grid">';

		$this->render_card(
			__( 'Current Resolution', 'universal-geo-context' ),
			function () use ( $context ): void {
				$this->renderer->render_definition_list(
					array(
						'country_code' => $context->country_code,
						'region_code'  => $context->region_code,
						'source'       => $context->source,
						'confidence'   => $context->confidence,
						'is_cached'    => $context->is_cached,
					)
				);
			}
		);

		$this->render_card(
			__( 'Providers', 'universal-geo-context' ),
			function () use ( $sections, $probe ): void {
				if ( null !== $probe ) {
					printf(
						'<p><strong>%1$s</strong> %2$s</p>',
						esc_html__( 'Last refresh:', 'universal-geo-context' ),
						esc_html(
							sprintf(
								/* translators: 1: number of providers that returned ok, 2: total providers probed */
								__( '%1$d of %2$d providers returned a country on the last explicit refresh.', 'universal-geo-context' ),
								(int) ( $probe['ok_count'] ?? 0 ),
								(int) ( $probe['total'] ?? 0 )
							)
						)
					);
				} else {
					echo '<p>' . esc_html__( 'No explicit refresh has been run yet. Last-known failure records appear below.', 'universal-geo-context' ) . '</p>';
				}

				if ( array() === $sections['provider_health'] ) {
					echo '<p>' . esc_html__( 'No provider failure records on file.', 'universal-geo-context' ) . '</p>';
					return;
				}

				foreach ( $sections['provider_health'] as $provider_id => $row ) {
					echo '<h3>' . esc_html( (string) $provider_id ) . '</h3>';
					$this->renderer->render_definition_list( $row );
				}

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em">';
				wp_nonce_field( 'universal_geo_refresh_providers' );
				echo '<input type="hidden" name="action" value="universal_geo_refresh_providers" />';
				submit_button( __( 'Refresh now', 'universal-geo-context' ), 'secondary', 'submit', false );
				echo '</form>';
			}
		);

		$this->render_card(
			__( 'Remote Provider', 'universal-geo-context' ),
			function () use ( $sections ): void {
				$this->renderer->render_definition_list( $sections['remote'] );
			}
		);

		$this->render_card(
			__( 'Trusted Proxies', 'universal-geo-context' ),
			function () use ( $sections ): void {
				$this->renderer->render_definition_list( $sections['trusted_proxies'] );
			}
		);

		$this->render_card(
			__( 'Cache', 'universal-geo-context' ),
			function () use ( $sections ): void {
				$this->renderer->render_definition_list( $sections['cache'] );
			}
		);

		$this->render_card(
			__( 'Environment', 'universal-geo-context' ),
			function () use ( $sections ): void {
				$this->renderer->render_definition_list( $sections['environment'] );
			}
		);

		echo '</div></div>';
	}

	/**
	 * Explicit Refresh now: runs one probe and stores a presentation summary.
	 *
	 * @return void
	 */
	public function handle_refresh_providers(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_refresh_providers' );

		$rows     = $this->resolver->probe();
		$ok_count = 0;

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ( $row['reason'] ?? '' ) === 'ok' ) {
				++$ok_count;
			}
		}

		$url = add_query_arg(
			array(
				'page'                      => $this->slug(),
				'universal_geo_msg'         => 'providers_refreshed',
				'universal_geo_typ'         => 'success',
				'universal_geo_probe_ok'    => $ok_count,
				'universal_geo_probe_total' => count( $rows ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Reads one-shot probe summary query args from an explicit refresh redirect.
	 *
	 * @return array{ok_count: int, total: int}|null
	 */
	private function last_refresh_summary_from_request(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG args from this handler.
		if ( ! isset( $_GET['universal_geo_probe_ok'], $_GET['universal_geo_probe_total'] ) ) {
			return null;
		}

		return array(
			'ok_count' => max( 0, (int) wp_unslash( $_GET['universal_geo_probe_ok'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'total'    => max( 0, (int) wp_unslash( $_GET['universal_geo_probe_total'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);
	}

	/**
	 * Renders one overview dashboard card.
	 *
	 * @param string   $heading  Card title.
	 * @param callable $callback Renders card body.
	 *
	 * @return void
	 */
	private function render_card( string $heading, callable $callback ): void {
		echo '<div class="postbox universal-geo-overview-card"><div class="postbox-header"><h2 class="hndle">';
		echo esc_html( $heading );
		echo '</h2></div><div class="inside">';
		$callback();
		echo '</div></div>';
	}

	/**
	 * Renders the overall health badge from a Site Health status.
	 *
	 * @param string $status One of critical, recommended, good.
	 *
	 * @return void
	 */
	private function render_health_badge( string $status ): void {
		$classes = array(
			'critical'    => 'notice-error',
			'recommended' => 'notice-warning',
			'good'        => 'notice-success',
		);

		$labels = array(
			'critical'    => __( 'Overall health: critical issues detected', 'universal-geo-context' ),
			'recommended' => __( 'Overall health: recommended improvements available', 'universal-geo-context' ),
			'good'        => __( 'Overall health: good', 'universal-geo-context' ),
		);

		$class = $classes[ $status ] ?? 'notice-info';
		$label = $labels[ $status ] ?? $labels['good'];

		printf(
			'<div class="notice %1$s inline"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $label )
		);
	}
}
