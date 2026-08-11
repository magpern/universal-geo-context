<?php
/**
 * Plugin Name: Universal Geo Context
 * Plugin URI: https://github.com/magpern/universal-geo-context
 * Description: Visitor geolocation detection and country resolution. Evidence-based, privacy-respecting, and easily extensible.
 * Version: 1.8.0
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

define( 'UNIVERSAL_GEO_VERSION', '1.8.0' );
define( 'UNIVERSAL_GEO_PLUGIN_FILE', __FILE__ );

// Loaded unconditionally, before the PHP-version guard below (M5): this
// plugin is GitHub-distributed, not wordpress.org-hosted, so it does not
// get WordPress 4.6+'s automatic translation loading for .org plugins —
// without this call, every __()/esc_html__() call anywhere in the plugin
// is permanently untranslatable regardless of how many .mo files exist.
// Registered ahead of the guard so even the guard's own admin_notices
// message translates: that branch can run on a PHP version too old for the
// rest of this file to matter, so this call cannot live inside
// Plugin::init(), which never runs in that case.
add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'universal-geo-context', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

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
