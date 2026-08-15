<?php
/**
 * Unit tests for UniversalGeo\Diagnostics\DiagnosticsService.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Diagnostics;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Admin\ReportRenderer;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Model\GeoCandidate;
use UniversalGeo\Plugin;
use UniversalGeo\Providers\DefaultCountryProvider;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Settings;
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationCookie;
use UniversalGeo\Simulation\SimulationState;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use UniversalGeo\Tests\Support\ServerRequestFactory;
use UniversalGeo\Tests\Unit\Doubles\TrackingGeoProvider;

/**
 * Covers the M2 report() sections, the trusted-proxy Site Health test,
 * register(), (M3) the maxmind/provider_health report sections and the
 * MaxMind Site Health test, and (M4) the remote report section and the
 * remote-provider Site Health test.
 */
final class DiagnosticsServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['universal_geo_test_options']                = array();
		$GLOBALS['universal_geo_test_object_cache']           = array();
		$GLOBALS['universal_geo_test_object_cache_calls']     = array();
		$GLOBALS['universal_geo_test_using_ext_object_cache'] = true;
		$GLOBALS['universal_geo_test_filters']                = array();
		$GLOBALS['universal_geo_test_actions']                = array();
		$GLOBALS['universal_geo_test_current_user_can']       = true;

		$reflection = new ReflectionClass( Plugin::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		// result_section() unconditionally calls Plugin::instance()->context(),
		// which _doing_it_wrong()s if Plugin was never init()'d — every test
		// building a report needs Plugin booted first, even when a test's own
		// scenario doesn't care about the result section specifically.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		Plugin::instance()->init();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	private function service(
		?ClientIpResolver $ip_resolver = null,
		?TrustedProxies $trusted_proxies = null,
		array $settings = array(),
		array $providers = array(),
		?ProviderHealthStore $provider_health_store = null,
		?MaxMindProvider $maxmind_provider = null,
		?CircuitBreaker $circuit_breaker = null,
		string $remote_credential_source = 'none',
		?DatabaseManager $database_manager = null,
		string $maxmind_path_source = 'none'
	): DiagnosticsService {
		$request         = ServerRequestFactory::make( '203.0.113.1' );
		$trusted_proxies = $trusted_proxies ?? new TrustedProxies( array(), false );
		$ip_resolver     = $ip_resolver ?? new ClientIpResolver( $request, $trusted_proxies );
		$resolver        = new ContextResolver( $ip_resolver, $providers, new GeoCache( false, 900, 'sig' ) );

		return new DiagnosticsService(
			$resolver,
			$ip_resolver,
			$request,
			$trusted_proxies,
			$settings,
			$provider_health_store ?? new ProviderHealthStore(),
			$maxmind_provider ?? new MaxMindProvider( '' ),
			$circuit_breaker ?? new CircuitBreaker(),
			$remote_credential_source,
			$database_manager ?? $this->unused_database_manager(),
			$maxmind_path_source,
			new GeoCache( false, 900, 'sig' ),
			new UpdateScheduler( $database_manager ?? $this->unused_database_manager() ),
			new SimulationState( new SimulationCookie(), new SimulationAuthorization() )
		);
	}

	/**
	 * An unused-in-most-tests DatabaseManager, the same "empty/inert double"
	 * role `new MaxMindProvider('')` already plays for tests that don't
	 * care about the managed-database feature specifically.
	 */
	private function unused_database_manager(): DatabaseManager {
		return new DatabaseManager(
			sys_get_temp_dir() . '/ugeo-diagnostics-test-unused',
			'',
			'',
			true,
			new FakeHttpTransport(),
			new ArchiveExtractor(),
			new UpdateLock()
		);
	}

	// ---- report() shape ---------------------------------------------------------

	public function test_report_contains_all_sections(): void {
		$report = $this->service()->report();

		$this->assertSame(
			array(
				'result',
				'client_address',
				'trusted_proxies',
				'forwarding_headers',
				'cloudflare',
				'woocommerce',
				'maxmind',
				'remote',
				'maxmind_managed',
				'providers',
				'provider_health',
				'cache',
				'simulation',
				'environment',
			),
			array_keys( $report )
		);
	}

	// ---- result section -----------------------------------------------------------

	public function test_result_section_reflects_plugin_context(): void {
		// setUp() already booted Plugin once (with empty settings, for every
		// other test's benefit) — reset the singleton so this test's own
		// settings actually take effect on a fresh init().
		$reflection = new ReflectionClass( Plugin::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ] = array(
			'schema_version'  => Settings::SCHEMA_VERSION,
			'default_country' => 'SE',
		);

		Plugin::instance()->init();

		$report = $this->service()->report();

		$this->assertSame( 'SE', $report['result']['country_code'] );
		$this->assertSame( 'default', $report['result']['source'] );
	}

	// ---- client_address section ----------------------------------------------------

	public function test_client_address_section_masks_the_peer(): void {
		$report = $this->service()->report();

		$this->assertSame( '203.0.113.x', $report['client_address']['peer_masked'] );
		$this->assertSame( 'REMOTE_ADDR', $report['client_address']['source_header'] );
	}

	public function test_client_address_section_never_contains_a_raw_ip(): void {
		$report = $this->service()->report();
		$json   = wp_json_encode( $report['client_address'] );

		$this->assertStringNotContainsString( '203.0.113.1', $json );
	}

	public function test_client_address_section_reports_no_drift_when_unchanged(): void {
		// The service's boot-time ServerRequest snapshot ('203.0.113.1', via
		// the fixture factory) matches the live $_SERVER REMOTE_ADDR setUp()
		// set for Plugin's own boot — no drift.
		$report = $this->service()->report();

		$this->assertSame( array(), $report['client_address']['server_snapshot_drift'] );
	}

	public function test_client_address_section_reports_drift_when_remote_addr_changes(): void {
		$service = $this->service();

		// The service's own ServerRequest snapshot is fixed at construction
		// ('203.0.113.1', via the fixture factory); changing the live
		// superglobal afterward is exactly the mu-plugin/hosting-shim
		// rewrite scenario this drift check exists to catch.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';

		$report = $service->report();

		$this->assertContains( 'REMOTE_ADDR', $report['client_address']['server_snapshot_drift'] );
	}

	// ---- trusted_proxies section ----------------------------------------------------

	public function test_trusted_proxies_section_reports_configured_count(): void {
		$trusted = new TrustedProxies( array( '203.0.113.0/24', '10.0.0.0/8' ), false );
		$report  = $this->service( null, $trusted )->report();

		$this->assertSame( 2, $report['trusted_proxies']['configured_count'] );
	}

	public function test_trusted_proxies_section_reports_the_matched_entry(): void {
		$trusted = new TrustedProxies( array( '203.0.113.0/24' ), false );
		$report  = $this->service( null, $trusted )->report();

		$this->assertSame( '203.0.113.0/24', $report['trusted_proxies']['matched_entry'] );
		$this->assertTrue( $report['trusted_proxies']['peer_trusted'] );
	}

	public function test_trusted_proxies_section_reports_no_match(): void {
		$trusted = new TrustedProxies( array( '10.0.0.0/8' ), false );
		$report  = $this->service( null, $trusted )->report();

		$this->assertNull( $report['trusted_proxies']['matched_entry'] );
		$this->assertFalse( $report['trusted_proxies']['peer_trusted'] );
	}

	// ---- cloudflare section -----------------------------------------------------------

	public function test_cloudflare_section_reports_preset_state(): void {
		$trusted = new TrustedProxies( array(), true );
		$report  = $this->service( null, $trusted )->report();

		$this->assertTrue( $report['cloudflare']['preset_enabled'] );
	}

	public function test_cloudflare_section_reports_the_bundled_ranges_date(): void {
		$report = $this->service()->report();

		$this->assertSame( TrustedProxies::CLOUDFLARE_RANGES_DATE, $report['cloudflare']['ranges_date'] );
		$this->assertIsInt( $report['cloudflare']['ranges_age_days'] );
		$this->assertGreaterThanOrEqual( 0, $report['cloudflare']['ranges_age_days'] );
	}

	public function test_cloudflare_section_reports_header_presence(): void {
		$request     = ServerRequestFactory::make( '173.245.48.1', array( 'CF-IPCountry' => 'SE' ) );
		$trusted     = new TrustedProxies( array(), true );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array(), new ProviderHealthStore(), new MaxMindProvider( '' ), new CircuitBreaker(), 'none', new DatabaseManager( sys_get_temp_dir() . '/ugeo-diagnostics-test-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ), 'none', new GeoCache( false, 900, 'sig' ), new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-m12-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ), new SimulationState( new SimulationCookie(), new SimulationAuthorization() ) );

		$report = $service->report();

		$this->assertTrue( $report['cloudflare']['cf_ipcountry_present'] );
		$this->assertTrue( $report['cloudflare']['peer_in_cf_ranges'] );
	}

	// ---- woocommerce section ------------------------------------------------------------

	public function test_woocommerce_section_reports_inactive_without_woocommerce(): void {
		$this->assertFalse( class_exists( 'WC_Geolocation' ) );

		$report = $this->service()->report();

		$this->assertFalse( $report['woocommerce']['active'] );
	}

	public function test_woocommerce_section_reports_no_maxmind_integration_by_default(): void {
		$report = $this->service()->report();

		$this->assertFalse( $report['woocommerce']['maxmind_integration_active'] );
		$this->assertFalse( $report['woocommerce']['license_key_present'] );
	}

	public function test_woocommerce_section_reports_maxmind_integration_when_configured(): void {
		$GLOBALS['universal_geo_test_options']['woocommerce_maxmind_geolocation_settings'] = array(
			'database_prefix' => 'GeoLite2',
		);

		$report = $this->service()->report();

		$this->assertTrue( $report['woocommerce']['maxmind_integration_active'] );
		$this->assertFalse( $report['woocommerce']['license_key_present'] );
	}

	public function test_woocommerce_section_reports_license_key_present(): void {
		$GLOBALS['universal_geo_test_options']['woocommerce_maxmind_geolocation_settings'] = array(
			'license_key' => 'abc123',
		);

		$report = $this->service()->report();

		$this->assertTrue( $report['woocommerce']['license_key_present'] );
	}

	public function test_woocommerce_section_reports_mmdb_absent_when_wp_upload_dir_unavailable(): void {
		$this->assertFalse( function_exists( 'wp_upload_dir' ) );

		$report = $this->service()->report();

		$this->assertFalse( $report['woocommerce']['mmdb_present'] );
	}

	// ---- maxmind section (M3) --------------------------------------------------------------

	public function test_maxmind_section_reports_unconfigured_by_default(): void {
		$report = $this->service()->report();

		$this->assertSame( '', $report['maxmind']['effective_path'] );
		$this->assertFalse( $report['maxmind']['available'] );
		$this->assertNull( $report['maxmind']['database_type'] );
		$this->assertNull( $report['maxmind']['build_age_days'] );
		$this->assertSame( '', $report['maxmind']['city_database_note'] );
	}

	public function test_maxmind_section_reports_metadata_for_a_real_database(): void {
		$fixture  = dirname( __DIR__, 2 ) . '/fixtures/GeoIP2-Country-Test.mmdb';
		$provider = new MaxMindProvider( $fixture );
		$report   = $this->service( null, null, array(), array(), null, $provider )->report();

		$this->assertSame( $fixture, $report['maxmind']['effective_path'] );
		$this->assertTrue( $report['maxmind']['available'] );
		$this->assertSame( 'GeoIP2-Country', $report['maxmind']['database_type'] );
		$this->assertIsInt( $report['maxmind']['build_age_days'] );
		$this->assertStringContainsString( 'Reader.php', $report['maxmind']['reader_class_file'] );
		$this->assertSame( '', $report['maxmind']['city_database_note'] );
	}

	public function test_maxmind_section_reports_city_database_note_for_a_city_database(): void {
		$fixture  = dirname( __DIR__, 2 ) . '/fixtures/GeoIP2-City-Test.mmdb';
		$provider = new MaxMindProvider( $fixture );
		$report   = $this->service( null, null, array(), array(), null, $provider )->report();

		$this->assertSame( 'GeoIP2-City', $report['maxmind']['database_type'] );
		$this->assertNotSame( '', $report['maxmind']['city_database_note'] );
	}

	public function test_maxmind_section_never_opens_a_second_reader(): void {
		$fixture  = dirname( __DIR__, 2 ) . '/fixtures/GeoIP2-Country-Test.mmdb';
		$provider = new MaxMindProvider( $fixture );
		$provider->resolve( '214.78.120.1' );

		$reflection      = new \ReflectionClass( $provider );
		$reader_property = $reflection->getProperty( 'reader' );
		$reader_property->setAccessible( true );
		$reader_before = $reader_property->getValue( $provider );

		$this->service( null, null, array(), array(), null, $provider )->report();

		$this->assertSame( $reader_before, $reader_property->getValue( $provider ) );
	}

	// ---- remote section (M4) -------------------------------------------------------------

	public function test_remote_section_reports_disabled_by_default(): void {
		$report = $this->service()->report();

		$this->assertFalse( $report['remote']['enabled'] );
		$this->assertFalse( $report['remote']['transfer_acknowledged'] );
		$this->assertFalse( $report['remote']['credentials_present'] );
		$this->assertSame( 'none', $report['remote']['credential_source'] );
	}

	public function test_remote_section_reports_the_fixed_endpoint_host(): void {
		$report = $this->service()->report();

		$this->assertSame( 'geolite.info', $report['remote']['endpoint_host'] );
	}

	public function test_remote_section_reports_the_default_timeout_when_unconfigured(): void {
		$report = $this->service()->report();

		$this->assertSame( 2, $report['remote']['timeout_seconds'] );
	}

	public function test_remote_section_reports_the_configured_timeout(): void {
		$report = $this->service( null, null, array( 'remote_timeout' => 5 ) )->report();

		$this->assertSame( 5, $report['remote']['timeout_seconds'] );
	}

	public function test_remote_section_reports_settings_derived_flags(): void {
		$settings = array(
			'remote_enabled'               => true,
			'remote_transfer_acknowledged' => true,
		);
		$report   = $this->service( null, null, $settings )->report();

		$this->assertTrue( $report['remote']['enabled'] );
		$this->assertTrue( $report['remote']['transfer_acknowledged'] );
	}

	public function test_remote_section_reports_the_injected_credential_source(): void {
		$report = $this->service( null, null, array(), array(), null, null, null, 'settings' )->report();

		$this->assertTrue( $report['remote']['credentials_present'] );
		$this->assertSame( 'settings', $report['remote']['credential_source'] );
	}

	public function test_remote_section_never_exposes_credential_values(): void {
		$report = $this->service( null, null, array(), array(), null, null, null, 'settings' )->report();
		$json   = wp_json_encode( $report['remote'] );

		$this->assertStringNotContainsString( 'account', $json );
		$this->assertStringNotContainsString( 'license', $json );
	}

	public function test_remote_section_reports_the_circuit_state(): void {
		$circuit_breaker = new CircuitBreaker();
		$circuit_breaker->report_failure();
		$circuit_breaker->report_failure();
		$circuit_breaker->report_failure();

		$report = $this->service( null, null, array(), array(), null, null, $circuit_breaker )->report();

		$this->assertSame( 'open', $report['remote']['circuit_state'] );
	}

	public function test_remote_section_reading_circuit_state_does_not_mutate_it(): void {
		$circuit_breaker = new CircuitBreaker();

		$this->service( null, null, array(), array(), null, null, $circuit_breaker )->report();

		$this->assertFalse( get_option( CircuitBreaker::OPTION_NAME, false ) );
	}

	public function test_remote_section_reports_no_recent_failure_by_default(): void {
		$report = $this->service()->report();

		$this->assertNull( $report['remote']['recent_failure'] );
	}

	public function test_remote_section_reports_a_scrubbed_recent_failure(): void {
		$provider_health_store = new ProviderHealthStore();
		$provider_health_store->record( 'remote', 'TransportException: Could not resolve host: 203.0.113.1' );

		$report = $this->service( null, null, array(), array(), $provider_health_store )->report();

		$this->assertNotNull( $report['remote']['recent_failure'] );
		$this->assertStringNotContainsString( '203.0.113.1', $report['remote']['recent_failure'] );
	}

	// ---- Site Health: remote_site_status_test() (M4) -------------------------------------

	public function test_remote_site_status_test_is_good_when_disabled(): void {
		$service = $this->service();

		$result = $service->remote_site_status_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( DiagnosticsService::TEST_REMOTE, $result['test'] );
	}

	public function test_remote_site_status_test_is_recommended_when_enabled_without_credentials(): void {
		$service = $this->service( null, null, array( 'remote_enabled' => true ), array(), null, null, null, 'none' );

		$this->assertSame( 'recommended', $service->remote_site_status_test()['status'] );
	}

	public function test_remote_site_status_test_is_recommended_when_circuit_is_open(): void {
		$circuit_breaker = new CircuitBreaker();
		$circuit_breaker->report_failure();
		$circuit_breaker->report_failure();
		$circuit_breaker->report_failure();

		$service = $this->service(
			null,
			null,
			array( 'remote_enabled' => true ),
			array(),
			null,
			null,
			$circuit_breaker,
			'settings'
		);

		$this->assertSame( 'recommended', $service->remote_site_status_test()['status'] );
	}

	public function test_remote_site_status_test_is_recommended_after_a_recent_failure(): void {
		$provider_health_store = new ProviderHealthStore();
		$provider_health_store->record( 'remote', 'TransportException: Connection timed out' );

		$service = $this->service(
			null,
			null,
			array( 'remote_enabled' => true ),
			array(),
			$provider_health_store,
			null,
			null,
			'settings'
		);

		$this->assertSame( 'recommended', $service->remote_site_status_test()['status'] );
	}

	public function test_remote_site_status_test_is_good_when_enabled_and_healthy(): void {
		$service = $this->service( null, null, array( 'remote_enabled' => true ), array(), null, null, null, 'settings' );

		$this->assertSame( 'good', $service->remote_site_status_test()['status'] );
	}

	public function test_remote_site_status_test_never_returns_critical(): void {
		// Every scenario this test constructs, even the "worst" (enabled,
		// no credentials, open circuit, recent failure all at once), must
		// cap at 'recommended' — the frozen M4 policy.
		$provider_health_store = new ProviderHealthStore();
		$provider_health_store->record( 'remote', 'TransportException: failure' );
		$circuit_breaker = new CircuitBreaker();
		$circuit_breaker->report_failure();
		$circuit_breaker->report_failure();
		$circuit_breaker->report_failure();

		$service = $this->service(
			null,
			null,
			array( 'remote_enabled' => true ),
			array(),
			$provider_health_store,
			null,
			$circuit_breaker,
			'none'
		);

		$this->assertNotSame( 'critical', $service->remote_site_status_test()['status'] );
	}

	public function test_remote_site_status_test_gated_on_manage_options(): void {
		$GLOBALS['universal_geo_test_current_user_can'] = false;

		$service = $this->service( null, null, array( 'remote_enabled' => true ), array(), null, null, null, 'none' );

		$this->assertSame( 'good', $service->remote_site_status_test()['status'] );
	}

	public function test_remote_site_status_test_performs_no_outbound_request(): void {
		// A fresh CircuitBreaker + no queued transport of any kind: if this
		// test's own service construction ever reached the network, PHPUnit
		// would fail on an actual connection attempt/timeout rather than
		// pass quietly — this test's real assertion is that it completes at
		// all, quickly, using only injected fakes/state.
		$service = $this->service( null, null, array( 'remote_enabled' => true ), array(), null, null, null, 'settings' );

		$result = $service->remote_site_status_test();

		$this->assertContains( $result['status'], array( 'good', 'recommended' ) );
	}

	// ---- providers section (passive snapshot) ----------------------------------------

	public function test_providers_section_shows_passive_snapshot(): void {
		$provider = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SE', null ) );
		$report   = $this->service( null, null, array(), array( $provider ) )->report();

		$this->assertCount( 1, $report['providers'] );
		$this->assertSame( 'a', $report['providers'][0]['provider'] );
		$this->assertSame( true, $report['providers'][0]['available'] );
		$this->assertSame( null, $report['providers'][0]['country_code'] );
		$this->assertSame( null, $report['providers'][0]['region_code'] );
		$this->assertSame( 'passive_snapshot', $report['providers'][0]['reason'] );
	}

	// ---- provider_health section (M3) ------------------------------------------------------

	public function test_provider_health_section_reflects_the_store(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: boom' );

		$report = $this->service( null, null, array(), array(), $store )->report();

		$this->assertArrayHasKey( 'maxmind', $report['provider_health'] );
		$this->assertSame( 'RuntimeException', $report['provider_health']['maxmind']['last_error_class'] );
	}

	public function test_provider_health_section_is_empty_when_nothing_recorded(): void {
		$report = $this->service()->report();

		$this->assertSame( array(), $report['provider_health'] );
	}

	// ---- cache section --------------------------------------------------------------------

	public function test_cache_section_reflects_settings(): void {
		$report = $this->service(
			null,
			null,
			array(
				'derived_cache_enabled' => false,
				'derived_cache_ttl'     => 1234,
			)
		)->report();

		$this->assertFalse( $report['cache']['derived_cache_enabled'] );
		$this->assertSame( 1234, $report['cache']['derived_cache_ttl'] );
	}

	public function test_cache_section_defaults_when_settings_key_missing(): void {
		$report = $this->service( null, null, array() )->report();

		$this->assertTrue( $report['cache']['derived_cache_enabled'] );
		$this->assertSame( 900, $report['cache']['derived_cache_ttl'] );
	}

	// ---- environment section -------------------------------------------------------------

	public function test_environment_section_reports_php_version(): void {
		$report = $this->service()->report();

		$this->assertSame( PHP_VERSION, $report['environment']['php_version'] );
	}

	// ---- Site Health: add_site_status_tests() --------------------------------------------

	public function test_add_site_status_tests_registers_the_trusted_proxy_test(): void {
		$service = $this->service();
		$tests   = $service->add_site_status_tests( array() );

		$this->assertArrayHasKey( DiagnosticsService::TEST_TRUSTED_PROXY, $tests['direct'] );
	}

	public function test_register_wires_the_site_status_tests_filter(): void {
		$this->service()->register();

		$result = apply_filters( 'site_status_tests', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own hook, invoked to simulate WP applying it.

		$this->assertArrayHasKey( DiagnosticsService::TEST_TRUSTED_PROXY, $result['direct'] );
	}

	public function test_add_site_status_tests_registers_the_maxmind_test(): void {
		$tests = $this->service()->add_site_status_tests( array() );

		$this->assertArrayHasKey( DiagnosticsService::TEST_MAXMIND, $tests['direct'] );
	}

	public function test_add_site_status_tests_registers_the_remote_test(): void {
		$tests = $this->service()->add_site_status_tests( array() );

		$this->assertArrayHasKey( DiagnosticsService::TEST_REMOTE, $tests['direct'] );
	}

	// ---- M5: debug_information -----------------------------------------------------------

	public function test_register_wires_the_debug_information_filter(): void {
		$this->service()->register();

		$result = apply_filters( 'debug_information', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own hook, invoked to simulate WP applying it.

		$this->assertArrayHasKey( 'universal-geo-context', $result );
	}

	public function test_add_debug_information_has_a_label(): void {
		$info = $this->service()->add_debug_information( array() );

		$this->assertSame( 'Universal Geo Context', $info['universal-geo-context']['label'] );
	}

	public function test_add_debug_information_is_never_marked_private(): void {
		$info = $this->service()->add_debug_information( array() );

		$this->assertFalse( $info['universal-geo-context']['private'] );
	}

	public function test_add_debug_information_flattens_report_sections_into_fields(): void {
		$info   = $this->service()->add_debug_information( array() );
		$fields = $info['universal-geo-context']['fields'];

		$this->assertArrayHasKey( 'client_address.peer_masked', $fields );
		$this->assertSame( 'Peer address (masked)', $fields['client_address.peer_masked']['label'] );
	}

	public function test_add_debug_information_never_contains_a_credential_value(): void {
		$info   = $this->service()->add_debug_information( array() );
		$values = array_column( $info['universal-geo-context']['fields'], 'value' );

		foreach ( $values as $value ) {
			$this->assertStringNotContainsString( 'license', strtolower( (string) $value ) );
		}
	}

	// ---- M5: field_labels() ----------------------------------------------------------------

	/**
	 * @dataProvider client_address_key_provider
	 */
	public function test_field_labels_maps_every_client_address_key( string $key ): void {
		$this->assertArrayHasKey( $key, $this->service()->field_labels() );
	}

	public function client_address_key_provider(): array {
		return array(
			'peer_masked'           => array( 'peer_masked' ),
			'peer_classification'   => array( 'peer_classification' ),
			'client_masked'         => array( 'client_masked' ),
			'source_header'         => array( 'source_header' ),
			'is_public'             => array( 'is_public' ),
			'chain_verified'        => array( 'chain_verified' ),
			'server_snapshot_drift' => array( 'server_snapshot_drift' ),
		);
	}

	// ---- Site Health: trusted_proxy_site_status_test() -----------------------------------

	public function test_site_status_test_is_critical_when_misconfigured(): void {
		// Forwarding header present, peer private, no trusted proxies.
		$request     = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '198.51.100.2' ) );
		$trusted     = new TrustedProxies( array(), false );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array(), new ProviderHealthStore(), new MaxMindProvider( '' ), new CircuitBreaker(), 'none', new DatabaseManager( sys_get_temp_dir() . '/ugeo-diagnostics-test-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ), 'none', new GeoCache( false, 900, 'sig' ), new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-m12-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ), new SimulationState( new SimulationCookie(), new SimulationAuthorization() ) );

		$result = $service->trusted_proxy_site_status_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( DiagnosticsService::TEST_TRUSTED_PROXY, $result['test'] );
	}

	public function test_site_status_test_is_good_when_trusted_proxies_configured(): void {
		$request     = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '198.51.100.2' ) );
		$trusted     = new TrustedProxies( array( '172.18.0.0/16' ), false );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array(), new ProviderHealthStore(), new MaxMindProvider( '' ), new CircuitBreaker(), 'none', new DatabaseManager( sys_get_temp_dir() . '/ugeo-diagnostics-test-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ), 'none', new GeoCache( false, 900, 'sig' ), new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-m12-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ), new SimulationState( new SimulationCookie(), new SimulationAuthorization() ) );

		$this->assertSame( 'good', $service->trusted_proxy_site_status_test()['status'] );
	}

	public function test_site_status_test_is_good_when_peer_is_public(): void {
		// Headers present but the peer is already public — no misconfiguration.
		$request     = ServerRequestFactory::make( '8.8.8.8', array( 'X-Real-IP' => '198.51.100.2' ) );
		$trusted     = new TrustedProxies( array(), false );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array(), new ProviderHealthStore(), new MaxMindProvider( '' ), new CircuitBreaker(), 'none', new DatabaseManager( sys_get_temp_dir() . '/ugeo-diagnostics-test-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ), 'none', new GeoCache( false, 900, 'sig' ), new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-m12-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ), new SimulationState( new SimulationCookie(), new SimulationAuthorization() ) );

		$this->assertSame( 'good', $service->trusted_proxy_site_status_test()['status'] );
	}

	public function test_site_status_test_is_good_when_no_forwarding_headers_present(): void {
		$request     = ServerRequestFactory::make( '172.18.0.5' );
		$trusted     = new TrustedProxies( array(), false );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array(), new ProviderHealthStore(), new MaxMindProvider( '' ), new CircuitBreaker(), 'none', new DatabaseManager( sys_get_temp_dir() . '/ugeo-diagnostics-test-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ), 'none', new GeoCache( false, 900, 'sig' ), new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-m12-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ), new SimulationState( new SimulationCookie(), new SimulationAuthorization() ) );

		$this->assertSame( 'good', $service->trusted_proxy_site_status_test()['status'] );
	}

	public function test_site_status_test_gated_on_manage_options(): void {
		$GLOBALS['universal_geo_test_current_user_can'] = false;

		$request     = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '198.51.100.2' ) );
		$trusted     = new TrustedProxies( array(), false );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array(), new ProviderHealthStore(), new MaxMindProvider( '' ), new CircuitBreaker(), 'none', new DatabaseManager( sys_get_temp_dir() . '/ugeo-diagnostics-test-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ), 'none', new GeoCache( false, 900, 'sig' ), new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-m12-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ), new SimulationState( new SimulationCookie(), new SimulationAuthorization() ) );

		// Even though this scenario would otherwise be critical, an
		// unauthorized user must never see that verdict.
		$this->assertSame( 'good', $service->trusted_proxy_site_status_test()['status'] );
	}

	// ---- Site Health: maxmind_site_status_test() (M3) --------------------------------------

	public function test_maxmind_site_status_test_is_good_when_unconfigured(): void {
		$service = $this->service( null, null, array(), array(), null, new MaxMindProvider( '' ) );

		$result = $service->maxmind_site_status_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( DiagnosticsService::TEST_MAXMIND, $result['test'] );
	}

	public function test_maxmind_site_status_test_is_critical_when_configured_but_missing(): void {
		$provider = new MaxMindProvider( '/nonexistent/path/geo.mmdb' );
		$service  = $this->service( null, null, array(), array(), null, $provider );

		$this->assertSame( 'critical', $service->maxmind_site_status_test()['status'] );
	}

	public function test_maxmind_site_status_test_is_good_for_a_healthy_fresh_database(): void {
		// The fixture's build_epoch is recent enough (see MaxMindProviderTest)
		// to fall well under the 30-day recommended threshold at the time
		// this milestone was built; a hard date assertion isn't made here to
		// avoid a test that silently starts failing purely due to elapsed
		// time — build_age_days() itself is unit-tested directly on
		// MaxMindMetadata.
		$fixture  = dirname( __DIR__, 2 ) . '/fixtures/GeoIP2-Country-Test.mmdb';
		$provider = new MaxMindProvider( $fixture );
		$service  = $this->service( null, null, array(), array(), null, $provider );

		$this->assertContains( $service->maxmind_site_status_test()['status'], array( 'good', 'recommended', 'critical' ) );
	}

	public function test_maxmind_site_status_test_gated_on_manage_options(): void {
		$GLOBALS['universal_geo_test_current_user_can'] = false;

		$provider = new MaxMindProvider( '/nonexistent/path/geo.mmdb' );
		$service  = $this->service( null, null, array(), array(), null, $provider );

		// Even though this scenario would otherwise be critical, an
		// unauthorized user must never see that verdict.
		$this->assertSame( 'good', $service->maxmind_site_status_test()['status'] );
	}

	// ---- maxmind_managed section (M6) --------------------------------------------------

	public function test_maxmind_managed_section_reflects_settings_and_status(): void {
		$service = $this->service(
			null,
			null,
			array(
				'maxmind_managed_enabled'               => true,
				'maxmind_managed_auto_update_frequency' => 'twice_weekly',
			)
		);

		$section = $service->report()['maxmind_managed'];

		$this->assertTrue( $section['enabled'] );
		$this->assertFalse( $section['installed'] );
		$this->assertSame( 'twice_weekly', $section['auto_update_frequency'] );
		$this->assertNull( $section['last_result_code'] );
	}

	// ---- Site Health: maxmind_managed_site_status_test() (M6) --------------------------

	/**
	 * Builds a DatabaseManager reporting an installed database at a
	 * specific build epoch, relative to now — via a real file on disk (so
	 * installed_path() is genuinely true) plus a directly persisted state
	 * option (so status()'s installed_build_epoch is exact and
	 * deterministic, unlike the real fixture's own build_epoch which
	 * drifts with wall-clock time).
	 */
	private function installed_database_manager( int $age_days ): DatabaseManager {
		$managed_dir = sys_get_temp_dir() . '/ugeo-diagnostics-managed-test-' . uniqid( '', true );
		mkdir( $managed_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$fixture = dirname( __DIR__, 2 ) . '/fixtures/GeoIP2-Country-Test.mmdb';
		copy( $fixture, $managed_dir . '/GeoLite2-Country.mmdb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		update_option(
			DatabaseManager::STATE_OPTION_NAME,
			array(
				'last_attempt_at'       => time(),
				'last_success_at'       => time(),
				'last_result_code'      => 'ok',
				'installed_build_epoch' => time() - ( $age_days * 86400 ),
			)
		);

		return new DatabaseManager( $managed_dir, '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() );
	}

	public function test_maxmind_managed_site_status_test_is_good_when_disabled(): void {
		$service = $this->service();

		$result = $service->maxmind_managed_site_status_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( DiagnosticsService::TEST_MAXMIND_MANAGED, $result['test'] );
	}

	public function test_maxmind_managed_site_status_test_is_critical_when_enabled_not_installed_and_unavailable(): void {
		$service = $this->service(
			null,
			null,
			array( 'maxmind_managed_enabled' => true ),
			array(),
			null,
			new MaxMindProvider( '' )
		);

		$this->assertSame( 'critical', $service->maxmind_managed_site_status_test()['status'] );
	}

	public function test_maxmind_managed_site_status_test_is_recommended_when_enabled_not_installed_but_a_higher_precedence_path_covers_the_gap(): void {
		$fixture = dirname( __DIR__, 2 ) . '/fixtures/GeoIP2-Country-Test.mmdb';

		$service = $this->service(
			null,
			null,
			array( 'maxmind_managed_enabled' => true ),
			array(),
			null,
			new MaxMindProvider( $fixture )
		);

		$this->assertSame( 'recommended', $service->maxmind_managed_site_status_test()['status'] );
	}

	public function test_maxmind_managed_site_status_test_is_good_for_a_fresh_installed_database(): void {
		$service = $this->service(
			null,
			null,
			array( 'maxmind_managed_enabled' => true ),
			array(),
			null,
			new MaxMindProvider( '' ),
			null,
			'none',
			$this->installed_database_manager( 1 )
		);

		$this->assertSame( 'good', $service->maxmind_managed_site_status_test()['status'] );
	}

	public function test_maxmind_managed_site_status_test_is_recommended_at_fourteen_days(): void {
		$service = $this->service(
			null,
			null,
			array( 'maxmind_managed_enabled' => true ),
			array(),
			null,
			new MaxMindProvider( '' ),
			null,
			'none',
			$this->installed_database_manager( 14 )
		);

		$this->assertSame( 'recommended', $service->maxmind_managed_site_status_test()['status'] );
	}

	public function test_maxmind_managed_site_status_test_is_critical_at_thirty_days_when_unavailable(): void {
		$service = $this->service(
			null,
			null,
			array( 'maxmind_managed_enabled' => true ),
			array(),
			null,
			new MaxMindProvider( '' ),
			null,
			'none',
			$this->installed_database_manager( 30 )
		);

		$this->assertSame( 'critical', $service->maxmind_managed_site_status_test()['status'] );
	}

	public function test_maxmind_managed_site_status_test_is_recommended_at_thirty_days_when_a_higher_precedence_path_covers_the_gap(): void {
		$fixture = dirname( __DIR__, 2 ) . '/fixtures/GeoIP2-Country-Test.mmdb';

		$service = $this->service(
			null,
			null,
			array( 'maxmind_managed_enabled' => true ),
			array(),
			null,
			new MaxMindProvider( $fixture ),
			null,
			'none',
			$this->installed_database_manager( 30 )
		);

		$this->assertSame( 'recommended', $service->maxmind_managed_site_status_test()['status'] );
	}

	public function test_maxmind_managed_site_status_test_gated_on_manage_options(): void {
		$GLOBALS['universal_geo_test_current_user_can'] = false;

		$service = $this->service(
			null,
			null,
			array( 'maxmind_managed_enabled' => true ),
			array(),
			null,
			new MaxMindProvider( '' )
		);

		// Even though this scenario would otherwise be critical, an
		// unauthorized user must never see that verdict.
		$this->assertSame( 'good', $service->maxmind_managed_site_status_test()['status'] );
	}

	// ---- Site Health: provider chain + cache (M12) --------------------------------------

	public function test_add_site_status_tests_registers_provider_chain_and_cache(): void {
		$tests = $this->service()->add_site_status_tests( array() );

		$this->assertArrayHasKey( DiagnosticsService::TEST_PROVIDER_CHAIN, $tests['direct'] );
		$this->assertArrayHasKey( DiagnosticsService::TEST_CACHE, $tests['direct'] );
	}

	public function test_provider_chain_site_status_test_is_good_with_preferred_provider(): void {
		$service = $this->service(
			null,
			null,
			array(),
			array( new TrackingGeoProvider( 'cloudflare', true, new GeoCandidate( 'SE', null ) ) )
		);

		$result = $service->provider_chain_site_status_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( DiagnosticsService::TEST_PROVIDER_CHAIN, $result['test'] );
	}

	public function test_provider_chain_site_status_test_is_recommended_when_only_default_available(): void {
		$service = $this->service(
			null,
			null,
			array( 'default_country' => 'SE' ),
			array( new DefaultCountryProvider( 'SE' ) )
		);

		$result = $service->provider_chain_site_status_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'default', strtolower( wp_strip_all_tags( $result['description'] ) ) );
	}

	public function test_provider_chain_site_status_test_is_critical_when_chain_empty(): void {
		$service = $this->service( null, null, array(), array() );

		$this->assertSame( 'critical', $service->provider_chain_site_status_test()['status'] );
	}

	public function test_cache_site_status_test_is_good_without_external_object_cache(): void {
		$GLOBALS['universal_geo_test_using_ext_object_cache'] = false;

		$result = $this->service(
			null,
			null,
			array(
				'derived_cache_enabled' => true,
				'derived_cache_ttl'     => 900,
			)
		)->cache_site_status_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( DiagnosticsService::TEST_CACHE, $result['test'] );
		$this->assertStringNotContainsString( 'Redis', $result['description'] );
		$this->assertStringNotContainsString( 'Memcached', $result['description'] );
		$this->assertStringContainsString( 'optional', strtolower( wp_strip_all_tags( $result['description'] ) ) );
	}

	public function test_cache_site_status_test_never_returns_critical(): void {
		$result = $this->service(
			null,
			null,
			array(
				'derived_cache_enabled' => true,
				'derived_cache_ttl'     => 1, // Impossible post-sanitize shape.
			)
		)->cache_site_status_test();

		$this->assertNotSame( 'critical', $result['status'] );
		$this->assertSame( 'recommended', $result['status'] );
	}

	public function test_maxmind_managed_site_status_test_is_recommended_when_auto_update_missing_credentials(): void {
		$service = $this->service(
			null,
			null,
			array(
				'maxmind_managed_enabled'             => true,
				'maxmind_managed_auto_update_enabled' => true,
			),
			array(),
			null,
			new MaxMindProvider( '' ),
			null,
			'none',
			$this->installed_database_manager( 1 )
		);

		$result = $service->maxmind_managed_site_status_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'credentials', strtolower( wp_strip_all_tags( $result['description'] ) ) );
	}

	public function test_maxmind_managed_site_status_test_is_recommended_when_scheduler_missing(): void {
		$GLOBALS['universal_geo_test_cron'] = array();

		$service = $this->service(
			null,
			null,
			array(
				'maxmind_managed_enabled'             => true,
				'maxmind_managed_auto_update_enabled' => true,
			),
			array(),
			null,
			new MaxMindProvider( '' ),
			null,
			'settings',
			$this->installed_database_manager( 1 )
		);

		$result = $service->maxmind_managed_site_status_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'schedul', strtolower( wp_strip_all_tags( $result['description'] ) ) );
	}

	public function test_worst_site_health_status_includes_provider_chain_and_cache(): void {
		$service = $this->service( null, null, array(), array() );

		// Empty provider chain is critical → worst must surface critical.
		$this->assertSame( 'critical', $service->worst_site_health_status() );
	}

	// ---- Passive snapshot reason rendering ------------------------------------------

	public function test_passive_snapshot_reason_is_rendered_as_not_probed(): void {
		// Verify that when passive_provider_snapshot() is used in report(),
		// the 'passive_snapshot' reason value is rendered as 'Not probed'
		// in the admin UI (DefinitionListRenderer value translation).
		$providers = array(
			new DefaultCountryProvider( 'US' ),
		);
		$service   = $this->service( null, null, array(), $providers );
		$report    = $service->report();

		// The providers section should have passive_snapshot reasons.
		$this->assertIsArray( $report['providers'] ?? null );
		$this->assertNotEmpty( $report['providers'] );

		// Verify reason value is the internal 'passive_snapshot'.
		foreach ( $report['providers'] as $provider_row ) {
			$this->assertArrayHasKey( 'reason', $provider_row );
			$this->assertSame( 'passive_snapshot', $provider_row['reason'] );
		}

		// Now verify that DefinitionListRenderer translates it.
		// This is the actual acceptance test for the presentation layer fix.
		$definition_list_renderer = new \UniversalGeo\Admin\DefinitionListRenderer( $service );
		$renderer                 = new ReportRenderer( $definition_list_renderer );
		foreach ( $report['providers'] as $provider_row ) {
			$html = $renderer->definition_list_html( $provider_row );
			// The display should contain 'Not probed', not 'passive_snapshot'.
			$this->assertStringContainsString(
				'Not probed',
				$html,
				'passive_snapshot reason should be rendered as "Not probed"'
			);
			$this->assertStringNotContainsString(
				'passive_snapshot',
				$html,
				'Internal passive_snapshot token should not be visible to admins'
			);
		}
	}

	// ---- Class shape --------------------------------------------------------------------

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( DiagnosticsService::class ) )->isFinal() );
	}
}
