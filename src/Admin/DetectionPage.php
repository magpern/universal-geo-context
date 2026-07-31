<?php
/**
 * Detection & Testing admin page.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Explanation\DetectionInspectorService;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Plugin;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Simulation\CountryCatalog;
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationController;
use UniversalGeo\Simulation\SimulationState;

/**
 * Detection Inspector (M9) and country simulation controls (M8).
 *
 * @internal
 * @final
 */
final class DetectionPage implements Page {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param ContextResolver            $resolver   Supplies the real resolved context.
	 * @param SimulationState            $state      Active simulation state.
	 * @param CountryCatalog             $catalog    Country selector options.
	 * @param SimulationController       $controller POST handlers for simulation.
	 * @param DetectionInspectorService  $inspector  Explanation builder.
	 * @param DetectionInspectorRenderer $renderer   Inspector UI renderer.
	 * @param AdminHeaderRenderer        $header     Shared page header.
	 * @param AdminActionRenderer        $actions    Shared action controls.
	 * @param AdminComponentRenderer     $components Design-system components.
	 * @param ReportRenderer             $report     Definition list renderer.
	 */
	public function __construct(
		private readonly ContextResolver $resolver,
		private readonly SimulationState $state,
		private readonly CountryCatalog $catalog,
		private readonly SimulationController $controller,
		private readonly DetectionInspectorService $inspector,
		private readonly DetectionInspectorRenderer $renderer,
		private readonly AdminHeaderRenderer $header,
		private readonly AdminActionRenderer $actions,
		private readonly AdminComponentRenderer $components,
		private readonly ReportRenderer $report
	) {
	}

	/**
	 * Registers simulation admin_post handlers.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		$this->controller->register_handlers();
	}

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
		return SimulationAuthorization::CAPABILITY;
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

		$tab   = $this->active_tab();
		$shell = $this->header->shell();

		$shell->open_wrap();
		$shell->open();
		$this->header->render(
			$this->slug(),
			$this->title(),
			function () use ( $tab ): void {
				if ( 'live' === $tab ) {
					$this->actions->render_refresh_providers_form(
						$this->slug(),
						__( 'Refresh Detection', 'universal-geo-context' )
					);
				}

				$simulation_url = add_query_arg( 'tab', 'simulation', admin_url( 'admin.php?page=' . $this->slug() ) );
				$this->actions->render_link_button(
					$simulation_url,
					__( 'Start Simulation', 'universal-geo-context' )
				);
			}
		);

		$shell->open_content( true );
		$this->render_tab_nav( $tab );

		if ( 'simulation' === $tab ) {
			$this->render_simulation_tab();
		} else {
			$this->render_detection_tab();
		}

		$shell->close_content();
		$shell->close();
		$shell->close_wrap();
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
	 * Renders the Detection and Simulation pill navigation.
	 *
	 * @param string $active 'live' or 'simulation'.
	 *
	 * @return void
	 */
	private function render_tab_nav( string $active ): void {
		$base = admin_url( 'admin.php?page=' . $this->slug() );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->pill_navigation(
			__( 'Detection and simulation panels', 'universal-geo-context' ),
			array(
				array(
					'url'    => $base,
					'label'  => __( 'Detection', 'universal-geo-context' ),
					'icon'   => 'dashicons-search',
					'active' => 'live' === $active,
				),
				array(
					'url'    => add_query_arg( 'tab', 'simulation', $base ),
					'label'  => __( 'Simulation', 'universal-geo-context' ),
					'icon'   => 'dashicons-controls-play',
					'active' => 'simulation' === $active,
				),
			)
		);
	}

	/**
	 * Renders the Detection Inspector (M9).
	 *
	 * @return void
	 */
	private function render_detection_tab(): void {
		$explanation = $this->inspector->explain( AdminProbeFreshFlag::summary() );
		$this->renderer->render( $explanation );
	}

