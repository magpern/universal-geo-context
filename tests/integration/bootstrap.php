<?php
/**
 * Integration test bootstrap: loads the WordPress test suite with
 * WooCommerce active, this plugin available under wp-content/plugins/.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$ugeo_root = dirname( __DIR__, 2 );

require_once $ugeo_root . '/vendor/autoload.php';

// Hand-rolled test doubles under tests/integration/Support/ — required
// manually rather than left to Composer's PSR-4 autoloader, which cannot
// resolve `UniversalGeo\Tests\Integration\...` (capital I) against this
// directory's actual lowercase `tests/integration/` path on a case-
// sensitive filesystem. Mirrors tests/unit/bootstrap.php's identical
// manual-require workaround for tests/unit/Doubles/.
require_once __DIR__ . '/Support/FixedResultClientIpResolver.php';

define( 'UNIVERSAL_GEO_VERSION', '0.0.0-test' );

if ( ! defined( 'UNIVERSAL_GEO_PLUGIN_FILE' ) ) {
	define( 'UNIVERSAL_GEO_PLUGIN_FILE', $ugeo_root . '/universal-geo-context.php' );
}

$ugeo_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $ugeo_tests_dir ) {
	$ugeo_tests_dir = $ugeo_root . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . dirname( __DIR__ ) . '/wp-tests-config.php' );
}

require_once $ugeo_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		\WC_Install::install();
		$GLOBALS['wp_roles'] = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
);

require_once $ugeo_tests_dir . '/includes/bootstrap.php';

// phpcs:enable
