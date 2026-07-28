<?php
/**
 * Uninstall script for Universal Geo Context.
 *
 * WordPress runs this file directly — the main plugin file is never
 * loaded first, so the Composer autoloader must be required here or
 * Settings is unreachable and class_exists() always fails silently.
 *
 * Settings is the sole owner of the one option this plugin persists in
 * M1 Step 1A (`universal_geo_settings`); this script performs no cleanup
 * of its own and runs no raw SQL.
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
