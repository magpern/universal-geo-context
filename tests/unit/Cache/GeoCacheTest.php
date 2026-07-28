<?php
/**
 * Unit tests for UniversalGeo\Cache\GeoCache.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Model\VisitorContext;

/**
 * Covers the complete Revision 3 §9 GeoCache contract: key privacy, enabled
 * / no-op degradation, malformed-entry handling, negative-result TTL, and
 * the is_cached true-on-hit / false-in-storage split. No ContextResolver,
 * provider iteration, or request memoization concerns belong here.
 */
final class GeoCacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['universal_geo_test_options']                = array();
		$GLOBALS['universal_geo_test_object_cache']           = array();
		$GLOBALS['universal_geo_test_object_cache_calls']     = array();
		$GLOBALS['universal_geo_test_using_ext_object_cache'] = true;
	}

	private function known_context(): VisitorContext {
		return new VisitorContext( 'SE', 'AB', 'maxmind', 0.9 );
	}

	// ---- Class shape --------------------------------------------------------

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( GeoCache::class ) )->isFinal() );
	}

	public function test_namespace_is_cache(): void {
		$this->assertSame( 'UniversalGeo\Cache', ( new ReflectionClass( GeoCache::class ) )->getNamespaceName() );
	}

	public function test_constructor_takes_exactly_enabled_ttl_and_config_sig(): void {
		$parameters = ( new ReflectionClass( GeoCache::class ) )->getConstructor()->getParameters();

		$this->assertCount( 3, $parameters );
		$this->assertSame( 'enabled', $parameters[0]->getName() );
		$this->assertSame( 'bool', (string) $parameters[0]->getType() );
		$this->assertSame( 'ttl_seconds', $parameters[1]->getName() );
		$this->assertSame( 'int', (string) $parameters[1]->getType() );
		$this->assertSame( 'config_sig', $parameters[2]->getName() );
		$this->assertSame( 'string', (string) $parameters[2]->getType() );
	}

	public function test_public_api_is_exactly_get_set_and_bump_epoch(): void {
		$names = array_values(
			array_diff(
				array_map(
					static fn( ReflectionMethod $method ) => $method->getName(),
					( new ReflectionClass( GeoCache::class ) )->getMethods( ReflectionMethod::IS_PUBLIC )
				),
				array( '__construct' )
			)
		);

		sort( $names );
		$this->assertSame( array( 'bump_epoch', 'get', 'set' ), $names );
	}

	// ---- Enabled / disabled / no-op degradation ------------------------------

	public function test_disabled_by_setting_never_calls_the_object_cache(): void {
		$cache = new GeoCache( false, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$this->assertSame( array(), $GLOBALS['universal_geo_test_object_cache_calls'] );
		$this->assertNull( $cache->get( '203.0.113.1' ) );
	}

	public function test_disabled_by_missing_persistent_object_cache_never_calls_the_object_cache(): void {
		$GLOBALS['universal_geo_test_using_ext_object_cache'] = false;

		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$this->assertSame( array(), $GLOBALS['universal_geo_test_object_cache_calls'] );
		$this->assertNull( $cache->get( '203.0.113.1' ) );
	}

	public function test_disabled_cache_does_not_touch_salt_or_epoch_options(): void {
		$cache = new GeoCache( false, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );
		$cache->get( '203.0.113.1' );

		$this->assertSame( array(), $GLOBALS['universal_geo_test_options'] );
	}

	public function test_enabled_with_persistent_object_cache_performs_a_real_write(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$this->assertCount( 1, $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	// ---- Key privacy ----------------------------------------------------------

	public function test_key_never_contains_the_raw_ipv4_address(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.42', $this->known_context() );

		$key = $GLOBALS['universal_geo_test_object_cache_calls'][0]['key'];
		$this->assertStringNotContainsString( '203.0.113.42', $key );
	}

	public function test_key_never_contains_the_raw_ipv6_address(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '2001:db8::1234', $this->known_context() );

		$key = $GLOBALS['universal_geo_test_object_cache_calls'][0]['key'];
		$this->assertStringNotContainsString( '2001:db8::1234', $key );
	}

	public function test_key_matches_the_epoch_config_sig_ip_hash_format(): void {
		$GLOBALS['universal_geo_test_options']['universal_geo_cache_epoch'] = 7;

		$cache = new GeoCache( true, 900, 'my-sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$key = $GLOBALS['universal_geo_test_object_cache_calls'][0]['key'];
		$this->assertMatchesRegularExpression( '/^7:my-sig:ip:[0-9a-f]{32}$/', $key );
	}

	public function test_same_inputs_produce_the_same_key(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );
		$cache->set( '203.0.113.1', $this->known_context() );

		$calls = $GLOBALS['universal_geo_test_object_cache_calls'];
		$this->assertSame( $calls[0]['key'], $calls[1]['key'] );
	}

	public function test_different_ips_produce_different_keys(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );
		$cache->set( '198.51.100.1', $this->known_context() );

		$calls = $GLOBALS['universal_geo_test_object_cache_calls'];
		$this->assertNotSame( $calls[0]['key'], $calls[1]['key'] );
	}

	public function test_different_config_sig_produces_a_different_key(): void {
		( new GeoCache( true, 900, 'sig-a' ) )->set( '203.0.113.1', $this->known_context() );
		( new GeoCache( true, 900, 'sig-b' ) )->set( '203.0.113.1', $this->known_context() );

		$calls = $GLOBALS['universal_geo_test_object_cache_calls'];
		$this->assertNotSame( $calls[0]['key'], $calls[1]['key'] );
	}

	public function test_different_epoch_produces_a_different_key(): void {
		$cache = new GeoCache( true, 900, 'sig' );

		$GLOBALS['universal_geo_test_options']['universal_geo_cache_epoch'] = 1;
		$cache->set( '203.0.113.1', $this->known_context() );

		$GLOBALS['universal_geo_test_options']['universal_geo_cache_epoch'] = 2;
		$cache->set( '203.0.113.1', $this->known_context() );

		$calls = $GLOBALS['universal_geo_test_object_cache_calls'];
		$this->assertNotSame( $calls[0]['key'], $calls[1]['key'] );
	}

	public function test_different_salt_produces_a_different_key(): void {
		$cache = new GeoCache( true, 900, 'sig' );

		$GLOBALS['universal_geo_test_options']['universal_geo_cache_salt'] = 'salt-one';
		$cache->set( '203.0.113.1', $this->known_context() );

		$GLOBALS['universal_geo_test_options']['universal_geo_cache_salt'] = 'salt-two';
		$cache->set( '203.0.113.1', $this->known_context() );

		$calls = $GLOBALS['universal_geo_test_object_cache_calls'];
		$this->assertNotSame( $calls[0]['key'], $calls[1]['key'] );
	}

	public function test_salt_is_generated_and_persisted_on_first_use(): void {
		$this->assertArrayNotHasKey( 'universal_geo_cache_salt', $GLOBALS['universal_geo_test_options'] );

		( new GeoCache( true, 900, 'sig' ) )->set( '203.0.113.1', $this->known_context() );

		$this->assertArrayHasKey( 'universal_geo_cache_salt', $GLOBALS['universal_geo_test_options'] );
		$this->assertNotSame( '', $GLOBALS['universal_geo_test_options']['universal_geo_cache_salt'] );
	}

	public function test_salt_is_reused_once_generated_not_regenerated(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );
		$first_salt = $GLOBALS['universal_geo_test_options']['universal_geo_cache_salt'];

		$cache->set( '198.51.100.1', $this->known_context() );
		$this->assertSame( $first_salt, $GLOBALS['universal_geo_test_options']['universal_geo_cache_salt'] );
	}

	public function test_geo_cache_never_advances_the_epoch(): void {
		$GLOBALS['universal_geo_test_options']['universal_geo_cache_epoch'] = 5;

		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );
		$cache->get( '203.0.113.1' );

		$this->assertSame( 5, $GLOBALS['universal_geo_test_options']['universal_geo_cache_epoch'] );
	}

	// ---- Reads ------------------------------------------------------------

	public function test_miss_returns_null(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$this->assertNull( $cache->get( '203.0.113.1' ) );
	}

	public function test_hit_reconstructs_the_stored_context(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$result = $cache->get( '203.0.113.1' );

		$this->assertInstanceOf( VisitorContext::class, $result );
		$this->assertSame( 'SE', $result->country_code );
		$this->assertSame( 'AB', $result->region_code );
		$this->assertSame( 'maxmind', $result->source );
		$this->assertSame( 0.9, $result->confidence );
	}

	public function test_hit_returns_is_cached_true(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$this->assertTrue( $cache->get( '203.0.113.1' )->is_cached );
	}

	public function test_malformed_non_array_payload_is_a_miss(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$key = $GLOBALS['universal_geo_test_object_cache_calls'][0]['key'];
		$GLOBALS['universal_geo_test_object_cache'][ 'universal_geo|' . $key ] = 'not-an-array';

		$this->assertNull( $cache->get( '203.0.113.1' ) );
	}

	public function test_unsupported_schema_version_is_a_miss(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$key                       = $GLOBALS['universal_geo_test_object_cache_calls'][0]['key'];
		$payload                   = $GLOBALS['universal_geo_test_object_cache'][ 'universal_geo|' . $key ];
		$payload['schema_version'] = 999;
		$GLOBALS['universal_geo_test_object_cache'][ 'universal_geo|' . $key ] = $payload;

		$this->assertNull( $cache->get( '203.0.113.1' ) );
	}

	public function test_missing_schema_version_is_a_miss(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$key     = $GLOBALS['universal_geo_test_object_cache_calls'][0]['key'];
		$payload = $GLOBALS['universal_geo_test_object_cache'][ 'universal_geo|' . $key ];
		unset( $payload['schema_version'] );
		$GLOBALS['universal_geo_test_object_cache'][ 'universal_geo|' . $key ] = $payload;

		$this->assertNull( $cache->get( '203.0.113.1' ) );
	}

	public function test_invalid_country_in_payload_falls_back_via_from_array_tolerance(): void {
		// VisitorContext::from_array() is already tolerant (Step 1B); a
		// malformed country degrades to unknown rather than throwing or
		// producing a wrong-but-plausible-looking VisitorContext.
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$key                     = $GLOBALS['universal_geo_test_object_cache_calls'][0]['key'];
		$payload                 = $GLOBALS['universal_geo_test_object_cache'][ 'universal_geo|' . $key ];
		$payload['country_code'] = 'SWE';
		$GLOBALS['universal_geo_test_object_cache'][ 'universal_geo|' . $key ] = $payload;

		$result = $cache->get( '203.0.113.1' );
		$this->assertNull( $result->country_code );
	}

	// ---- Writes -------------------------------------------------------------

	public function test_stored_payload_never_has_is_cached_true(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$key     = $GLOBALS['universal_geo_test_object_cache_calls'][0]['key'];
		$payload = $GLOBALS['universal_geo_test_object_cache'][ 'universal_geo|' . $key ];

		$this->assertFalse( $payload['is_cached'] );
	}

	public function test_stored_payload_is_false_even_when_context_was_already_marked_cached(): void {
		$cache          = new GeoCache( true, 900, 'sig' );
		$already_cached = $this->known_context()->with_cached( true );
		$cache->set( '203.0.113.1', $already_cached );

		$key     = $GLOBALS['universal_geo_test_object_cache_calls'][0]['key'];
		$payload = $GLOBALS['universal_geo_test_object_cache'][ 'universal_geo|' . $key ];

		$this->assertFalse( $payload['is_cached'] );
	}

	public function test_known_context_uses_the_configured_ttl(): void {
		$cache = new GeoCache( true, 1234, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$this->assertSame( 1234, $GLOBALS['universal_geo_test_object_cache_calls'][0]['expire'] );
	}

	public function test_unknown_context_uses_the_fixed_negative_ttl_of_300(): void {
		$cache = new GeoCache( true, 1234, 'sig' );
		$cache->set( '203.0.113.1', VisitorContext::unknown() );

		$this->assertSame( 300, $GLOBALS['universal_geo_test_object_cache_calls'][0]['expire'] );
	}

	public function test_unknown_context_is_still_cached(): void {
		// Negative caching: a failing provider chain is not retried on
		// every request (Revision 3 §9).
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', VisitorContext::unknown() );

		$result = $cache->get( '203.0.113.1' );
		$this->assertInstanceOf( VisitorContext::class, $result );
		$this->assertFalse( $result->is_known() );
	}

	public function test_default_country_low_confidence_context_uses_the_normal_ttl(): void {
		$cache   = new GeoCache( true, 900, 'sig' );
		$context = new VisitorContext( 'SE', null, 'default', 0.10 );
		$cache->set( '203.0.113.1', $context );

		$this->assertSame( 900, $GLOBALS['universal_geo_test_object_cache_calls'][0]['expire'] );
	}

	public function test_uses_the_cache_group_universal_geo(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$this->assertSame( 'universal_geo', $GLOBALS['universal_geo_test_object_cache_calls'][0]['group'] );
	}

	// ---- Round trip -------------------------------------------------------

	public function test_round_trip_ipv4_country_only(): void {
		$cache   = new GeoCache( true, 900, 'sig' );
		$context = new VisitorContext( 'DE', null, 'maxmind', 0.90 );
		$cache->set( '203.0.113.1', $context );

		$result = $cache->get( '203.0.113.1' );
		$this->assertSame( 'DE', $result->country_code );
		$this->assertNull( $result->region_code );
		$this->assertSame( 'maxmind', $result->source );
		$this->assertSame( 0.90, $result->confidence );
	}

	public function test_round_trip_ipv6_country_and_region(): void {
		$cache   = new GeoCache( true, 900, 'sig' );
		$context = new VisitorContext( 'SE', 'AB', 'cloudflare', 0.95 );
		$cache->set( '2001:db8::1', $context );

		$result = $cache->get( '2001:db8::1' );
		$this->assertSame( 'SE', $result->country_code );
		$this->assertSame( 'AB', $result->region_code );
		$this->assertSame( 'cloudflare', $result->source );
		$this->assertSame( 0.95, $result->confidence );
	}

	// ---- bump_epoch() ---------------------------------------------------------

	public function test_bump_epoch_increments_from_the_default_when_unset(): void {
		// DEFAULT_EPOCH (1) is the implicit baseline before any bump has
		// ever happened; bumping once moves past it to 2.
		GeoCache::bump_epoch();
		$this->assertSame( 2, $GLOBALS['universal_geo_test_options']['universal_geo_cache_epoch'] );
	}

	public function test_bump_epoch_increments_an_existing_value(): void {
		$GLOBALS['universal_geo_test_options']['universal_geo_cache_epoch'] = 5;

		GeoCache::bump_epoch();

		$this->assertSame( 6, $GLOBALS['universal_geo_test_options']['universal_geo_cache_epoch'] );
	}

	public function test_bump_epoch_called_twice_increments_twice(): void {
		GeoCache::bump_epoch();
		GeoCache::bump_epoch();

		$this->assertSame( 3, $GLOBALS['universal_geo_test_options']['universal_geo_cache_epoch'] );
	}

	public function test_bump_epoch_invalidates_previously_cached_entries(): void {
		$cache = new GeoCache( true, 900, 'sig' );
		$cache->set( '203.0.113.1', $this->known_context() );

		$this->assertNotNull( $cache->get( '203.0.113.1' ) );

		GeoCache::bump_epoch();

		$this->assertNull( $cache->get( '203.0.113.1' ) );
	}

	public function test_bump_epoch_treats_a_non_int_existing_value_as_the_default(): void {
		$GLOBALS['universal_geo_test_options']['universal_geo_cache_epoch'] = 'not-an-int';

		GeoCache::bump_epoch();

		$this->assertSame( 2, $GLOBALS['universal_geo_test_options']['universal_geo_cache_epoch'] );
	}

	// ---- Isolation ----------------------------------------------------------

	public function test_fake_storage_does_not_leak_between_tests(): void {
		// If a previous test's cached entry leaked, this would be a hit,
		// not a miss — even though a prior test's generated salt is
		// irrelevant here (get() legitimately (re)generates its own via
		// setUp()'s reset options store, since key-building needs a salt
		// even for a lookup that will miss).
		$cache = new GeoCache( true, 900, 'sig' );
		$this->assertNull( $cache->get( '203.0.113.1' ) );
		$this->assertSame( array(), $GLOBALS['universal_geo_test_object_cache'] );
	}
}
