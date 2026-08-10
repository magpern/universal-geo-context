<?php
/**
 * Unit tests for OperationalStatus and OperationalStatusService.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Diagnostics;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\OperationalIssue;
use UniversalGeo\Diagnostics\OperationalStatus;
use UniversalGeo\Diagnostics\OperationalStatusService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Model\GeoCandidate;
use UniversalGeo\Providers\DefaultCountryProvider;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationCookie;
use UniversalGeo\Simulation\SimulationState;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use UniversalGeo\Tests\Support\ServerRequestFactory;
use UniversalGeo\Tests\Unit\Doubles\TrackingGeoProvider;

/**
 * Table-driven readiness classification and passive-evaluation guarantees.
 */
final class OperationalStatusServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['universal_geo_test_options']          = array();
		$GLOBALS['universal_geo_test_cron']             = array();
		$GLOBALS['universal_geo_test_filters']          = array();
		$GLOBALS['universal_geo_test_actions']          = array();
		$GLOBALS['universal_geo_test_is_logged_in']     = true;
		$GLOBALS['universal_geo_test_current_user_can'] = true;
		unset( $_COOKIE['universal_geo_sim'] );
	}

	protected function tearDown(): void {
		unset( $_COOKIE['universal_geo_sim'] );
		parent::tearDown();
	}

	public function test_ready_when_preferred_provider_available(): void {
		$provider = new TrackingGeoProvider( 'cloudflare', true, new GeoCandidate( 'SE', null ) );
		$status   = $this->evaluate( array( $provider ), array(), new TrustedProxies( array(), true ) );

		$this->assertSame( OperationalStatus::STATE_READY, $status->state );
		$this->assertTrue( $status->consumer_usable );
		$this->assertFalse( $status->simulation_active );
	}

	public function test_degraded_when_only_default_with_country(): void {
		$default = new DefaultCountryProvider( 'SE' );
		$status  = $this->evaluate( array( $default ), array( 'default_country' => 'SE' ) );

		$this->assertSame( OperationalStatus::STATE_DEGRADED, $status->state );
		$this->assertTrue( $status->consumer_usable );
		$this->assertTrue( $this->has_code( $status, 'default_only' ) );
	}

	public function test_degraded_when_default_empty_unknown_without_expectation(): void {
		$default = new DefaultCountryProvider( '' );
		$status  = $this->evaluate( array( $default ), array( 'default_country' => '' ) );

		$this->assertSame( OperationalStatus::STATE_DEGRADED, $status->state );
		$this->assertTrue( $status->consumer_usable );
		$this->assertFalse( $this->has_code( $status, 'geo_sources_unavailable' ) );
	}

	public function test_action_required_when_geo_expected_but_only_default(): void {
		$default = new DefaultCountryProvider( 'SE' );
		$status  = $this->evaluate(
			array( $default ),
			array(
				'default_country'         => 'SE',
				'maxmind_managed_enabled' => true,
			)
		);

		$this->assertSame( OperationalStatus::STATE_ACTION_REQUIRED, $status->state );
		$this->assertTrue( $status->consumer_usable );
		$this->assertTrue( $this->has_code( $status, 'geo_sources_unavailable' ) );
	}

	public function test_action_required_for_missing_managed_credentials(): void {
		$provider = new TrackingGeoProvider( 'cloudflare', true, new GeoCandidate( 'SE', null ) );
		$status   = $this->evaluate(
			array( $provider ),
			array(
				'maxmind_managed_enabled'             => true,
				'maxmind_managed_auto_update_enabled' => true,
			),
			new TrustedProxies( array(), true ),
			'none'
		);

		$this->assertSame( OperationalStatus::STATE_ACTION_REQUIRED, $status->state );
		$this->assertTrue( $status->consumer_usable );
		$this->assertTrue( $this->has_code( $status, 'managed_auto_update_missing_credentials' ) );
	}

	public function test_action_required_trusted_proxy_misconfiguration_not_consumer_usable(): void {
		$request  = ServerRequestFactory::make(
			'10.0.0.1',
			array( 'X-Forwarded-For' => '203.0.113.9' )
		);
		$trusted  = new TrustedProxies( array(), false );
		$ip       = new ClientIpResolver( $request, $trusted );
		$default  = new DefaultCountryProvider( 'SE' );
		$resolver = new ContextResolver( $ip, array( $default ), new GeoCache( false, 900, 'sig' ) );

		$service = new OperationalStatusService(
			$resolver,
			$request,
			$trusted,
			array( 'default_country' => 'SE' ),
			new ProviderHealthStore(),
			new MaxMindProvider( '' ),
			new CircuitBreaker(),
			'none',
			$this->database_manager(),
			new UpdateScheduler( $this->database_manager() ),
			$this->simulation_state( false ),
			'none'
		);

		$status = $service->evaluate();

		$this->assertSame( OperationalStatus::STATE_ACTION_REQUIRED, $status->state );
		$this->assertFalse( $status->consumer_usable );
		$this->assertTrue( $this->has_code( $status, 'trusted_proxy_misconfigured' ) );
	}

	public function test_simulation_overlay_does_not_change_base_ready_state(): void {
		$provider = new TrackingGeoProvider( 'cloudflare', true, new GeoCandidate( 'SE', null ) );
		$status   = $this->evaluate(
			array( $provider ),
			array(),
			new TrustedProxies( array(), true ),
			'none',
			true
		);

		$this->assertSame( OperationalStatus::STATE_READY, $status->state );
		$this->assertTrue( $status->simulation_active );
		$this->assertTrue( $this->has_code( $status, 'simulation_active' ) );
	}

	public function test_evaluate_is_memoized_and_passive(): void {
		$transport = new FakeHttpTransport();
		$provider  = new TrackingGeoProvider( 'cloudflare', true, new GeoCandidate( 'SE', null ) );
		$resolver  = new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make( '203.0.113.1' ), new TrustedProxies( array(), true ) ),
			array( $provider ),
			new GeoCache( false, 900, 'sig' )
		);

		$service = new OperationalStatusService(
			$resolver,
			ServerRequestFactory::make( '203.0.113.1' ),
			new TrustedProxies( array(), true ),
			array(),
			new ProviderHealthStore(),
			new MaxMindProvider( '' ),
			new CircuitBreaker(),
			'none',
			$this->database_manager( $transport ),
			new UpdateScheduler( $this->database_manager( $transport ) ),
			$this->simulation_state( false ),
			'none'
		);

		$first  = $service->evaluate();
		$second = $service->evaluate();

		$this->assertSame( $first, $second );
		$this->assertSame( 0, $provider->resolve_calls );
		$this->assertSame( 0, $transport->call_count() );
	}

	public function test_to_array_shape(): void {
		$status = new OperationalStatus(
			OperationalStatus::STATE_DEGRADED,
			true,
			false,
			array(
				new OperationalIssue( 'default_only', OperationalIssue::SEVERITY_RECOMMENDED, 'msg', 'fix' ),
			),
			'summary'
		);

		$data = $status->to_array();
		$this->assertSame( 'degraded', $data['state'] );
		$this->assertTrue( $data['consumer_usable'] );
		$this->assertSame( 'default_only', $data['issues'][0]['code'] );
	}

	/**
	 * @param array<\UniversalGeo\Contracts\GeoProviderInterface> $providers Providers.
	 * @param array<string, mixed>                                $settings  Settings overrides.
	 */
	private function evaluate(
		array $providers,
		array $settings = array(),
		?TrustedProxies $trusted = null,
		string $remote_credential_source = 'none',
		bool $simulation_active = false,
		string $maxmind_path_source = 'none'
	): OperationalStatus {
		$trusted  = $trusted ?? new TrustedProxies( array(), false );
		$request  = ServerRequestFactory::make( '203.0.113.1' );
		$resolver = new ContextResolver(
			new ClientIpResolver( $request, $trusted ),
			$providers,
			new GeoCache( false, 900, 'sig' )
		);

		if ( $settings['maxmind_managed_enabled'] ?? false ) {
			$maxmind_path_source = 'managed';
		}

		$service = new OperationalStatusService(
			$resolver,
			$request,
			$trusted,
			$settings,
			new ProviderHealthStore(),
			new MaxMindProvider( '' ),
			new CircuitBreaker(),
			$remote_credential_source,
			$this->database_manager(),
			new UpdateScheduler( $this->database_manager() ),
			$this->simulation_state( $simulation_active ),
			$maxmind_path_source
		);

		return $service->evaluate();
	}

	private function has_code( OperationalStatus $status, string $code ): bool {
		foreach ( $status->issues as $issue ) {
			if ( $issue->code === $code ) {
				return true;
			}
		}

		return false;
	}

	private function database_manager( ?FakeHttpTransport $transport = null ): DatabaseManager {
		return new DatabaseManager(
			sys_get_temp_dir() . '/ugc-m12-test-maxmind',
			'',
			'',
			true,
			$transport ?? new FakeHttpTransport(),
			new ArchiveExtractor(),
			new UpdateLock()
		);
	}

	private function simulation_state( bool $active ): SimulationState {
		$cookie = new SimulationCookie();
		$auth   = new SimulationAuthorization();

		$GLOBALS['universal_geo_test_is_logged_in']     = true;
		$GLOBALS['universal_geo_test_current_user_can'] = true;

		if ( $active ) {
			$cookie->write( 'SE' );
		}

		return new SimulationState( $cookie, $auth );
	}
}