	/**
	 * Renders the country simulation tab.
	 *
	 * @return void
	 */
	private function render_simulation_tab(): void {
		$real_context      = $this->resolver->resolve();
		$effective_context = Plugin::instance()->context();
		$is_active         = $this->state->is_active();
		$active_country    = $this->state->active_country();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->warning_panel(
			__( 'Developer and QA tool', 'universal-geo-context' ),
			__( 'Country simulation overrides the visitor context for your browser session only. It does not change real geolocation, provider configuration, or shared geo caches.', 'universal-geo-context' )
		);

		$badge = $this->components->status_badge(
			$is_active ? __( 'Simulation active', 'universal-geo-context' ) : __( 'Simulation inactive', 'universal-geo-context' ),
			$is_active ? 'warning' : 'disabled'
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open(
			__( 'Current context', 'universal-geo-context' ),
			__( 'Real resolved country versus the effective country downstream plugins see.', 'universal-geo-context' ),
			$badge
		);

		$this->report->render_definition_list(
			array(
				'real_country'       => $this->context_summary( $real_context ),
				'effective_country'  => $this->context_summary( $effective_context ),
				'simulation_active'  => $is_active ? 'yes' : 'no',
				'simulated_country'  => $is_active && null !== $active_country
					? $this->catalog->label( $active_country ) . ' (' . $active_country . ')'
					: null,
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open(
			__( 'Controls', 'universal-geo-context' ),
			__( 'Start, change, or stop a simulated visitor country for this browser session.', 'universal-geo-context' )
		);

		if ( $is_active ) {
			$this->render_simulation_form(
				'universal_geo_simulation_change',
				__( 'Change simulated country', 'universal-geo-context' ),
				$active_country ?? '',
				'universal_geo_simulation'
			);

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ugc-ui-inline-form">';
			wp_nonce_field( 'universal_geo_simulation_stop' );
			echo '<input type="hidden" name="action" value="universal_geo_simulation_stop" />';
			submit_button( __( 'Stop simulation', 'universal-geo-context' ), 'secondary', 'submit', false );
			echo '</form>';
		} else {
			$this->render_simulation_form(
				'universal_geo_simulation_start',
				__( 'Start simulation', 'universal-geo-context' ),
				'',
				'universal_geo_simulation'
			);
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();
	}

	/**
	 * Builds a short context summary string.
	 *
	 * @param VisitorContext $context Context to summarize.
	 */
	private function context_summary( VisitorContext $context ): string {
		$country = $context->country_code ?? __( 'Unknown', 'universal-geo-context' );

		return sprintf(
			'%1$s — %2$s (confidence %3$s)',
			(string) $country,
			$context->source,
			(string) $context->confidence
		);
	}

	/**
	 * Renders a country selector POST form.
	 *
	 * @param string $action       admin_post action name.
	 * @param string $button       Submit button label.
	 * @param string $selected     Pre-selected country code.
	 * @param string $nonce_action Nonce action name.
	 *
	 * @return void
	 */
	private function render_simulation_form( string $action, string $button, string $selected, string $nonce_action ): void {
		$options = '<option value="">' . esc_html__( 'Select a country…', 'universal-geo-context' ) . '</option>';

		foreach ( $this->catalog->options() as $code => $label ) {
			$options .= sprintf(
				'<option value="%1$s" %2$s>%3$s (%1$s)</option>',
				esc_attr( $code ),
				selected( $selected, $code, false ),
				esc_html( $label )
			);
		}

		$select = sprintf(
			'<select name="simulation_country" id="universal-geo-simulation-country" required>%s</select>',
			$options
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( $nonce_action );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->select_row(
			'simulation_country',
			__( 'Country', 'universal-geo-context' ),
			'',
			$select,
			array( 'id' => 'universal-geo-simulation-country' )
		);

		submit_button( $button, 'primary', 'submit', false );
		echo '</form>';
	}
}
