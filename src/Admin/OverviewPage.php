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
use UniversalGeo\Simulation\SimulationState;

/**
 * Presentation-only dashboard over existing diagnostics and context services.
 *
 * @internal
 * @final
 */
final class OverviewPage implements Page {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param DiagnosticsService       $diagnostics   Supplies overview slices and Site Health verdicts.
	 * @param ContextResolver          $resolver      Used only for explicit Refresh now probe action.
	 * @param ReportRenderer           $renderer      Renders definition lists inside cards.
	 * @param AdminNotices             $notices       PRG redirects after refresh.
	 * @param AdminHeaderRenderer      $header        Shared page header.
	 * @param QuickActionsRenderer     $quick_actions Quick navigation card.
	 * @param AdminActionRenderer      $actions       Shared action controls.
	 * @param AdminComponentRenderer   $components    Design-system components.
	 * @param SimulationState          $simulation    Active simulation state.
	 */
	public function __construct(
		private readonly DiagnosticsService $diagnostics,
		private readonly ContextResolver $resolver,
		private readonly ReportRenderer $renderer,
		private readonly AdminNotices $notices,
		private readonly AdminHeaderRenderer $header,
		private readonly QuickActionsRenderer $quick_actions,
		private readonly AdminActionRenderer $actions,
		private readonly AdminComponentRenderer $components,
		private readonly SimulationState $simulation
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
		return __( 'Overview', 'universal-geo-context' );
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
		$status   = $this->diagnostics->worst_site_health_status();
		$shell    = $this->header->shell();

		$shell->open_wrap();
		$shell->open();
		$this->header->render(
			$this->slug(),
			$this->title(),
			function (): void {
				$this->actions->render_refresh_providers_form(
					$this->slug(),
					__( 'Refresh Providers', 'universal-geo-context' )
				);
				$this->actions->render_link_button(
					AdminPageRegistry::page_url( AdminPageSlugs::SETTINGS ),
					__( 'Open Settings', 'universal-geo-context' )
				);
			}
		);

		$shell->open_content( true );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->health_summary_panel( $status, AdminPageRegistry::page_url( AdminPageSlugs::DIAGNOSTICS ) );

		$this->render_statistics_grid( $context, $sections, $status, $probe );

		$this->quick_actions->render();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->feature_section_open(
			__( 'Operational', 'universal-geo-context' ),
			__( 'Current resolution path and provider status.', 'universal-geo-context' )
		);

		$this->render_operational_cards( $context, $sections, $probe );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->feature_section_close();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->feature_section_open(
			__( 'Environment', 'universal-geo-context' ),
			__( 'Runtime configuration and cache behaviour.', 'universal-geo-context' )
		);

		$this->render_environment_cards( $sections );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->feature_section_close();

		$shell->close_content();
		$shell->close();
		$shell->close_wrap();
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

		$redirect_page = AdminPageSlugs::OVERVIEW;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above; optional redirect target.
		if ( isset( $_POST['universal_geo_redirect_page'] ) ) {
			$candidate = sanitize_key( wp_unslash( $_POST['universal_geo_redirect_page'] ) );
			if ( in_array( $candidate, array( AdminPageSlugs::OVERVIEW, AdminPageSlugs::DETECTION, AdminPageSlugs::PROVIDERS, AdminPageSlugs::DIAGNOSTICS ), true ) ) {
				$redirect_page = $candidate;
			}
		}

		$url = add_query_arg(
			array(
				'page'                      => $redirect_page,
				'universal_geo_msg'         => 'providers_refreshed',
				'universal_geo_typ'         => 'success',
				'universal_geo_probe_ok'    => $ok_count,
				'universal_geo_probe_total' => count( $rows ),
				'universal_geo_probe_fresh' => 1,
			),
			admin_url( 'admin.php' )
		);

		if ( AdminPageSlugs::DETECTION === $redirect_page ) {
			$url = add_query_arg( 'tab', 'live', $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Reads one-shot probe summary query args from an explicit refresh redirect.
	 *
	 * @return array{ok_count: int, total: int}|null
	 */
	private function last_refresh_summary_from_request(): ?array {
		return AdminProbeFreshFlag::summary();
	}

	/**
	 * Renders the dashboard statistics grid.
	 *
	 * @param \UniversalGeo\Model\VisitorContext $context  Effective visitor context.
	 * @param array<string, mixed>               $sections Overview diagnostic sections.
	 * @param string                             $status   Site health status.
	 * @param array{ok_count: int, total: int}|null $probe Last refresh summary.
	 *
	 * @return void
	 */
	private function render_statistics_grid( $context, array $sections, string $status, ?array $probe ): void {
		$country  = $context->country_code ?? __( 'Unknown', 'universal-geo-context' );
		$source   = (string) $context->source;
		$health   = match ( $status ) {
			'critical'    => __( 'Critical', 'universal-geo-context' ),
			'recommended' => __( 'Needs attention', 'universal-geo-context' ),
			default       => __( 'Good', 'universal-geo-context' ),
		};
		$cache_on = ! empty( $sections['cache']['enabled'] ) ? __( 'Enabled', 'universal-geo-context' ) : __( 'Disabled', 'universal-geo-context' );
		$sim      = $this->simulation->is_active()
			? (string) ( $this->simulation->active_country() ?? __( 'Active', 'universal-geo-context' ) )
			: __( 'Inactive', 'universal-geo-context' );

		$provider_hint = null !== $probe
			? sprintf(
				/* translators: 1: ok count, 2: total providers */
				__( '%1$d of %2$d OK on last refresh', 'universal-geo-context' ),
				(int) $probe['ok_count'],
				(int) $probe['total']
			)
			: __( 'Run refresh for live probe', 'universal-geo-context' );

		$provider_value = array() === $sections['provider_health']
			? __( 'No failures', 'universal-geo-context' )
			: sprintf(
				/* translators: %d: number of providers with recorded failures */
				_n( '%d issue', '%d issues', count( $sections['provider_health'] ), 'universal-geo-context' ),
				count( $sections['provider_health'] )
			);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->statistics_grid_open();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->statistics_card( __( 'Effective Country', 'universal-geo-context' ), (string) $country, $source );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->statistics_card( __( 'Resolution Source', 'universal-geo-context' ), $source, $context->is_cached ? __( 'Cached', 'universal-geo-context' ) : __( 'Live', 'universal-geo-context' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->statistics_card( __( 'Provider Health', 'universal-geo-context' ), $provider_value, $provider_hint );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->statistics_card( __( 'Cache', 'universal-geo-context' ), $cache_on, (string) ( $sections['cache']['ttl'] ?? '' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->statistics_card( __( 'Simulation', 'universal-geo-context' ), $sim, __( 'Session override', 'universal-geo-context' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->statistics_card( __( 'Overall Health', 'universal-geo-context' ), $health, __( 'Site Health summary', 'universal-geo-context' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->statistics_grid_close();
	}

	/**
	 * Renders operational overview cards.
	 *
	 * @param \UniversalGeo\Model\VisitorContext $context Effective visitor context.
	 * @param array<string, mixed>             $sections Overview sections.
	 * @param array{ok_count: int, total: int}|null $probe Last refresh summary.
	 *
	 * @return void
	 */
	private function render_operational_cards( $context, array $sections, ?array $probe ): void {
		$this->render_data_card(
			__( 'Current Resolution', 'universal-geo-context' ),
			__( 'Effective visitor context right now.', 'universal-geo-context' ),
			'active',
			__( 'Live', 'universal-geo-context' ),
			array(
				'country_code' => $context->country_code,
				'region_code'  => $context->region_code,
				'source'       => $context->source,
				'confidence'   => $context->confidence,
				'is_cached'    => $context->is_cached,
			),
			AdminPageRegistry::page_url( AdminPageSlugs::DETECTION ),
			__( 'Open Detection & Testing', 'universal-geo-context' )
		);

		ob_start();
		if ( null !== $probe ) {
			printf(
				'<p class="ugc-ui-settings-card__note">%1$s %2$s</p>',
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
		}

		if ( array() === $sections['provider_health'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
			echo $this->components->empty_state(
				'dashicons-yes-alt',
				__( 'No provider failures recorded', 'universal-geo-context' ),
				__( 'Provider health summaries appear after you refresh diagnostics.', 'universal-geo-context' )
			);
		} else {
			foreach ( $sections['provider_health'] as $provider_id => $row ) {
				printf( '<h5 class="ugc-ui-provider-card__title">%s</h5>', esc_html( (string) $provider_id ) );
				$this->renderer->render_definition_list( $row );
			}
		}
		$providers_body = ob_get_clean();

		$badge_variant = array() === $sections['provider_health'] ? 'active' : 'warning';
		$badge_label   = array() === $sections['provider_health']
			? __( 'Healthy', 'universal-geo-context' )
			: __( 'Issues recorded', 'universal-geo-context' );

		$this->render_html_card(
			__( 'Providers', 'universal-geo-context' ),
			__( 'Recorded provider health and last refresh summary.', 'universal-geo-context' ),
			$badge_variant,
			$badge_label,
			$providers_body,
			AdminPageRegistry::page_url( AdminPageSlugs::PROVIDERS ),
			__( 'Open Providers', 'universal-geo-context' )
		);

		$this->render_data_card(
			__( 'Remote Provider', 'universal-geo-context' ),
			__( 'MaxMind GeoLite2 Country Web Service configuration.', 'universal-geo-context' ),
			! empty( $sections['remote']['enabled'] ) ? 'active' : 'disabled',
			! empty( $sections['remote']['enabled'] ) ? __( 'Enabled', 'universal-geo-context' ) : __( 'Disabled', 'universal-geo-context' ),
			$sections['remote'],
			AdminPageRegistry::page_url( AdminPageSlugs::SETTINGS ) . '#universal-geo-remote-provider',
			__( 'Open Settings', 'universal-geo-context' )
		);

		$this->render_data_card(
			__( 'Trusted Proxies', 'universal-geo-context' ),
			__( 'How forwarded client addresses are trusted.', 'universal-geo-context' ),
			! empty( $sections['trusted_proxies']['peer_trusted'] ) ? 'active' : 'warning',
			! empty( $sections['trusted_proxies']['peer_trusted'] ) ? __( 'Peer trusted', 'universal-geo-context' ) : __( 'Peer not trusted', 'universal-geo-context' ),
			$sections['trusted_proxies'],
			AdminPageRegistry::page_url( AdminPageSlugs::TRUSTED_PROXIES ),
			__( 'Open Trusted Proxies', 'universal-geo-context' )
		);
	}

	/**
	 * Renders environment overview cards.
	 *
	 * @param array<string, mixed> $sections Overview sections.
	 *
	 * @return void
	 */
	private function render_environment_cards( array $sections ): void {
		$this->render_data_card(
			__( 'Cache', 'universal-geo-context' ),
			__( 'Derived context caching configuration.', 'universal-geo-context' ),
			! empty( $sections['cache']['enabled'] ) ? 'active' : 'disabled',
			! empty( $sections['cache']['enabled'] ) ? __( 'Enabled', 'universal-geo-context' ) : __( 'Disabled', 'universal-geo-context' ),
			$sections['cache'],
			AdminPageRegistry::page_url( AdminPageSlugs::SETTINGS ),
			__( 'Open Settings', 'universal-geo-context' )
		);

		$this->render_data_card(
			__( 'Environment', 'universal-geo-context' ),
			__( 'Runtime and integration status.', 'universal-geo-context' ),
			'available',
			__( 'Runtime', 'universal-geo-context' ),
			$sections['environment'],
			AdminPageRegistry::page_url( AdminPageSlugs::DIAGNOSTICS ),
			__( 'Open Diagnostics', 'universal-geo-context' )
		);
	}

	/**
	 * Renders one settings card from associative data.
	 *
	 * @param string               $title        Card title.
	 * @param string               $description  Card description.
	 * @param string               $badge_variant Badge variant.
	 * @param string               $badge_label  Badge label.
	 * @param array<string, mixed> $values       Definition list values.
	 * @param string               $footer_url   Footer link URL.
	 * @param string               $footer_label Footer link label.
	 *
	 * @return void
	 */
	private function render_data_card(
		string $title,
		string $description,
		string $badge_variant,
		string $badge_label,
		array $values,
		string $footer_url,
		string $footer_label
	): void {
		ob_start();
		$this->renderer->render_definition_list( $values );
		$this->render_html_card( $title, $description, $badge_variant, $badge_label, ob_get_clean(), $footer_url, $footer_label );
	}

	/**
	 * Renders one settings card from pre-built body HTML.
	 *
	 * @param string $title         Card title.
	 * @param string $description   Card description.
	 * @param string $badge_variant Badge variant.
	 * @param string $badge_label   Badge label.
	 * @param string $body_html     Card body markup.
	 * @param string $footer_url    Footer link URL.
	 * @param string $footer_label  Footer link label.
	 *
	 * @return void
	 */
	private function render_html_card(
		string $title,
		string $description,
		string $badge_variant,
		string $badge_label,
		string $body_html,
		string $footer_url,
		string $footer_label
	): void {
		$badge = $this->components->status_badge( $badge_label, $badge_variant );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open( $title, $description, $badge );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Body built from escaped fragments.
		echo $body_html;

		ob_start();
		$this->actions->render_link_button( $footer_url, $footer_label );
		$footer = ob_get_clean();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_footer( $footer );
	}
}
