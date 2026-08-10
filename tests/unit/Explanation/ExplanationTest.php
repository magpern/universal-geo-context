<?php
/**
 * Unit tests for the Detection Inspector explanation layer (M9).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Explanation;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Explanation\DetectionInspectorService;
use UniversalGeo\Explanation\ExplanationFormatter;
use UniversalGeo\Explanation\ProviderExplanationBuilder;
use UniversalGeo\Explanation\ResolutionStage;
use UniversalGeo\Explanation\ResolutionTimelineBuilder;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Providers\DefaultCountryProvider;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationCookie;
use UniversalGeo\Simulation\SimulationState;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use UniversalGeo\Tests\Support\ServerRequestFactory;
use UniversalGeo\Tests\Unit\Doubles\TrackingGeoProvider;
use UniversalGeo\MaxMind\UpdateScheduler;

/**
 * @covers \UniversalGeo\Explanation\DetectionInspectorService
 * @covers \UniversalGeo\Explanation\ProviderExplanationBuilder
 * @covers \UniversalGeo\Explanation\ResolutionTimelineBuilder
 * @covers \UniversalGeo\Explanation\ExplanationFormatter
 */
final class ExplanationTest extends TestCase {

	public function test_timeline_includes_simulation_stage_when_active(): void {
		$builder   = new ResolutionTimelineBuilder();
		$real      = new VisitorContext( 'SE', null, 'cloudflare', 0.95, false );
		$effective = new VisitorContext( 'DE', null, 'simulation', 1.0, false );

		$timeline = $builder->build(
			array( 'client_masked' => '203.0.113.x' ),
			array( 'peer_trusted' => false ),
			array(),
			$real,
			$effective,
			true,
			array(
				'cache_operational'   => false,
				'current_request_hit' => false,
			)
		);

		$simulation = null;
		foreach ( $timeline as $stage ) {
			if ( $stage instanceof ResolutionStage && 'simulation' === $stage->id ) {
				$simulation = $stage;
				break;
			}
		}

		$this->assertInstanceOf( ResolutionStage::class, $simulation );
		$this->assertSame( ResolutionStage::STATUS_SUCCESS, $simulation->status );
	}

