<?php
/**
 * Central admin page metadata for navigation and descriptions.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Single source of truth for in-plugin navigation order, labels, and copy.
 *
 * @internal
 * @final
 */
final class AdminPageRegistry {

	/**
	 * Returns navigation items in display order (M10).
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	public static function navigation_items(): array {
		return array(
			array(
				'slug'  => AdminPageSlugs::OVERVIEW,
				'label' => __( 'Overview', 'universal-geo-context' ),
			),
			array(
				'slug'  => AdminPageSlugs::SETTINGS,
				'label' => __( 'Settings', 'universal-geo-context' ),
			),
			array(
				'slug'  => AdminPageSlugs::DETECTION,
				'label' => __( 'Detection & Testing', 'universal-geo-context' ),
			),
			array(
				'slug'  => AdminPageSlugs::PROVIDERS,
				'label' => __( 'Providers', 'universal-geo-context' ),
			),
			array(
				'slug'  => AdminPageSlugs::TRUSTED_PROXIES,
				'label' => __( 'Trusted Proxies', 'universal-geo-context' ),
			),
			array(
				'slug'  => AdminPageSlugs::DIAGNOSTICS,
				'label' => __( 'Diagnostics', 'universal-geo-context' ),
			),
		);
	}

	/**
	 * Returns the short page description shown beneath the title.
	 *
	 * @param string $slug Active admin page slug.
	 *
	 * @return string
	 */
	public static function description( string $slug ): string {
		return match ( $slug ) {
			AdminPageSlugs::OVERVIEW => __(
				'Monitor the current health and configuration of Universal Geo Context.',
				'universal-geo-context'
			),
			AdminPageSlugs::SETTINGS => __(
				'Configure providers, downloads, defaults, and plugin behaviour.',
				'universal-geo-context'
			),
			AdminPageSlugs::DETECTION => __(
				'Inspect how the current visitor location was resolved and test simulated locations.',
				'universal-geo-context'
			),
			AdminPageSlugs::PROVIDERS => __(
				'Review provider availability, health, and configuration.',
				'universal-geo-context'
			),
			AdminPageSlugs::TRUSTED_PROXIES => __(
				'Configure trusted proxy networks and forwarded IP handling.',
				'universal-geo-context'
			),
			AdminPageSlugs::DIAGNOSTICS => __(
				'Review system health and troubleshooting information.',
				'universal-geo-context'
			),
			default => '',
		};
	}

	/**
	 * Returns the admin URL for one plugin page slug.
	 *
	 * @param string $slug Page slug.
	 *
	 * @return string
	 */
	public static function page_url( string $slug ): string {
		return admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {
	}
}
