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
	 */
	public function __construct(
		private readonly ContextResolver $resolver,
		private readonly SimulationState $state,
		private readonly CountryCatalog $catalog,
		private readonly SimulationController $controller,
		private readonly DetectionInspectorService $inspector,
		private readonly DetectionInspectorRenderer $renderer,
		private readonly AdminHeaderRenderer $header,
		private readonly AdminActionRenderer $actions
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

		$tab = $this->active_tab();

		echo '<div class="wrap">';
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
		$this->render_tab_nav( $tab );

		if ( 'simulation' === $tab ) {
			$this->render_simulation_tab();
		} else {
			$this->render_detection_tab();
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
	 * Renders the Detection and Simulation tab navigation.
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
			esc_html__( 'Detection', 'universal-geo-context' )
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

		echo '<div class="card" style="max-width: 720px;">';

		printf( '<p>%s</p>', esc_html__( 'Country simulation overrides the visitor context for your browser session only. It does not change real geolocation, provider configuration, or shared geo caches. Use it to test how downstream plugins respond to a different visitor country.', 'universal-geo-context' ) );
		printf( '<p><strong>%s</strong></p>', esc_html__( 'This is a developer and QA tool — not a production mechanism for controlling customer experience.', 'universal-geo-context' ) );

		echo '<h2>' . esc_html__( 'Current context', 'universal-geo-context' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width: 640px;"><tbody>';
		$this->render_context_row( __( 'Real resolved country', 'universal-geo-context' ), $real_context );
		$this->render_context_row( __( 'Effective country (what consumers see)', 'universal-geo-context' ), $effective_context );
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html__( 'Simulation active', 'universal-geo-context' ),
			esc_html( $is_active ? __( 'Yes', 'universal-geo-context' ) : __( 'No', 'universal-geo-context' ) )
		);
		if ( $is_active && null !== $active_country ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s (%3$s)</td></tr>',
				esc_html__( 'Simulated country', 'universal-geo-context' ),
				esc_html( $this->catalog->label( $active_country ) ),
				esc_html( $active_country )
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Controls', 'universal-geo-context' ) . '</h2>';

		if ( $is_active ) {
			$this->render_simulation_form(
				'universal_geo_simulation_change',
				__( 'Change simulated country', 'universal-geo-context' ),
				$active_country ?? '',
				'universal_geo_simulation'
			);

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top: 1em;">';
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

		echo '</div>';
	}

	/**
	 * Renders one context summary row.
	 *
	 * @param string         $label   Row label.
	 * @param VisitorContext $context Context to summarize.
	 *
	 * @return void
	 */
	private function render_context_row( string $label, VisitorContext $context ): void {
		$country = $context->country_code ?? __( 'Unknown', 'universal-geo-context' );
		$detail  = sprintf(
			'%1$s — %2$s (confidence %3$s)',
			(string) $country,
			$context->source,
			(string) $context->confidence
		);

		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( $detail )
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
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( $nonce_action );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );

		echo '<p>';
		echo '<label for="universal-geo-simulation-country"><strong>' . esc_html__( 'Country', 'universal-geo-context' ) . '</strong></label><br />';
		echo '<select name="simulation_country" id="universal-geo-simulation-country" required>';
		echo '<option value="">' . esc_html__( 'Select a country…', 'universal-geo-context' ) . '</option>';

		foreach ( $this->catalog->options() as $code => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s (%1$s)</option>',
				esc_attr( $code ),
				selected( $selected, $code, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		echo '</p>';

		submit_button( $button, 'primary', 'submit', false );
		echo '</form>';
	}
}
