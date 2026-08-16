<?php
/**
 * Minimal WP_REST_Request stand-in for the WordPress-free unit bootstrap.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- impersonates a real WordPress core global class name, deliberately unprefixed.

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * A bare placeholder: ContextController::get_context() type-hints
	 * WP_REST_Request but never reads anything from it (§A, the route
	 * accepts no parameters) — no behavior is needed, only instantiability,
	 * for tests/unit/Rest/ContextControllerTest.php to construct one.
	 */
	class WP_REST_Request {
	}
}

// phpcs:enable
