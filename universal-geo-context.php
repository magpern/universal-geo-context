<?php
/**
 * Plugin Name: Universal Geo Context
 * Plugin URI: https://github.com/magpern/universal-geo-context
 * Description: Visitor geolocation detection and country resolution. Evidence-based, privacy-respecting, and easily extensible.
 * Version: 0.4.0
 * Author: magpern
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: universal-geo-context
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.1
 *
 * @package UniversalGeoContext
 */

defined( 'ABSPATH' ) || exit;

define( 'UNIVERSAL_GEO_VERSION', '0.4.0' );
define( 'UNIVERSAL_GEO_PLUGIN_FILE', __FILE__ );

// PHP version guard. The "Requires PHP" header stops activation on WP 5.1+,
// but a file-drop install can bypass it, so fail closed with a notice.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Universal Geo Context requires PHP 8.1 or newer and is inactive.', 'universal-geo-context' )
			);
		}
	);
	return;
}

$universal_geo_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $universal_geo_autoload ) ) {
	require_once $universal_geo_autoload;
}

// HPOS and blocks compatibility declarations. These are honest: the plugin
// never touches orders or the cart, so both are compatible.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', UNIVERSAL_GEO_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', UNIVERSAL_GEO_PLUGIN_FILE, true );
		}
	}
);

if ( class_exists( \UniversalGeo\Plugin::class ) ) {
	register_activation_hook( __FILE__, array( \UniversalGeo\Plugin::class, 'activate' ) );
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( \UniversalGeo\Plugin::class ) ) {
			// Autoloader missing (composer install not run); stay inert.
			return;
		}

		\UniversalGeo\Plugin::instance()->init();
	}
);
