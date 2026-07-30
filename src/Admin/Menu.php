<?php
/**
 * Top-level admin menu registration.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Registers the plugin menu, submenu pages, and one-release legacy URL compatibility.
 *
 * @internal
 * @final
 */
final class Menu {

	/**
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
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_page_url' ) );

		$this->overview->register_handlers();
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
	 * Redirects the v1.1.0 Settings-submenu URL for one release (removed M8).
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_page_url(): void {
		global $pagenow;

		if ( ! is_admin() || 'options-general.php' !== $pagenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only legacy bookmark detection.
		if ( ! isset( $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$page = sanitize_key( wp_unslash( $_GET['page'] ) );

		if ( AdminPageSlugs::LEGACY_OPTIONS_PAGE !== $page ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		$target = 'diagnostics' === $tab
			? admin_url( 'admin.php?page=' . AdminPageSlugs::DIAGNOSTICS )
			: admin_url( 'admin.php?page=' . AdminPageSlugs::OVERVIEW );

		wp_safe_redirect( $target );
		exit;
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
