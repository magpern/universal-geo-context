<?php
/**
 * Stable admin page slugs for Universal Geo Context.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Central slug constants — one source of truth for menu registration,
 * PRG redirects, and legacy URL compatibility.
 *
 * @internal
 * @final
 */
final class AdminPageSlugs {

	/**
	 * Overview (top-level menu landing page).
	 */
	public const OVERVIEW = 'universal-geo-context';

	/**
	 * Detection & Testing submenu.
	 */
	public const DETECTION = 'universal-geo-context-detection';

	/**
	 * Providers submenu.
	 */
	public const PROVIDERS = 'universal-geo-context-providers';

	/**
	 * Trusted Proxies submenu.
	 */
	public const TRUSTED_PROXIES = 'universal-geo-context-trusted-proxies';

	/**
	 * Diagnostics submenu.
	 */
	public const DIAGNOSTICS = 'universal-geo-context-diagnostics';

	/**
	 * Settings submenu.
	 */
	public const SETTINGS = 'universal-geo-context-settings';

	/**
	 * Legacy Settings-submenu slug (v1.1.0 and earlier).
	 */
	public const LEGACY_OPTIONS_PAGE = 'universal-geo-context';

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {
	}
}
