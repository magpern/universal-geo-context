<?php
/**
 * Uninstall script for Universal Geo Context.
 *
 * WordPress runs this file directly — the main plugin file is never
 * loaded first, so the Composer autoloader must be required here or
 * Settings is unreachable and class_exists() always fails silently.
 *
 * Three classes each own a slice of persisted state and are called here in
 * turn (M4, closing the M2/M3 all-or-nothing retention gap
 * `docs/PRIVACY.md` previously recorded): `Settings::uninstall()` (its own
 * option, `ProviderHealthStore`'s option, and the circuit breaker's option),
 * `GeoCache::uninstall()` (the cache salt and epoch options), and
 * `AdminScreen::uninstall()` (the per-user first-run-notice meta). This
 * script performs no cleanup of its own and runs no raw SQL.
 *
 * @package UniversalGeoContext
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$universal_geo_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $universal_geo_autoload ) ) {
	require_once $universal_geo_autoload;
}

if ( ! class_exists( \UniversalGeo\Settings::class ) ) {
	// Autoloader missing or composer install was never run; nothing to remove.
	return;
}

\UniversalGeo\Settings::uninstall();
\UniversalGeo\Cache\GeoCache::uninstall();
\UniversalGeo\Admin\AdminScreen::uninstall();
