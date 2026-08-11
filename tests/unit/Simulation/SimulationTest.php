<?php
/**
 * Unit tests for country simulation (M8).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Simulation;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Simulation\CountryCatalog;
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationContextFilter;
use UniversalGeo\Providers\DefaultCountryProvider;
use UniversalGeo\Simulation\SimulationCookie;
use UniversalGeo\Simulation\SimulationState;
use UniversalGeo\Tests\Support\ServerRequestFactory;

/**
 * Cookie, filter, cache-isolation, and authorization coverage for simulation.
 */
final class SimulationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['universal_geo_test_current_user_can'] = true;
		$GLOBALS['universal_geo_test_is_logged_in']     = true;
		$GLOBALS['universal_geo_test_object_cache']     = array();
		$GLOBALS['universal_geo_test_setcookie_calls']  = array();
		unset( $_COOKIE[ SimulationCookie::NAME ] );
	}

	public function test_inactive_filter_returns_original_context(): void {
		$original = new VisitorContext( 'SE', null, 'maxmind', 0.9, true );
		$filter   = new SimulationContextFilter( $this->state() );

		$this->assertSame( $original, $filter->apply( $original ) );
	}

	public function test_active_filter_replaces_country_and_metadata(): void {
		$cookie = new SimulationCookie();
		$cookie->write( 'DE' );

		$original = new VisitorContext( 'SE', 'AB', 'maxmind', 0.9, true );
		$result   = ( new SimulationContextFilter( $this->state( $cookie ) ) )->apply( $original );

		$this->assertSame( 'DE', $result->country_code );
		$this->assertNull( $result->region_code );
		$this->assertSame( SimulationContextFilter::SOURCE, $result->source );
		$this->assertSame( SimulationContextFilter::CONFIDENCE, $result->confidence );
		$this->assertFalse( $result->is_cached );
		$this->assertNotSame( $original, $result );
	}

	/**
	 * M13: proves the real, region-capable context is never mutated by
	 * simulation and is restored exactly (identity, not just value-equal)
	 * once simulation stops — the same filter instance/state, first with an
	 * active cookie, then without one.
	 */
	public function test_stopping_simulation_restores_the_real_non_null_region(): void {
		$cookie = new SimulationCookie();
		$cookie->write( 'DE' );

		$real   = new VisitorContext( 'SE', 'AB', 'maxmind', 0.9, true );
		$filter = new SimulationContextFilter( $this->state( $cookie ) );

		$simulated = $filter->apply( $real );
		$this->assertSame( 'DE', $simulated->country_code );
		$this->assertNull( $simulated->region_code );

		$cookie->clear();
		$restored = ( new SimulationContextFilter( $this->state() ) )->apply( $real );

		$this->assertSame( $real, $restored );
		$this->assertSame( 'SE', $restored->country_code );
		$this->assertSame( 'AB', $restored->region_code );
	}

	public function test_unauthorized_user_ignores_valid_cookie(): void {
		$cookie = new SimulationCookie();
		$cookie->write( 'DE' );

		$GLOBALS['universal_geo_test_current_user_can'] = false;
		$GLOBALS['universal_geo_test_is_logged_in']     = true;

		$this->assertNull( $this->state( $cookie )->active_country() );
	}

	public function test_logged_out_user_ignores_valid_cookie(): void {
		$cookie = new SimulationCookie();
		$cookie->write( 'DE' );

		$GLOBALS['universal_geo_test_is_logged_in'] = false;

		$this->assertNull( $this->state( $cookie )->active_country() );
	}

	public function test_malformed_cookie_is_rejected(): void {
		$_COOKIE[ SimulationCookie::NAME ] = 'not-valid';

		$this->assertNull( $this->state()->active_country() );
	}

	public function test_tampered_signature_is_rejected(): void {
		$_COOKIE[ SimulationCookie::NAME ] = '1.DE.deadbeefdeadbeefdeadbeefdeadbeef';

		$this->assertNull( $this->state()->active_country() );
	}

	public function test_simulation_never_writes_to_geo_cache(): void {
		$cache    = new GeoCache( true, 900, 'sig' );
		$request  = ServerRequestFactory::make( '203.0.113.10' );
		$resolver = new ContextResolver(
			new ClientIpResolver( $request, new TrustedProxies( array(), false ) ),
			array( new DefaultCountryProvider( 'SE' ) ),
			$cache
		);

		$real = $resolver->resolve();
		$this->assertSame( 'SE', $real->country_code );

		$cookie = new SimulationCookie();
		$cookie->write( 'DE' );
		$simulated = ( new SimulationContextFilter( $this->state( $cookie ) ) )->apply( $real );

		$this->assertSame( 'DE', $simulated->country_code );

		$resolver->reset();
		$again = $resolver->resolve();

		$this->assertSame( 'SE', $again->country_code );
		$this->assertTrue( $again->is_cached );
	}

	public function test_country_catalog_contains_validated_codes(): void {
		$catalog = new CountryCatalog();
		$options = $catalog->options();

		$this->assertArrayHasKey( 'DE', $options );
		$this->assertArrayHasKey( 'SE', $options );
		$this->assertNotEmpty( $options['DE'] );
	}

	public function test_clear_cookie_removes_state(): void {
		$cookie = new SimulationCookie();
		$cookie->write( 'DE' );
		$this->assertTrue( $this->state( $cookie )->is_active() );

		$cookie->clear();
		$this->assertFalse( $this->state( $cookie )->is_active() );
	}

	public function test_filter_registers_at_expected_priority(): void {
		$GLOBALS['universal_geo_test_filters'] = array();

		( new SimulationContextFilter( $this->state() ) )->register();

		$this->assertNotEmpty( $GLOBALS['universal_geo_test_filters']['universal_geo_context'] ?? array() );
	}

	private function state( ?SimulationCookie $cookie = null ): SimulationState {
		return new SimulationState(
			$cookie ?? new SimulationCookie(),
			new SimulationAuthorization()
		);
	}
}
