<?php
/**
 * Unit test bootstrap: composer autoloader only, WordPress is not loaded.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'UNIVERSAL_GEO_VERSION' ) ) {
	define( 'UNIVERSAL_GEO_VERSION', '0.0.0-test' );
}

if ( ! defined( 'UNIVERSAL_GEO_PLUGIN_FILE' ) ) {
	define( 'UNIVERSAL_GEO_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/universal-geo-context.php' );
}

// AdminScreen::maxmind_path_is_valid() reads WP_CONTENT_DIR directly (M3);
// no WordPress is loaded in the unit bootstrap, so a fixture directory
// stands in for it. Tests create/remove files under it directly.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/universal-geo-context-test-wp-content' );
}

if ( ! is_dir( WP_CONTENT_DIR ) ) {
	mkdir( WP_CONTENT_DIR, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Minimal WordPress stubs for unit tests without WordPress loaded.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

// In-memory filter/action registries backing add_filter/apply_filters and
// add_action/do_action below, so hook tests can register real callbacks
// and observe real invocation (arguments, order, call counts) without a
// mocking framework.
$GLOBALS['universal_geo_test_filters'] = array();
$GLOBALS['universal_geo_test_actions'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$GLOBALS['universal_geo_test_filters'][ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		foreach ( $GLOBALS['universal_geo_test_filters'][ $tag ] ?? array() as $callback ) {
			$value = call_user_func( $callback, $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$GLOBALS['universal_geo_test_actions'][ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		foreach ( $GLOBALS['universal_geo_test_actions'][ $tag ] ?? array() as $callback ) {
			call_user_func( $callback, ...$args );
		}
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function, $message, $version ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, Universal.NamingConventions.NoReservedKeywordParameterNames.functionFound
		// In tests, trigger an error so the developer sees it.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error, WordPress.Security.EscapeOutput.OutputNotEscaped
		trigger_error(
			sprintf( 'Function %s was called incorrectly. %s', $function, $message ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			E_USER_WARNING
		);
	}
}

// In-memory object-cache store backing the wp_cache_get/wp_cache_set stubs
// below, plus a record of every wp_cache_set() call (key/group/expire) so
// tests can assert on the TTL actually passed, e.g. by GeoCache.
$GLOBALS['universal_geo_test_object_cache']       = array();
$GLOBALS['universal_geo_test_object_cache_calls'] = array();

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$composite = $group . '|' . $key;

		return array_key_exists( $composite, $GLOBALS['universal_geo_test_object_cache'] )
			? $GLOBALS['universal_geo_test_object_cache'][ $composite ]
			: false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$composite = $group . '|' . $key;

		$GLOBALS['universal_geo_test_object_cache'][ $composite ] = $value;
		$GLOBALS['universal_geo_test_object_cache_calls'][]       = array(
			'key'    => $key,
			'group'  => $group,
			'expire' => $expire,
		);

		return true;
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['universal_geo_test_using_ext_object_cache'] ?? true;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Identity translation stub for unit tests without WordPress loaded.
	 *
	 * @param string $text   Source string.
	 * @param string $domain Text domain (ignored).
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $text;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $text;
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $text;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $text;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $url;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( $display ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce" />';
		}
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		echo '<input type="submit" name="' . esc_attr( $name ) . '" class="button" value="' . esc_attr( (string) $text ) . '" />';
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $display = true ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $result;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $display = true ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $result;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Identity translate-and-escape stub (M5): both the __() and esc_html()
	 * stubs above are identity functions in this environment, so this one
	 * is written as a direct identity too, rather than calling __() with a
	 * variable argument (which the I18n sniff correctly forbids at real
	 * call sites and does not distinguish from this stub's own definition).
	 *
	 * @param string $text   Source string.
	 * @param string $domain Text domain (ignored).
	 */
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $text;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $GLOBALS['universal_geo_test_current_user_can'] ?? true;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['universal_geo_test_is_logged_in'] ?? true;
	}
}

if ( ! defined( 'COOKIEPATH' ) ) {
	define( 'COOKIEPATH', '/' );
}

if ( ! defined( 'COOKIE_DOMAIN' ) ) {
	define( 'COOKIE_DOMAIN', '' );
}

if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
	define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return 'test-salt-' . $scheme;
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['universal_geo_test_is_ssl'] ?? false;
	}
}

if ( ! function_exists( 'headers_sent' ) ) {
	function headers_sent( &$file = null, &$line = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found
		unset( $file, $line );

		return $GLOBALS['universal_geo_test_headers_sent'] ?? false;
	}
}

