<?php
/**
 * Unit tests for UniversalGeo\Resolver\ContextResolver.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Resolver;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Model\GeoCandidate;
use UniversalGeo\Model\ResolvedClientIp;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Tests\Unit\Doubles\FakeClientIpResolver;
use UniversalGeo\Tests\Unit\Doubles\TrackingGeoProvider;

/**
 * Covers the complete Revision 3 ContextResolver contract: request-level
 * memoization, client-IP resolution, cache interaction, the provider loop,
 * GeoValidator-driven validation, centralized source/confidence assignment,
 * probe(), and reset(). No Plugin.php wiring, trusted-proxy behaviour, or
 * additional providers belong here.
 */
final class ContextResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['universal_geo_test_options']                = array();
		$GLOBALS['universal_geo_test_object_cache']           = array();
		$GLOBALS['universal_geo_test_object_cache_calls']     = array();
		$GLOBALS['universal_geo_test_using_ext_object_cache'] = true;
	}

	private function cache(): GeoCache {
		return new GeoCache( true, 900, 'sig' );
	}

	private function resolved_ip( string $ip = '203.0.113.1', bool $is_public = true ): ResolvedClientIp {
		return new ResolvedClientIp( $ip, 'REMOTE_ADDR', true, $is_public );
	}

	// ---- Class shape --------------------------------------------------------

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( ContextResolver::class ) )->isFinal() );
	}

	public function test_namespace_is_resolver(): void {
		$this->assertSame( 'UniversalGeo\Resolver', ( new ReflectionClass( ContextResolver::class ) )->getNamespaceName() );
	}

	public function test_constructor_signature(): void {
		$parameters = ( new ReflectionClass( ContextResolver::class ) )->getConstructor()->getParameters();

		$this->assertCount( 4, $parameters );
		$this->assertSame( 'client_ip_resolver', $parameters[0]->getName() );
		$this->assertSame( 'providers', $parameters[1]->getName() );
		$this->assertSame( 'array', (string) $parameters[1]->getType() );
		$this->assertSame( 'cache', $parameters[2]->getName() );
		$this->assertSame( 'UniversalGeo\Cache\GeoCache', (string) $parameters[2]->getType() );
		$this->assertSame( 'on_provider_failed', $parameters[3]->getName() );
		$this->assertSame( '?callable', (string) $parameters[3]->getType() );
		$this->assertTrue( $parameters[3]->isOptional() );
		$this->assertNull( $parameters[3]->getDefaultValue() );
	}

	public function test_public_api_is_exactly_resolve_probe_and_reset(): void {
		$names = array_values(
			array_diff(
				array_map(
					static fn( ReflectionMethod $method ) => $method->getName(),
					( new ReflectionClass( ContextResolver::class ) )->getMethods( ReflectionMethod::IS_PUBLIC )
				),
				array( '__construct' )
			)
		);

		sort( $names );
		$this->assertSame(
			array(
				'confidence_for_provider',
				'is_provider_available',
				'probe',
				'provider_chain',
				'reset',
				'resolve',
			),
			$names
		);
	}

	public function test_empty_provider_array_is_accepted(): void {
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array(), $this->cache() );
		$this->assertFalse( $resolver->resolve()->is_known() );
	}

	public function test_invalid_provider_element_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new ContextResolver( new FakeClientIpResolver(), array( 'not-a-provider' ), $this->cache() );
	}

	public function test_associative_provider_array_is_reindexed_but_order_preserved(): void {
		$b = new TrackingGeoProvider( 'b', true, new GeoCandidate( 'DE', null ) );
		$a = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SE', null ) );

		// 'first' => $b, 'second' => $a — array order (b then a) must win.
		$resolver = new ContextResolver(
			new FakeClientIpResolver( $this->resolved_ip() ),
			array(
				'first'  => $b,
				'second' => $a,
			),
			$this->cache()
		);

		$this->assertSame( 'DE', $resolver->resolve()->country_code );
	}

	// ---- Memoization --------------------------------------------------------

	public function test_second_resolve_returns_the_identical_instance(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$this->assertSame( $resolver->resolve(), $resolver->resolve() );
	}

	public function test_client_ip_resolver_called_exactly_once_across_repeated_resolve(): void {
		$client_resolver = new FakeClientIpResolver( $this->resolved_ip() );
		$provider        = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver        = new ContextResolver( $client_resolver, array( $provider ), $this->cache() );

		$resolver->resolve();
		$resolver->resolve();
		$resolver->resolve();

		$this->assertSame( 1, $client_resolver->calls );
	}

	public function test_provider_called_exactly_once_across_repeated_resolve(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$resolver->resolve();
		$resolver->resolve();

		$this->assertSame( 1, $provider->resolve_calls );
	}

	public function test_cache_called_exactly_once_across_repeated_resolve(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$resolver->resolve();
		$resolver->resolve();

		$this->assertCount( 1, $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	public function test_unknown_result_is_also_memoized(): void {
		$resolver = new ContextResolver( new FakeClientIpResolver( null ), array(), $this->cache() );

		$this->assertSame( $resolver->resolve(), $resolver->resolve() );
	}

	public function test_reset_clears_the_memo_so_the_client_resolver_is_called_again(): void {
		$client_resolver = new FakeClientIpResolver( $this->resolved_ip() );
		$provider        = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver        = new ContextResolver( $client_resolver, array( $provider ), $this->cache() );

		$resolver->resolve();
		$resolver->reset();
		$resolver->resolve();

		$this->assertSame( 2, $client_resolver->calls );
	}

	public function test_reset_does_not_flush_the_cache(): void {
		$client_resolver = new FakeClientIpResolver( $this->resolved_ip() );
		$provider        = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver        = new ContextResolver( $client_resolver, array( $provider ), $this->cache() );

		$resolver->resolve();
		$resolver->reset();
		$second = $resolver->resolve();

		// The second resolution hit the surviving cache entry rather than
		// re-running the provider.
		$this->assertSame( 1, $provider->resolve_calls );
		$this->assertTrue( $second->is_cached );
	}

	public function test_resolve_after_reset_is_not_the_prior_memoized_instance(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$first = $resolver->resolve();
		$resolver->reset();
		$second = $resolver->resolve();

		$this->assertNotSame( $first, $second );
	}

	// ---- Client IP ------------------------------------------------------------

	public function test_null_client_ip_returns_unknown(): void {
		$resolver = new ContextResolver( new FakeClientIpResolver( null ), array(), $this->cache() );
		$this->assertFalse( $resolver->resolve()->is_known() );
	}

	public function test_null_client_ip_does_not_invoke_providers(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( null ), array( $provider ), $this->cache() );

		$resolver->resolve();
		$this->assertSame( 0, $provider->resolve_calls );
	}

	public function test_null_client_ip_does_not_invoke_the_cache(): void {
		$resolver = new ContextResolver( new FakeClientIpResolver( null ), array(), $this->cache() );
		$resolver->resolve();

		$this->assertSame( array(), $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	public function test_valid_ipv4_resolves(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip( '203.0.113.5' ) ), array( $provider ), $this->cache() );

		$this->assertSame( 'SE', $resolver->resolve()->country_code );
	}

	public function test_valid_ipv6_resolves(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip( '2001:db8::1' ) ), array( $provider ), $this->cache() );

		$this->assertSame( 'SE', $resolver->resolve()->country_code );
	}

	public function test_private_ip_still_invokes_providers(): void {
		// No GeoProviderInterface method carries an is_public flag; that
		// gate belongs to individual IP-based providers' own resolve()
		// implementations (once they exist), not to ContextResolver.
		// DefaultCountryProvider-shaped providers are IP-independent and
		// must remain usable regardless of the resolved IP's publicness.
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver(
			new FakeClientIpResolver( $this->resolved_ip( '10.0.0.5', false ) ),
			array( $provider ),
			$this->cache()
		);

		$this->assertSame( 'SE', $resolver->resolve()->country_code );
		$this->assertSame( 1, $provider->resolve_calls );
	}

	public function test_private_ip_still_uses_the_cache(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver(
			new FakeClientIpResolver( $this->resolved_ip( '10.0.0.5', false ) ),
			array( $provider ),
			$this->cache()
		);

		$resolver->resolve();
		$this->assertCount( 1, $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	// ---- Cache --------------------------------------------------------------

	public function test_cache_hit_is_returned_without_invoking_providers(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );

		// Warm the cache with a first resolver instance.
		( new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() ) )->resolve();
		$this->assertSame( 1, $provider->resolve_calls );

		// A fresh resolver instance, sharing the same fake storage, must hit cache.
		$second  = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );
		$context = $second->resolve();

		$this->assertSame( 1, $provider->resolve_calls );
		$this->assertTrue( $context->is_cached );
	}

	public function test_cache_round_trip_preserves_country_region_source_confidence(): void {
		$provider = new TrackingGeoProvider( 'cloudflare', true, new GeoCandidate( 'SE', 'AB' ) );

		( new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() ) )->resolve();

		$second  = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array(), $this->cache() );
		$context = $second->resolve();

		$this->assertSame( 'SE', $context->country_code );
		$this->assertSame( 'AB', $context->region_code );
		$this->assertSame( 'cloudflare', $context->source );
		$this->assertSame( 0.95, $context->confidence );
	}

	public function test_cache_miss_invokes_providers(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$resolver->resolve();
		$this->assertSame( 1, $provider->resolve_calls );
	}

	public function test_known_result_is_written_to_cache(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		( new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() ) )->resolve();

		$this->assertCount( 1, $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	public function test_unknown_result_is_also_written_to_cache(): void {
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array(), $this->cache() );
		$resolver->resolve();

		$this->assertCount( 1, $GLOBALS['universal_geo_test_object_cache_calls'] );

		$second  = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array(), $this->cache() );
		$context = $second->resolve();

		$this->assertFalse( $context->is_known() );
		$this->assertTrue( $context->is_cached );
	}

	public function test_only_one_cache_write_occurs_even_with_multiple_skipped_candidates(): void {
		$invalid = new TrackingGeoProvider( 'maxmind', true, new GeoCandidate( 'SWE', null ) );
		$valid   = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );

		( new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $invalid, $valid ), $this->cache() ) )->resolve();

		$this->assertCount( 1, $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	// ---- Provider order and loop ----------------------------------------------

	public function test_injected_order_wins_over_alphabetical_or_provider_order_bias(): void {
		// 'zzz' first in the injected array must win over 'aaa', even
		// though 'aaa' would sort first alphabetically or under any
		// PROVIDER_ORDER-style bias.
		$zzz = new TrackingGeoProvider( 'zzz', true, new GeoCandidate( 'DE', null ) );
		$aaa = new TrackingGeoProvider( 'aaa', true, new GeoCandidate( 'SE', null ) );

		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $zzz, $aaa ), $this->cache() );
		$this->assertSame( 'DE', $resolver->resolve()->country_code );
	}

	public function test_unavailable_provider_is_skipped(): void {
		$unavailable = new TrackingGeoProvider( 'a', false, new GeoCandidate( 'DE', null ) );
		$fallback    = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );

		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $unavailable, $fallback ), $this->cache() );
		$context  = $resolver->resolve();

		$this->assertSame( 'SE', $context->country_code );
		$this->assertSame( 0, $unavailable->resolve_calls );
	}

	public function test_null_returning_provider_is_skipped(): void {
		$miss     = new TrackingGeoProvider( 'a', true, null );
		$fallback = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );

		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $miss, $fallback ), $this->cache() );
		$this->assertSame( 'SE', $resolver->resolve()->country_code );
	}

	public function test_invalid_country_provider_is_skipped(): void {
		$invalid  = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SWE', null ) );
		$fallback = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );

		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $invalid, $fallback ), $this->cache() );
		$this->assertSame( 'SE', $resolver->resolve()->country_code );
	}

	public function test_throwing_provider_is_skipped(): void {
		$throwing = new TrackingGeoProvider( 'a', true, null, true );
		$fallback = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );

		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $throwing, $fallback ), $this->cache() );
		$this->assertSame( 'SE', $resolver->resolve()->country_code );
	}

	// ---- on_provider_failed callable -------------------------------------------

	public function test_on_provider_failed_is_optional(): void {
		// No 4th argument at all — must not throw or require one.
		$throwing = new TrackingGeoProvider( 'a', true, null, true );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $throwing ), $this->cache() );

		$this->assertFalse( $resolver->resolve()->is_known() );
	}

	public function test_on_provider_failed_is_invoked_from_resolve_with_the_provider_id_and_a_reason(): void {
		$throwing = new TrackingGeoProvider( 'a', true, null, true );
		$calls    = array();
		$resolver = new ContextResolver(
			new FakeClientIpResolver( $this->resolved_ip() ),
			array( $throwing ),
			$this->cache(),
			static function ( string $provider_id, string $reason ) use ( &$calls ) {
				$calls[] = array( $provider_id, $reason );
			}
		);

		$resolver->resolve();

		$this->assertCount( 1, $calls );
		$this->assertSame( 'a', $calls[0][0] );
		$this->assertStringContainsString( 'RuntimeException', $calls[0][1] );
		$this->assertStringContainsString( 'TrackingGeoProvider configured to throw.', $calls[0][1] );
	}

	public function test_on_provider_failed_is_not_invoked_for_a_non_throwing_miss(): void {
		$miss  = new TrackingGeoProvider( 'a', true, null );
		$calls = array();

		$resolver = new ContextResolver(
			new FakeClientIpResolver( $this->resolved_ip() ),
			array( $miss ),
			$this->cache(),
			static function () use ( &$calls ) {
				$calls[] = true;
			}
		);

		$resolver->resolve();

		$this->assertSame( array(), $calls );
	}

	public function test_on_provider_failed_is_invoked_once_per_throwing_provider_not_per_resolve_call(): void {
		$throwing = new TrackingGeoProvider( 'a', true, null, true );
		$calls    = 0;
		$resolver = new ContextResolver(
			new FakeClientIpResolver( $this->resolved_ip() ),
			array( $throwing ),
			$this->cache(),
			static function () use ( &$calls ) {
				++$calls;
			}
		);

		$resolver->resolve();
		$resolver->resolve(); // Memoized — provider not called again.

		$this->assertSame( 1, $calls );
	}

	public function test_on_provider_failed_is_invoked_from_probe(): void {
		$throwing = new TrackingGeoProvider( 'a', true, null, true );
		$calls    = array();
		$resolver = new ContextResolver(
			new FakeClientIpResolver(),
			array( $throwing ),
			$this->cache(),
			static function ( string $provider_id, string $reason ) use ( &$calls ) {
				$calls[] = array( $provider_id, $reason );
			}
		);

		$resolver->probe( '203.0.113.1' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'a', $calls[0][0] );
	}

	public function test_on_provider_failed_never_receives_the_ip(): void {
		$throwing = new TrackingGeoProvider( 'a', true, null, true );
		$calls    = array();
		$resolver = new ContextResolver(
			new FakeClientIpResolver( $this->resolved_ip( '203.0.113.77' ) ),
			array( $throwing ),
			$this->cache(),
			static function ( string $provider_id, string $reason ) use ( &$calls ) {
				$calls[] = $reason;
			}
		);

		$resolver->resolve();

		$this->assertStringNotContainsString( '203.0.113.77', $calls[0] );
	}

	public function test_all_providers_failing_returns_unknown(): void {
		$miss = new TrackingGeoProvider( 'a', true, null );
		$bad  = new TrackingGeoProvider( 'b', true, new GeoCandidate( 'SWE', null ) );
		$down = new TrackingGeoProvider( 'c', false, new GeoCandidate( 'SE', null ) );

		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $miss, $bad, $down ), $this->cache() );
		$this->assertFalse( $resolver->resolve()->is_known() );
	}

	public function test_no_provider_is_called_after_the_first_valid_candidate(): void {
		$first  = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SE', null ) );
		$second = new TrackingGeoProvider( 'b', true, new GeoCandidate( 'DE', null ) );

		( new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $first, $second ), $this->cache() ) )->resolve();

		$this->assertSame( 1, $first->resolve_calls );
		$this->assertSame( 0, $second->resolve_calls );
	}

	public function test_provider_receives_the_resolved_client_ip(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		( new ContextResolver( new FakeClientIpResolver( $this->resolved_ip( '198.51.100.7' ) ), array( $provider ), $this->cache() ) )->resolve();

		$this->assertSame( '198.51.100.7', $provider->last_resolve_ip );
	}

	// ---- Validation via GeoValidator -------------------------------------------

	public function test_lowercase_candidate_country_is_normalized(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'se', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$this->assertSame( 'SE', $resolver->resolve()->country_code );
	}

	public function test_whitespace_candidate_country_is_normalized(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( '  SE  ', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$this->assertSame( 'SE', $resolver->resolve()->country_code );
	}

	public function test_valid_region_is_normalized(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', 'ab' ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$this->assertSame( 'AB', $resolver->resolve()->region_code );
	}

	public function test_country_prefixed_region_is_stripped(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', 'SE-AB' ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$this->assertSame( 'AB', $resolver->resolve()->region_code );
	}

	public function test_invalid_region_becomes_null_without_rejecting_the_valid_country(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', 'TOOLONG' ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$context = $resolver->resolve();
		$this->assertSame( 'SE', $context->country_code );
		$this->assertNull( $context->region_code );
	}

	public function test_candidate_is_not_mutated(): void {
		$candidate = new GeoCandidate( 'se', 'ab' );
		$provider  = new TrackingGeoProvider( 'default', true, $candidate );
		( new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() ) )->resolve();

		$this->assertSame( 'se', $candidate->country_code );
		$this->assertSame( 'ab', $candidate->region_code );
	}

	// ---- Source and confidence --------------------------------------------------

	public function test_source_equals_the_winning_providers_id(): void {
		$provider = new TrackingGeoProvider( 'maxmind', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$this->assertSame( 'maxmind', $resolver->resolve()->source );
	}

	/**
	 * @dataProvider confidence_provider
	 */
	public function test_confidence_mapping( string $provider_id, float $expected_confidence ): void {
		$provider = new TrackingGeoProvider( $provider_id, true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$this->assertSame( $expected_confidence, $resolver->resolve()->confidence );
	}

	public function confidence_provider(): array {
		return array(
			'cloudflare'  => array( 'cloudflare', 0.95 ),
			'maxmind'     => array( 'maxmind', 0.90 ),
			'woocommerce' => array( 'woocommerce', 0.85 ),
			'remote'      => array( 'remote', 0.85 ),
			'default'     => array( 'default', 0.10 ),
		);
	}

	public function test_unlisted_provider_id_uses_the_unlisted_fallback_confidence(): void {
		$provider = new TrackingGeoProvider( 'third-party-provider', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$this->assertSame( 0.85, $resolver->resolve()->confidence );
	}

	// ---- probe() --------------------------------------------------------------

	public function test_probe_bypasses_the_memo(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$resolver->resolve();
		$this->assertSame( 1, $provider->resolve_calls );

		$resolver->probe( '203.0.113.1' );
		$this->assertSame( 2, $provider->resolve_calls );
	}

	public function test_probe_bypasses_the_cache(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );

		( new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() ) )->resolve();
		$this->assertSame( 1, $provider->resolve_calls );

		$second = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );
		$second->probe( '203.0.113.1' );

		$this->assertSame( 2, $provider->resolve_calls );
	}

	public function test_probe_visits_every_provider_even_after_one_succeeds(): void {
		$first    = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SE', null ) );
		$second   = new TrackingGeoProvider( 'b', true, new GeoCandidate( 'DE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver(), array( $first, $second ), $this->cache() );

		$resolver->probe( '203.0.113.1' );

		$this->assertSame( 1, $first->resolve_calls );
		$this->assertSame( 1, $second->resolve_calls );
	}

	public function test_probe_records_unavailable_provider(): void {
		$provider = new TrackingGeoProvider( 'a', false );
		$resolver = new ContextResolver( new FakeClientIpResolver(), array( $provider ), $this->cache() );

		$results = $resolver->probe( '203.0.113.1' );

		$this->assertSame(
			array(
				'provider'     => 'a',
				'available'    => false,
				'country_code' => null,
				'region_code'  => null,
				'reason'       => 'unavailable',
			),
			$results[0]
		);
	}

	public function test_probe_records_null_candidate_as_miss(): void {
		$provider = new TrackingGeoProvider( 'a', true, null );
		$resolver = new ContextResolver( new FakeClientIpResolver(), array( $provider ), $this->cache() );

		$this->assertSame( 'miss', $resolver->probe( '203.0.113.1' )[0]['reason'] );
	}

	public function test_probe_records_invalid_country_with_the_raw_value(): void {
		$provider = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SWE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver(), array( $provider ), $this->cache() );

		$result = $resolver->probe( '203.0.113.1' )[0];
		$this->assertSame( 'invalid_country', $result['reason'] );
		$this->assertSame( 'SWE', $result['country_code'] );
	}

	public function test_probe_records_a_valid_result(): void {
		$provider = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'se', 'ab' ) );
		$resolver = new ContextResolver( new FakeClientIpResolver(), array( $provider ), $this->cache() );

		$result = $resolver->probe( '203.0.113.1' )[0];
		$this->assertSame( 'ok', $result['reason'] );
		$this->assertSame( 'SE', $result['country_code'] );
		$this->assertSame( 'AB', $result['region_code'] );
	}

	public function test_probe_records_a_throwing_provider_as_failed(): void {
		$provider = new TrackingGeoProvider( 'a', true, null, true );
		$resolver = new ContextResolver( new FakeClientIpResolver(), array( $provider ), $this->cache() );

		$this->assertSame( 'failed', $resolver->probe( '203.0.113.1' )[0]['reason'] );
	}

	public function test_probe_with_explicit_ip_does_not_consult_the_client_ip_resolver(): void {
		$client_resolver = new FakeClientIpResolver( $this->resolved_ip() );
		$resolver        = new ContextResolver( $client_resolver, array(), $this->cache() );

		$resolver->probe( '198.51.100.9' );
		$this->assertSame( 0, $client_resolver->calls );
	}

	public function test_probe_with_null_ip_consults_the_client_ip_resolver(): void {
		$client_resolver = new FakeClientIpResolver( $this->resolved_ip() );
		$provider        = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SE', null ) );
		$resolver        = new ContextResolver( $client_resolver, array( $provider ), $this->cache() );

		$resolver->probe();
		$this->assertSame( 1, $client_resolver->calls );
	}

	public function test_probe_with_malformed_explicit_ip_visits_no_provider(): void {
		$provider = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver(), array( $provider ), $this->cache() );

		$this->assertSame( array(), $resolver->probe( 'not-an-ip' ) );
		$this->assertSame( 0, $provider->resolve_calls );
	}

	public function test_probe_with_no_usable_ip_at_all_returns_empty_array(): void {
		$resolver = new ContextResolver( new FakeClientIpResolver( null ), array(), $this->cache() );
		$this->assertSame( array(), $resolver->probe() );
	}

	public function test_probe_does_not_alter_a_later_resolve_result(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver( $this->resolved_ip() ), array( $provider ), $this->cache() );

		$resolver->probe( '198.51.100.9' );
		$context = $resolver->resolve();

		$this->assertSame( 'SE', $context->country_code );
		$this->assertFalse( $context->is_cached );
	}

	public function test_probe_does_not_write_to_the_cache(): void {
		$provider = new TrackingGeoProvider( 'default', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver( new FakeClientIpResolver(), array( $provider ), $this->cache() );

		$resolver->probe( '203.0.113.1' );
		$this->assertSame( array(), $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	public function test_probe_result_is_deterministic(): void {
		$provider = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SE', 'AB' ) );
		$resolver = new ContextResolver( new FakeClientIpResolver(), array( $provider ), $this->cache() );

		$this->assertSame( $resolver->probe( '203.0.113.1' ), $resolver->probe( '203.0.113.1' ) );
	}

	public function test_probe_result_does_not_contain_the_raw_ip(): void {
		$provider = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SE', 'AB' ) );
		$resolver = new ContextResolver( new FakeClientIpResolver(), array( $provider ), $this->cache() );

		$result = $resolver->probe( '203.0.113.1' )[0];

		foreach ( $result as $value ) {
			if ( is_string( $value ) ) {
				$this->assertNotSame( '203.0.113.1', $value );
			}
		}
	}
}
