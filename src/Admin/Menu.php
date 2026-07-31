<?php
/**
 * Top-level admin menu registration.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Registers the plugin menu and submenu pages.
 *
 * @internal
 * @final
 */
final class Menu {

	/**
	 * Stores the injected page instances.
	 *
	 * @param OverviewPage       $overview        Overview landing page.
	 * @param DetectionPage      $detection       Detection & Testing page.
	 * @param ProvidersPage      $providers       Providers page.
	 * @param TrustedProxiesPage $trusted_proxies Trusted Proxies page.
	 * @param DiagnosticsPage    $diagnostics     Diagnostics page.
	 * @param SettingsPage       $settings        Settings page.
	 */
	public function __construct(
		private readonly OverviewPage $overview,
		private readonly DetectionPage $detection,
		private readonly ProvidersPage $providers,
		private readonly TrustedProxiesPage $trusted_proxies,
		private readonly DiagnosticsPage $diagnostics,
		private readonly SettingsPage $settings
	) {
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu_pages' ) );

		$this->overview->register_handlers();
		$this->detection->register_handlers();
		$this->trusted_proxies->register_handlers();
		$this->settings->register_handlers();
	}

	/**
	 * Registers the top-level menu and submenu pages.
	 *
	 * @return void
	 */
	public function register_menu_pages(): void {
		add_menu_page(
			__( 'Universal Geo Context', 'universal-geo-context' ),
			__( 'Universal Geo Context', 'universal-geo-context' ),
			'manage_options',
			AdminPageSlugs::OVERVIEW,
			array( $this->overview, 'render' ),
			'dashicons-location-alt',
			null
		);

		add_submenu_page(
			AdminPageSlugs::OVERVIEW,
			$this->overview->title(),
			$this->overview->menu_title(),
			$this->overview->capability(),
			$this->overview->slug(),
			array( $this->overview, 'render' )
		);

		foreach ( $this->pages() as $page ) {
			if ( AdminPageSlugs::OVERVIEW === $page->slug() ) {
				continue;
			}

			add_submenu_page(
				AdminPageSlugs::OVERVIEW,
				$page->title(),
				$page->menu_title(),
				$page->capability(),
				$page->slug(),
				array( $page, 'render' )
			);
		}
	}

	/**
	 * Registered pages in menu order.
	 *
	 * @return Page[]
	 */
	private function pages(): array {
		return array(
			$this->overview,
			$this->detection,
			$this->providers,
			$this->trusted_proxies,
			$this->diagnostics,
			$this->settings,
		);
	}
}
