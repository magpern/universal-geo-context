<?php
/**
 * Unit tests for UniversalGeo\Providers\WooCommerceProvider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Providers\WooCommerceProvider;

/**
 * Covers exactly the subset of WooCommerceProvider's contract that holds
 * true regardless of whether WooCommerce is loaded: get_id(), is_available()
 * correctly reporting false when WC_Geolocation does not exist (true in
 * this WordPress-free unit environment, per tests/unit/bootstrap.php), the
 * private static re-entrancy guard's existence, and self-guarding a
 * non-public IP without ever reaching WC_Geolocation.
 *
 * A real WC_Geolocation round-trip, the re-entrancy guard actually firing
 * under WooCommerce's own woocommerce_geolocate_ip filter, and the
 * zero-outbound-HTTP proof (pre_http_request trap) are integration
 * concerns (Revision 3 §16) — WC_Geolocation is a WooCommerce class this
 * WordPress-free suite must never load or fake; those live in
 * tests/integration/Providers/WooCommerceProviderTest.php.
 */
final class WooCommerceProviderTest extends TestCase {

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( WooCommerceProvider::class ) )->isFinal() );
	}

	public function test_get_id_is_woocommerce(): void {
		$this->assertSame( 'woocommerce', ( new WooCommerceProvider() )->get_id() );
	}

	public function test_is_available_is_false_without_woocommerce_loaded(): void {
		$this->assertFalse( class_exists( 'WC_Geolocation' ) );
		$this->assertFalse( ( new WooCommerceProvider() )->is_available() );
	}

	public function test_resolve_self_guards_a_private_ip_without_touching_wc_geolocation(): void {
		// Because WC_Geolocation does not exist in this environment, any
		// attempt to call it would fatal — a private IP must short-circuit
		// before that call is ever reached.
		$this->assertNull( ( new WooCommerceProvider() )->resolve( '10.0.0.5' ) );
	}

	public function test_resolve_self_guards_a_loopback_ip(): void {
		$this->assertNull( ( new WooCommerceProvider() )->resolve( '127.0.0.1' ) );
	}

	public function test_resolve_self_guards_a_link_local_ip(): void {
		$this->assertNull( ( new WooCommerceProvider() )->resolve( '169.254.1.1' ) );
	}

	public function test_class_has_a_private_static_in_flight_guard(): void {
		$reflection = new ReflectionClass( WooCommerceProvider::class );

		$this->assertTrue( $reflection->hasProperty( 'in_flight' ) );

		$property = $reflection->getProperty( 'in_flight' );
		$this->assertTrue( $property->isStatic() );
		$this->assertTrue( $property->isPrivate() );
	}
}
