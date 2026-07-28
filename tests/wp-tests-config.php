<?php
/**
 * WordPress test-suite configuration, driven by environment variables so the
 * same file works under the local Docker harness and CI.
 *
 * Loaded by wp-phpunit's own includes/bootstrap.php (via WP_PHPUNIT__TESTS_CONFIG),
 * which requires wp-settings.php itself in the correct order — this file
 * must only define constants, never load WordPress.
 *
 * @package UniversalGeoContext
 */

$universal_geo_core_dir = getenv( 'WP_CORE_DIR' );
if ( ! $universal_geo_core_dir ) {
	$universal_geo_core_dir = dirname( __DIR__ ) . '/tests/tmp/wordpress';
}

define( 'ABSPATH', rtrim( $universal_geo_core_dir, '/' ) . '/' );

define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ? getenv( 'WP_DB_NAME' ) : 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ? getenv( 'WP_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASS' ) ? getenv( 'WP_DB_PASS' ) : 'root' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ? getenv( 'WP_DB_HOST' ) : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Universal Geo Context Test Site' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

define( 'WP_DEBUG', true );
