<?php
/**
 * Uninstall script for Universal Geo Context.
 *
 * WordPress runs this file directly — the main plugin file is never
 * loaded first, so the Composer autoloader must be required here or
 * Settings is unreachable and class_exists() always fails silently.
 *
 * Five classes each own a slice of persisted state and are called here in
 * turn (M4, closing the M2/M3 all-or-nothing retention gap
 * `docs/PRIVACY.md` previously recorded; M6 closes the same gap for the
 * managed-database feature): `Settings::uninstall()` (its own option,
 * `ProviderHealthStore`'s option, the circuit breaker's option, and — M6 —
 * `UpdateLock`'s and `DatabaseManager`'s state options), `GeoCache::uninstall()`
 * (the per-user first-run-notice meta), `UpdateScheduler::uninstall()` (clears
 * the managed-database cron hook), and `DatabaseManager::uninstall_files()`
 * (deletes the managed directory's files, by exact filename, never a glob).
 * This script performs no cleanup of its own and runs no raw SQL.
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
\UniversalGeo\Admin\FirstRunNotice::uninstall();
\UniversalGeo\MaxMind\UpdateScheduler::uninstall();
\UniversalGeo\MaxMind\DatabaseManager::uninstall_files( \UniversalGeo\MaxMind\DatabaseManager::managed_directory() );
