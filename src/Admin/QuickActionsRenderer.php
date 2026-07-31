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
	 * Stores the action renderer.
	 *
	 * @param AdminActionRenderer $actions Shared admin controls.
	 */
	public function __construct(
		private readonly AdminActionRenderer $actions
	) {
	}

	/**
	 * Renders the Quick Actions card.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<div class="postbox universal-geo-quick-actions"><div class="postbox-header"><h2 class="hndle">';
		echo esc_html__( 'Quick Actions', 'universal-geo-context' );
		echo '</h2></div><div class="inside">';
		echo '<div class="universal-geo-action-buttons">';

		$this->actions->render_link_button(
			AdminPageRegistry::page_url( AdminPageSlugs::SETTINGS ),
			__( 'Configure Settings', 'universal-geo-context' )
		);

		$this->actions->render_link_button(
			AdminPageRegistry::page_url( AdminPageSlugs::DETECTION ),
			__( 'Detection & Testing', 'universal-geo-context' )
		);

		$this->actions->render_refresh_providers_form(
			AdminPageSlugs::OVERVIEW,
			__( 'Refresh Provider Diagnostics', 'universal-geo-context' )
		);

		$this->actions->render_link_button(
			AdminPageRegistry::page_url( AdminPageSlugs::PROVIDERS ),
			__( 'Providers', 'universal-geo-context' )
		);

		$this->actions->render_link_button(
			AdminPageRegistry::page_url( AdminPageSlugs::TRUSTED_PROXIES ),
			__( 'Trusted Proxies', 'universal-geo-context' )
		);

		$this->actions->render_link_button(
			AdminPageRegistry::page_url( AdminPageSlugs::DIAGNOSTICS ),
			__( 'Diagnostics', 'universal-geo-context' )
		);

		echo '</div></div></div>';
	}
}
