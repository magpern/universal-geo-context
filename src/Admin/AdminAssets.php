<?php
/**
 * Admin stylesheet and script registration.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Enqueues presentation-only admin assets on plugin screens.
 *
 * @internal
 * @final
 */
final class AdminAssets {

	/**
	 * Body class added on plugin admin screens.
	 */
	public const BODY_CLASS = 'ugc-settings-page';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Enqueues admin CSS when viewing a plugin screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		unset( $hook_suffix );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( ! $this->is_plugin_page( $page ) ) {
			return;
		}

		wp_enqueue_style(
			'universal-geo-context-admin',
			plugins_url( 'assets/css/admin.css', UNIVERSAL_GEO_PLUGIN_FILE ),
			array(),
			UNIVERSAL_GEO_VERSION
		);

		wp_enqueue_script(
			'universal-geo-context-admin',
			plugins_url( 'assets/js/admin.js', UNIVERSAL_GEO_PLUGIN_FILE ),
			array(),
			UNIVERSAL_GEO_VERSION,
			true
		);
	}

	/**
	 * Adds the scoped body class on plugin admin screens.
	 *
	 * @param string $classes Space-separated admin body classes.
	 */
	public function body_class( string $classes ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( ! $this->is_plugin_page( $page ) ) {
			return $classes;
		}

		return trim( $classes . ' ' . self::BODY_CLASS );
	}

	/**
	 * Returns whether a query `page` value belongs to this plugin.
	 *
	 * @param string $page Sanitized page slug.
	 *
	 * @return bool
	 */
	private function is_plugin_page( string $page ): bool {
		foreach ( AdminPageRegistry::navigation_items() as $item ) {
			if ( $item['slug'] === $page ) {
				return true;
			}
		}

		return false;
	}
}
