<?php
/**
 * Integration test bootstrap: WordPress is loaded, databases available.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Integration test bootstrap; these errors are development-time only.

// Determine the test environment config path (set by tests/bin/install-wp.sh).
$config_file = dirname( __DIR__ ) . '/wp-tests-config.php';
if ( ! file_exists( $config_file ) ) {
	echo "Error: Could not find $config_file\n";
	echo "Run 'bash tests/bin/install-wp.sh' first.\n";
	exit( 1 );
}

// phpcs:enable

require_once $config_file;
require_once dirname( __DIR__ ) . '/tmp/wordpress/wp-settings.php';

define( 'UNIVERSAL_GEO_VERSION', '0.0.0-test' );
