<?php
/**
 * Public API: the six global universal_geo_* functions (Revision 3 §13).
 *
 * Loaded via Composer autoload.files — never namespaced, since these are
 * meant to be called as bare global functions. Every declaration is
 * guarded with function_exists() so a second, conflicting copy of this
 * plugin cannot fatal a site. Each function returns a value in every
 * context and never throws or fatals, including before the plugin has
 * booted (see Plugin::context()).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Plugin;

if ( ! function_exists( 'universal_geo_get_context' ) ) {
	/**
	 * Returns the resolved visitor context for the current request.
	 *
	 * Guard before use: if ( ! function_exists( 'universal_geo_get_context' ) ) { … }
	 * Call at or after the plugins_loaded priority 20 (the plugin boots at 10).
	 *
	 * @return VisitorContext
	 */
	function universal_geo_get_context(): VisitorContext { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return Plugin::instance()->context();
	}
}

if ( ! function_exists( 'universal_geo_get_country_code' ) ) {
	/**
	 * Returns the resolved country code.
	 *
	 * @return string|null Uppercase ISO 3166-1 alpha-2 country code, or null when unknown.
	 */
	function universal_geo_get_country_code(): ?string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return universal_geo_get_context()->country_code;
	}
}

if ( ! function_exists( 'universal_geo_get_region_code' ) ) {
	/**
	 * The first-level subdivision code from the context, when the winning
	 * provider supplied one (M13, MaxMind City-edition databases only); null
	 * otherwise, including for every country-only provider and database.
	 *
	 * @return string|null
	 */
	function universal_geo_get_region_code(): ?string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return universal_geo_get_context()->region_code;
	}
}

if ( ! function_exists( 'universal_geo_get_source' ) ) {
	/**
	 * Returns the resolved source.
	 *
	 * @return string Provider id, 'default', or 'unknown'.
	 */
	function universal_geo_get_source(): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return universal_geo_get_context()->source;
	}
}

if ( ! function_exists( 'universal_geo_get_confidence' ) ) {
	/**
	 * Returns the resolved confidence.
	 *
	 * @return float Confidence in the country determination, 0.0 to 1.0 inclusive.
	 */
	function universal_geo_get_confidence(): float { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return universal_geo_get_context()->confidence;
	}
}

if ( ! function_exists( 'universal_geo_api_version' ) ) {
	/**
	 * Gate on this when depending on behaviour newer than v1.
	 *
	 * @return int
	 */
	function universal_geo_api_version(): int { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return 1;
	}
}