	public function test_provider_builder_inferred_marks_winner(): void {
		$resolver = new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) ),
			array(
				new TrackingGeoProvider( 'cloudflare', true, null ),
				new TrackingGeoProvider( 'maxmind', true, null ),
				new DefaultCountryProvider( 'US' ),
			),
			new GeoCache( false, 900, 'sig' )
		);

		$builder = new ProviderExplanationBuilder( $resolver );
		$real    = new VisitorContext( 'US', null, 'default', 0.10, false );
		$rows    = $builder->inferred( $real, array() );

		$this->assertCount( 3, $rows );
		$this->assertTrue( $rows[2]->is_winner );
		$this->assertTrue( $rows[0]->attempted );
	}

	public function test_provider_builder_from_probe_marks_ok_winner(): void {
		$resolver = new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) ),
			array(),
			new GeoCache( false, 900, 'sig' )
		);
		$builder  = new ProviderExplanationBuilder( $resolver );
		$probe    = array(
			array(
				'provider'     => 'cloudflare',
				'available'    => true,
				'country_code' => 'SE',
				'region_code'  => null,
				'reason'       => 'ok',
			),
		);

		$rows = $builder->from_probe( $probe, 'cloudflare' );
		$this->assertTrue( $rows[0]->is_winner );
		$this->assertSame( 'SE', $rows[0]->country_code );
	}

	public function test_explain_without_live_probe_uses_inference(): void {
		$service = $this->inspector_service();
		$result  = $service->explain( null );

		$this->assertFalse( $result->has_live_probe );
		$this->assertNotEmpty( $result->timeline );
		$this->assertNotEmpty( $result->providers );
	}

	public function test_explain_after_explicit_refresh_does_not_probe_all_providers(): void {
		$first    = new TrackingGeoProvider( 'a', true, new \UniversalGeo\Model\GeoCandidate( 'SE', null ) );
		$second   = new TrackingGeoProvider( 'b', true, new \UniversalGeo\Model\GeoCandidate( 'DE', null ) );
		$resolver = new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) ),
			array( $first, $second ),
			new GeoCache( false, 900, 'sig' )
		);
		$service  = $this->inspector_service_for_resolver( $resolver );

		$result = $service->explain(
			array(
				'ok_count' => 1,
				'total'    => 2,
			)
		);

		$this->assertTrue( $result->has_live_probe );
		$this->assertSame( 1, $first->resolve_calls );
		$this->assertSame( 0, $second->resolve_calls );
	}

	public function test_timeline_cache_hit_skips_provider_chain(): void {
		$builder = new ResolutionTimelineBuilder();
		$real    = new VisitorContext( 'SE', null, 'cloudflare', 0.95, true );

		$timeline = $builder->build(
			array( 'client_masked' => '203.0.113.x' ),
			array( 'peer_trusted' => false ),
			array(),
			$real,
			$real,
			false,
			array(
				'cache_operational'       => true,
				'current_request_hit'     => true,
				'this_request_from_cache' => true,
			)
		);

		$provider_stage = null;
		foreach ( $timeline as $stage ) {
			if ( $stage instanceof ResolutionStage && 'providers' === $stage->id ) {
				$provider_stage = $stage;
				break;
			}
		}

		$this->assertInstanceOf( ResolutionStage::class, $provider_stage );
		$this->assertSame( ResolutionStage::STATUS_NOT_ATTEMPTED, $provider_stage->status );
	}

	public function test_formatter_labels_cached_status(): void {
		$formatter = new ExplanationFormatter();
		$this->assertSame( 'Cached', $formatter->timeline_status_label( ResolutionStage::STATUS_CACHED ) );
	}

	public function test_context_resolver_provider_chain_introspection(): void {
		$resolver = new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) ),
			array(
				new TrackingGeoProvider( 'cloudflare', true, null ),
				new DefaultCountryProvider( 'US' ),
			),
			new GeoCache( false, 900, 'sig' )
		);

		$this->assertSame( array( 'cloudflare', 'default' ), $resolver->provider_chain() );
		$this->assertTrue( $resolver->is_provider_available( 'cloudflare' ) );
		$this->assertSame( 0.95, $resolver->confidence_for_provider( 'cloudflare' ) );
	}

	private function inspector_service_for_resolver( ContextResolver $resolver ): DetectionInspectorService {
		$request     = ServerRequestFactory::make();
		$trusted     = new TrustedProxies( array(), false );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$diagnostics = new DiagnosticsService(
			$resolver,
			$ip_resolver,
			$request,
			$trusted,
			array(
				'default_country'       => 'US',
				'derived_cache_enabled' => false,
				'derived_cache_ttl'     => 900,
			),
			new ProviderHealthStore(),
			new MaxMindProvider( '' ),
			new CircuitBreaker(),
			'none',
			new DatabaseManager( sys_get_temp_dir(), '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ),
			'none'
		, new GeoCache( false, 900, 'sig' ), new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-m12-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ), new SimulationState( new SimulationCookie(), new SimulationAuthorization() ));
		$cookie      = new SimulationCookie();
		$simulation  = new SimulationState( $cookie, new SimulationAuthorization() );

		return new DetectionInspectorService(
			$resolver,
			$ip_resolver,
			new GeoCache( false, 900, 'sig' ),
			$diagnostics,
			$simulation,
			new ProviderExplanationBuilder( $resolver ),
			new ResolutionTimelineBuilder()
		);
	}

	private function inspector_service(): DetectionInspectorService {
		$resolver = new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) ),
			array( new DefaultCountryProvider( 'US' ) ),
			new GeoCache( false, 900, 'sig' )
		);

		return $this->inspector_service_for_resolver( $resolver );
	}
}
