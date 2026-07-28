<?php
/**
 * Unit tests for UniversalGeo\Providers\CloudflareHeaderProvider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\Providers\CloudflareHeaderProvider;
use UniversalGeo\Tests\Support\ServerRequestFactory;

/**
 * Covers CloudflareHeaderProvider entirely without WordPress or Cloudflare
 * itself: is_available() delegation to ClientIpResolver::cloudflare_header_trusted(),
 * header extraction, and the $ip-ignored / region-always-null contract.
 * XX/T1/EU/AP rejection is GeoValidator's job (exercised via ContextResolver
 * integration, not duplicated here) — this provider hands over whatever the
 * header contains, unfiltered.
 */
final class CloudflareHeaderProviderTest extends TestCase {

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( CloudflareHeaderProvider::class ) )->isFinal() );
	}

	public function test_get_id_is_cloudflare(): void {
		$request  = ServerRequestFactory::make();
		$resolver = new ClientIpResolver( $request, new TrustedProxies( array(), false ) );

		$this->assertSame( 'cloudflare', ( new CloudflareHeaderProvider( $request, $resolver ) )->get_id() );
	}

	// ---- is_available() ------------------------------------------------------------

	public function test_is_available_true_when_trust_verdict_is_true(): void {
		$request  = ServerRequestFactory::make( '173.245.48.1' );
		$resolver = new ClientIpResolver( $request, new TrustedProxies( array(), true ) );

		$this->assertTrue( ( new CloudflareHeaderProvider( $request, $resolver ) )->is_available() );
	}

	public function test_is_available_false_when_preset_disabled(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5' );
		$resolver = new ClientIpResolver( $request, new TrustedProxies( array( '172.18.0.0/16' ), false ) );

		$this->assertFalse( ( new CloudflareHeaderProvider( $request, $resolver ) )->is_available() );
	}

	public function test_is_available_false_when_peer_untrusted(): void {
		$request  = ServerRequestFactory::make( '198.51.100.9' );
		$resolver = new ClientIpResolver( $request, new TrustedProxies( array(), true ) );

		$this->assertFalse( ( new CloudflareHeaderProvider( $request, $resolver ) )->is_available() );
	}

	public function test_is_available_true_in_chained_mode(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5' );
		$resolver = new ClientIpResolver( $request, new TrustedProxies( array( '172.18.0.0/16' ), true ) );

		$this->assertTrue( ( new CloudflareHeaderProvider( $request, $resolver ) )->is_available() );
	}

	// ---- resolve() ------------------------------------------------------------------

	public function test_resolve_returns_the_header_value_as_a_candidate(): void {
		$request  = ServerRequestFactory::make( '173.245.48.1', array( 'CF-IPCountry' => 'SE' ) );
		$resolver = new ClientIpResolver( $request, new TrustedProxies( array(), true ) );

		$candidate = ( new CloudflareHeaderProvider( $request, $resolver ) )->resolve( '203.0.113.1' );

		$this->assertSame( 'SE', $candidate->country_code );
	}

	public function test_resolve_region_is_always_null(): void {
		$request  = ServerRequestFactory::make( '173.245.48.1', array( 'CF-IPCountry' => 'SE' ) );
		$resolver = new ClientIpResolver( $request, new TrustedProxies( array(), true ) );

		$candidate = ( new CloudflareHeaderProvider( $request, $resolver ) )->resolve( '203.0.113.1' );

		$this->assertNull( $candidate->region_code );
	}

	public function test_resolve_returns_null_when_header_absent(): void {
		$request  = ServerRequestFactory::make( '173.245.48.1' );
		$resolver = new ClientIpResolver( $request, new TrustedProxies( array(), true ) );

		$this->assertNull( ( new CloudflareHeaderProvider( $request, $resolver ) )->resolve( '203.0.113.1' ) );
	}

	public function test_resolve_ignores_the_ip_argument(): void {
		$request  = ServerRequestFactory::make( '173.245.48.1', array( 'CF-IPCountry' => 'DE' ) );
		$resolver = new ClientIpResolver( $request, new TrustedProxies( array(), true ) );
		$provider = new CloudflareHeaderProvider( $request, $resolver );

		$this->assertSame( 'DE', $provider->resolve( '1.2.3.4' )->country_code );
		$this->assertSame( 'DE', $provider->resolve( '9.9.9.9' )->country_code );
	}

	public function test_resolve_hands_over_the_sentinel_value_unfiltered(): void {
		// GeoValidator, applied by ContextResolver's loop, is the single
		// source of truth for rejecting XX/T1/EU/AP — this provider must
		// not duplicate that list, so the raw sentinel passes through here.
		$request  = ServerRequestFactory::make( '173.245.48.1', array( 'CF-IPCountry' => 'XX' ) );
		$resolver = new ClientIpResolver( $request, new TrustedProxies( array(), true ) );

		$candidate = ( new CloudflareHeaderProvider( $request, $resolver ) )->resolve( '203.0.113.1' );

		$this->assertSame( 'XX', $candidate->country_code );
	}
}
