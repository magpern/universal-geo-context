<?php
/**
 * Derived-cache interaction tests for the remote provider (M4).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Providers\Remote;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Model\ResolvedClientIp;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Providers\Remote\ReferenceRemoteProvider;
use UniversalGeo\Providers\Remote\TransportResponse;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use UniversalGeo\Tests\Unit\Doubles\FakeClientIpResolver;

/**
 * Proves the frozen M4 cache-interaction guarantee: "one outbound attempt
 * per {IP, epoch, config_sig, TTL window}" — GeoCache remains the single
 * derived-result cache (no provider-local caching is added anywhere in
 * ReferenceRemoteProvider); a cache hit for the remote source returns
 * without ReferenceRemoteProvider or HttpTransport being consulted again at
 * all, across independently-constructed resolver instances, until the TTL
 * window ends or the config/epoch changes.
 */
final class RemoteProviderCacheInteractionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['universal_geo_test_options']                = array();
		$GLOBALS['universal_geo_test_object_cache']           = array();
		$GLOBALS['universal_geo_test_object_cache_calls']     = array();
		$GLOBALS['universal_geo_test_using_ext_object_cache'] = true;
	}

	private function resolved_ip(): ResolvedClientIp {
		return new ResolvedClientIp( '8.8.8.8', 'REMOTE_ADDR', false, true );
	}

	/**
	 * @return array{0: ContextResolver, 1: ReferenceRemoteProvider}
	 */
	private function make_resolver( FakeHttpTransport $transport, GeoCache $cache ): array {
		$provider = new ReferenceRemoteProvider( true, 'acct', 'key', 2, $transport, new CircuitBreaker() );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $cache );

		return array( $resolver, $provider );
	}

	private function successful_transport(): FakeHttpTransport {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 200, '{"country":{"iso_code":"US"}}' ) );

		return $transport;
	}

	// ---- A successful remote result is cached ------------------------------------

	public function test_a_successful_remote_result_is_cached(): void {
		$transport    = $this->successful_transport();
		[ $resolver ] = $this->make_resolver( $transport, new GeoCache( true, 900, 'sig' ) );

		$context = $resolver->resolve();

		$this->assertSame( 'US', $context->country_code );
		$this->assertSame( 'remote', $context->source );
		$this->assertFalse( $context->is_cached );
		$this->assertSame( 1, $transport->call_count() );
	}

	// ---- A new resolver instance for the same IP returns the cached context -----

	public function test_a_new_resolver_instance_returns_the_cached_remote_context_without_a_new_call(): void {
		$first_transport    = $this->successful_transport();
		[ $first_resolver ] = $this->make_resolver( $first_transport, new GeoCache( true, 900, 'sig' ) );
		$first_resolver->resolve();

		// A second, independently-constructed resolver — same config_sig,
		// same epoch — with a transport that has nothing queued at all: if
		// it were ever called, the FakeHttpTransport itself would throw.
		$second_transport    = new FakeHttpTransport();
		[ $second_resolver ] = $this->make_resolver( $second_transport, new GeoCache( true, 900, 'sig' ) );
		$context             = $second_resolver->resolve();

		$this->assertTrue( $context->is_cached );
		$this->assertSame( 'US', $context->country_code );
		$this->assertSame( 'remote', $context->source );
		$this->assertSame( 0, $second_transport->call_count() );
	}

	// ---- Repeated resolve() calls on one instance: the request-level memo -------

	public function test_repeated_resolve_calls_on_one_instance_make_only_one_transport_call(): void {
		$transport    = $this->successful_transport();
		[ $resolver ] = $this->make_resolver( $transport, new GeoCache( true, 900, 'sig' ) );

		$resolver->resolve();
		$resolver->resolve();
		$resolver->resolve();

		$this->assertSame( 1, $transport->call_count() );
	}

	// ---- TTL expiry: exactly one new attempt -------------------------------------

	public function test_ttl_expiry_produces_exactly_one_new_remote_attempt(): void {
		$first_transport    = $this->successful_transport();
		[ $first_resolver ] = $this->make_resolver( $first_transport, new GeoCache( true, 900, 'sig' ) );
		$first_resolver->resolve();
		$this->assertSame( 1, $first_transport->call_count() );

		// Simulates TTL expiry: the entry is no longer present in the
		// persistent object cache — indistinguishable, from GeoCache's own
		// perspective, from real TTL-based eviction by the object cache
		// backend.
		$GLOBALS['universal_geo_test_object_cache'] = array();

		$second_transport    = $this->successful_transport();
		[ $second_resolver ] = $this->make_resolver( $second_transport, new GeoCache( true, 900, 'sig' ) );
		$context             = $second_resolver->resolve();

		$this->assertFalse( $context->is_cached );
		$this->assertSame( 1, $second_transport->call_count() );
	}

	// ---- Epoch bump: exactly one new attempt -------------------------------------

	public function test_epoch_bump_produces_exactly_one_new_remote_attempt(): void {
		$first_transport    = $this->successful_transport();
		[ $first_resolver ] = $this->make_resolver( $first_transport, new GeoCache( true, 900, 'sig' ) );
		$first_resolver->resolve();

		GeoCache::bump_epoch();

		// Same config_sig string, but GeoCache reads the (now bumped) epoch
		// itself at key-construction time — no other change is needed to
		// prove the epoch, not just config_sig, invalidates the entry.
		$second_transport    = $this->successful_transport();
		[ $second_resolver ] = $this->make_resolver( $second_transport, new GeoCache( true, 900, 'sig' ) );
		$context             = $second_resolver->resolve();

		$this->assertFalse( $context->is_cached );
		$this->assertSame( 1, $second_transport->call_count() );
	}

	// ---- config_sig change: exactly one new attempt ------------------------------

	public function test_config_sig_change_produces_exactly_one_new_remote_attempt(): void {
		$first_transport    = $this->successful_transport();
		[ $first_resolver ] = $this->make_resolver( $first_transport, new GeoCache( true, 900, 'sig-a' ) );
		$first_resolver->resolve();

		$second_transport    = $this->successful_transport();
		[ $second_resolver ] = $this->make_resolver( $second_transport, new GeoCache( true, 900, 'sig-b' ) );
		$context             = $second_resolver->resolve();

		$this->assertFalse( $context->is_cached );
		$this->assertSame( 1, $second_transport->call_count() );
	}

	// ---- Cache disabled: every resolution is a fresh attempt ---------------------

	public function test_disabled_cache_makes_a_fresh_attempt_every_resolution(): void {
		$first_transport    = $this->successful_transport();
		[ $first_resolver ] = $this->make_resolver( $first_transport, new GeoCache( false, 900, 'sig' ) );
		$first_resolver->resolve();

		$second_transport    = $this->successful_transport();
		[ $second_resolver ] = $this->make_resolver( $second_transport, new GeoCache( false, 900, 'sig' ) );
		$context             = $second_resolver->resolve();

		$this->assertFalse( $context->is_cached );
		$this->assertSame( 1, $second_transport->call_count() );
	}
}
