<?php
/**
 * Integration tests for UniversalGeo\Providers\WooCommerceProvider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration\Providers;

use UniversalGeo\Model\GeoCandidate;
use UniversalGeo\Providers\WooCommerceProvider;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers WooCommerceProvider against the real WC_Geolocation class (Revision
 * 3 §16): the M2 acceptance behavior with no MaxMind integration configured
 * (country always empty → null, matching the documented dev-site acceptance
 * note), the re-entrancy guard actually firing under WooCommerce's own
 * woocommerce_geolocate_ip filter, the zero-outbound-HTTP proof via a
 * pre_http_request trap, and graceful degradation against a hostile
 * third-party woocommerce_get_geolocation filter.
 */
final class WooCommerceProviderTest extends WP_UnitTestCase {

	public function test_get_id_is_woocommerce(): void {
		$this->assertSame( 'woocommerce', ( new WooCommerceProvider() )->get_id() );
	}

	public function test_is_available_is_true_with_real_woocommerce_loaded(): void {
		$this->assertTrue( class_exists( 'WC_Geolocation' ) );
		$this->assertTrue( ( new WooCommerceProvider() )->is_available() );
	}

	// ---- M2 acceptance: no MaxMind integration configured ----------------------------

	public function test_resolve_returns_null_for_a_public_ip_with_no_geolocation_integration(): void {
		// Documented M2 acceptance note (Revision 3 §7): with no MaxMind
		// key/integration configured — the state of this fresh WooCommerce
		// install — geolocate_ip($ip, false, false) always returns an empty
		// country. This is expected, not a bug.
		$this->assertNull( ( new WooCommerceProvider() )->resolve( '8.8.8.8' ) );
	}

	public function test_resolve_calls_geolocate_ip_with_both_fallbacks_disabled(): void {
		// If either fallback were left enabled, WooCommerce would attempt
		// geolocate_via_api() or get_external_ip_address() — outbound HTTP.
		// Proven indirectly here (no country ever appears without a
		// registered geolocation filter) and directly by the
		// zero-outbound-HTTP test below.
		$this->assertNull( ( new WooCommerceProvider() )->resolve( '203.0.113.1' ) );
	}

	// ---- Extracting a real result ------------------------------------------------------

	public function test_resolve_extracts_the_country_when_woocommerce_actually_geolocates(): void {
		// Simulates an active geolocation integration (e.g. MaxMind) by
		// hooking woocommerce_get_geolocation directly — this environment
		// has no real MaxMind database installed, so this is the only way
		// to prove this provider's own extraction logic without one.
		$filter = static function ( $geolocation ) {
			$geolocation['country'] = 'SE';
			return $geolocation;
		};

		add_filter( 'woocommerce_get_geolocation', $filter, 10, 2 );
		$candidate = ( new WooCommerceProvider() )->resolve( '8.8.8.8' );
		remove_filter( 'woocommerce_get_geolocation', $filter, 10 );

		$this->assertInstanceOf( GeoCandidate::class, $candidate );
		$this->assertSame( 'SE', $candidate->country_code );
	}

	public function test_resolve_region_is_always_null_even_if_woocommerce_returns_a_state(): void {
		$filter = static function ( $geolocation ) {
			$geolocation['country'] = 'SE';
			$geolocation['state']   = 'AB';
			return $geolocation;
		};

		add_filter( 'woocommerce_get_geolocation', $filter, 10, 2 );
		$candidate = ( new WooCommerceProvider() )->resolve( '8.8.8.8' );
		remove_filter( 'woocommerce_get_geolocation', $filter, 10 );

		$this->assertNull( $candidate->region_code );
	}

	public function test_resolve_degrades_gracefully_when_country_is_not_a_string(): void {
		// A hostile or buggy third party wired into WooCommerce's own
		// public filter can return anything — this provider must not trust
		// it structurally.
		$filter = static function ( $geolocation ) {
			$geolocation['country'] = array( 'unexpected' );
			return $geolocation;
		};

		add_filter( 'woocommerce_get_geolocation', $filter, 10, 2 );
		$result = ( new WooCommerceProvider() )->resolve( '8.8.8.8' );
		remove_filter( 'woocommerce_get_geolocation', $filter, 10 );

		$this->assertNull( $result );
	}

	// ---- Re-entrancy guard --------------------------------------------------------------

	public function test_re_entrancy_guard_prevents_infinite_recursion(): void {
		// Simulates a site wiring this plugin's own resolution back into
		// WooCommerce's own woocommerce_geolocate_ip filter. Without the
		// static $in_flight guard, this recurses indefinitely (resolve() ->
		// geolocate_ip() -> the filter below -> resolve() -> ...); with it,
		// the nested call returns null immediately and the outer call also
		// finds no country.
		$provider = new WooCommerceProvider();

		$filter = static function ( $country_code, $ip_address ) use ( $provider ) {
			$inner = $provider->resolve( $ip_address );
			return null !== $inner ? $inner->country_code : false;
		};

		add_filter( 'woocommerce_geolocate_ip', $filter, 10, 2 );
		$result = $provider->resolve( '8.8.8.8' );
		remove_filter( 'woocommerce_geolocate_ip', $filter, 10 );

		$this->assertNull( $result );
	}

	// ---- Zero outbound HTTP ---------------------------------------------------------------

	public function test_makes_zero_outbound_http_requests(): void {
		$called = false;

		// pre_http_request passes ($preempt, $args, $url); none are needed
		// to prove the trap fired, so none are declared.
		$trap = static function () use ( &$called ) {
			$called = true;
			return new WP_Error( 'blocked_in_test', 'Outbound HTTP blocked in test' );
		};

		add_filter( 'pre_http_request', $trap, 10, 3 );
		( new WooCommerceProvider() )->resolve( '8.8.8.8' );
		remove_filter( 'pre_http_request', $trap, 10 );

		$this->assertFalse( $called );
	}

	public function test_makes_zero_outbound_http_requests_even_with_a_geolocation_filter_registered(): void {
		$called = false;

		// pre_http_request passes ($preempt, $args, $url); none are needed
		// to prove the trap fired, so none are declared.
		$trap = static function () use ( &$called ) {
			$called = true;
			return new WP_Error( 'blocked_in_test', 'Outbound HTTP blocked in test' );
		};

		$geolocation_filter = static function ( $geolocation ) {
			$geolocation['country'] = 'SE';
			return $geolocation;
		};

		add_filter( 'pre_http_request', $trap, 10, 3 );
		add_filter( 'woocommerce_get_geolocation', $geolocation_filter, 10, 2 );

		( new WooCommerceProvider() )->resolve( '8.8.8.8' );

		remove_filter( 'pre_http_request', $trap, 10 );
		remove_filter( 'woocommerce_get_geolocation', $geolocation_filter, 10 );

		$this->assertFalse( $called );
	}

	// ---- Self-guard against private IPs (already unit-tested; confirmed here too) -----------

	public function test_resolve_self_guards_a_private_ip_without_calling_woocommerce(): void {
		$filter = function () {
			$this->fail( 'woocommerce_get_geolocation must not fire for a private IP.' );
		};

		add_filter( 'woocommerce_get_geolocation', $filter, 10, 2 );
		$result = ( new WooCommerceProvider() )->resolve( '10.0.0.5' );
		remove_filter( 'woocommerce_get_geolocation', $filter, 10 );

		$this->assertNull( $result );
	}
}