if ( ! function_exists( 'setcookie' ) ) {
	function setcookie( $name, $value = '', $expires_or_options = 0, $path = '', $domain = '', $secure = false, $httponly = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$GLOBALS['universal_geo_test_setcookie_calls'][] = func_get_args();
		if ( is_array( $expires_or_options ) && array_key_exists( 'expires', $expires_or_options ) && $expires_or_options['expires'] < time() ) {
			unset( $_COOKIE[ $name ] );
		} else {
			$_COOKIE[ $name ] = $value;
		}

		return true;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $GLOBALS['universal_geo_test_wp_version'] ?? '0.0.0-test';
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
	}
}

if ( ! function_exists( 'get_plugin_data' ) ) {
	function get_plugin_data( $file, $markup = true, $translate = true ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $file, $markup, $translate );

		return array(
			'PluginURI' => 'https://github.com/magpern/universal-geo-context',
		);
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value = null, $url = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( is_array( $key ) ) {
			$args = $key;
			$url  = $value;
		} else {
			$args = array( $key => $value );
		}

		$separator = ( false === strpos( (string) $url, '?' ) ) ? '?' : '&';

		return $url . $separator . http_build_query( $args );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['universal_geo_test_is_admin'] ?? false;
	}
}

// In-memory options store backing the get_option/add_option/update_option/
// delete_option stubs below, so tests can exercise Settings::install() and
// Settings::uninstall() without a database. universal_geo_test_option_autoload
// records the autoload flag every add_option() call was given (M3:
// ProviderHealthStore's non-autoload invariant needs to be assertable).
$GLOBALS['universal_geo_test_options']         = array();
$GLOBALS['universal_geo_test_option_autoload'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound
		return array_key_exists( $option, $GLOBALS['universal_geo_test_options'] )
			? $GLOBALS['universal_geo_test_options'][ $option ]
			: $default;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $option, $value, $deprecated = '', $autoload = 'yes' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, Universal.NamingConventions.NoReservedKeywordParameterNames.deprecatedFound
		$GLOBALS['universal_geo_test_option_autoload'][ $option ] = $autoload;

		if ( array_key_exists( $option, $GLOBALS['universal_geo_test_options'] ) ) {
			return false;
		}
		$GLOBALS['universal_geo_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['universal_geo_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$existed = array_key_exists( $option, $GLOBALS['universal_geo_test_options'] );
		unset( $GLOBALS['universal_geo_test_options'][ $option ] );
		return $existed;
	}
}

// In-memory single-event-per-hook WP-Cron stub (M6): this plugin only ever
// schedules one hook (universal_geo_maxmind_update, no args), so the stub
// tracks at most one {timestamp, schedule} pair per hook name rather than a
// full cron array — enough to exercise UpdateScheduler's own logic without
// WordPress loaded.
$GLOBALS['universal_geo_test_cron'] = array();

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $GLOBALS['universal_geo_test_cron'][ $hook ]['timestamp'] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$GLOBALS['universal_geo_test_cron'][ $hook ] = array(
			'timestamp' => $timestamp,
			'schedule'  => $recurrence,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $GLOBALS['universal_geo_test_cron'][ $hook ] );
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $GLOBALS['universal_geo_test_cron'][ $hook ] );
		return true;
	}
}

if ( ! function_exists( 'wp_get_scheduled_event' ) ) {
	function wp_get_scheduled_event( $hook, $args = array(), $timestamp = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! isset( $GLOBALS['universal_geo_test_cron'][ $hook ] ) ) {
			return false;
		}

		$event = $GLOBALS['universal_geo_test_cron'][ $hook ];

		return (object) array(
			'hook'      => $hook,
			'timestamp' => $event['timestamp'],
			'schedule'  => $event['schedule'],
			'args'      => array(),
		);
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $action, $query_arg );

		return true;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$GLOBALS['universal_geo_test_last_redirect'] = $location;

		throw new RuntimeException( 'redirect' );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $title, $args );

		throw new RuntimeException( 'wp_die: ' . (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test stub, never rendered.
	}
}

// phpcs:enable

// Hand-rolled test doubles (Revision 3 §16: no mocking framework).
require_once __DIR__ . '/Doubles/FakeGeoProvider.php';
require_once __DIR__ . '/Doubles/TrackingGeoProvider.php';
require_once __DIR__ . '/Doubles/FakeClientIpResolver.php';
