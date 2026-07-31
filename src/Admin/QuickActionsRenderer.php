<?php
/**
 * Overview quick actions card.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Renders shortcut buttons on the Overview dashboard.
 *
 * @internal
 * @final
 */
final class QuickActionsRenderer {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param AdminActionRenderer      $actions    Shared admin controls.
	 * @param AdminComponentRenderer     $components Design-system components.
	 */
	public function __construct(
		private readonly AdminActionRenderer $actions,
		private readonly AdminComponentRenderer $components
	) {
	}

	/**
	 * Renders the Quick Actions panel.
	 *
	 * @return void
	 */
	public function render(): void {
		ob_start();
		$this->actions->render_refresh_providers_form(
			AdminPageSlugs::OVERVIEW,
			__( 'Refresh Provider Diagnostics', 'universal-geo-context' )
		);
		$refresh_html = ob_get_clean();

		$actions = array(
			array(
				'label'       => __( 'Configure Settings', 'universal-geo-context' ),
				'url'         => AdminPageRegistry::page_url( AdminPageSlugs::SETTINGS ),
				'description' => __( 'Defaults, MaxMind, remote provider', 'universal-geo-context' ),
			),
			array(
				'label'       => __( 'Detection & Testing', 'universal-geo-context' ),
				'url'         => AdminPageRegistry::page_url( AdminPageSlugs::DETECTION ),
				'description' => __( 'Inspect resolution and simulate countries', 'universal-geo-context' ),
			),
			array(
				'label'       => __( 'Providers', 'universal-geo-context' ),
				'url'         => AdminPageRegistry::page_url( AdminPageSlugs::PROVIDERS ),
				'description' => __( 'Availability, health, and configuration', 'universal-geo-context' ),
			),
			array(
				'label'       => __( 'Trusted Proxies', 'universal-geo-context' ),
				'url'         => AdminPageRegistry::page_url( AdminPageSlugs::TRUSTED_PROXIES ),
				'description' => __( 'Forwarded IP handling', 'universal-geo-context' ),
			),
			array(
				'label'       => __( 'Diagnostics', 'universal-geo-context' ),
				'url'         => AdminPageRegistry::page_url( AdminPageSlugs::DIAGNOSTICS ),
				'description' => __( 'Full troubleshooting report', 'universal-geo-context' ),
			),
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->feature_section_open(
			__( 'Quick Actions', 'universal-geo-context' ),
			__( 'Jump to common administrative tasks.', 'universal-geo-context' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->quick_actions_panel( __( 'Quick Actions', 'universal-geo-context' ), $actions );

		if ( '' !== $refresh_html ) {
			printf( '<div class="ugc-ui-quick-actions__utility">%s</div>', $refresh_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form markup from action renderer.
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->feature_section_close();
	}
}
