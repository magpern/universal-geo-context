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
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\Model\GeoCandidate;
use UniversalGeo\Plugin;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Settings;
use UniversalGeo\Tests\Support\ServerRequestFactory;
use UniversalGeo\Tests\Unit\Doubles\TrackingGeoProvider;

/**
 * Covers the M2 report() sections, the trusted-proxy Site Health test, and
 * register(). MaxMind/remote report sections and the provider-health option
 * are out of scope (M3/M4).
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
		array $providers = array()
	): DiagnosticsService {
		$request         = ServerRequestFactory::make( '203.0.113.1' );
		$trusted_proxies = $trusted_proxies ?? new TrustedProxies( array(), false );
		$ip_resolver     = $ip_resolver ?? new ClientIpResolver( $request, $trusted_proxies );
		$resolver        = new ContextResolver( $ip_resolver, $providers, new GeoCache( false, 900, 'sig' ) );

		return new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted_proxies, $settings );
	}

	// ---- report() shape ---------------------------------------------------------

	public function test_report_contains_all_m2_sections(): void {
		$report = $this->service()->report();

		$this->assertSame(
			array(
				'result',
				'client_address',
				'trusted_proxies',
				'forwarding_headers',
				'cloudflare',
				'woocommerce',
				'providers',
				'cache',
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
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array() );

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

	// ---- providers section (probe() passthrough) ----------------------------------------

	public function test_providers_section_reflects_probe(): void {
		$provider = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SE', null ) );
		$report   = $this->service( null, null, array(), array( $provider ) )->report();

		$this->assertCount( 1, $report['providers'] );
		$this->assertSame( 'a', $report['providers'][0]['provider'] );
		$this->assertSame( 'ok', $report['providers'][0]['reason'] );
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

	// ---- Site Health: trusted_proxy_site_status_test() -----------------------------------

	public function test_site_status_test_is_critical_when_misconfigured(): void {
		// Forwarding header present, peer private, no trusted proxies.
		$request     = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '198.51.100.2' ) );
		$trusted     = new TrustedProxies( array(), false );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array() );

		$result = $service->trusted_proxy_site_status_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( DiagnosticsService::TEST_TRUSTED_PROXY, $result['test'] );
	}

	public function test_site_status_test_is_good_when_trusted_proxies_configured(): void {
		$request     = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '198.51.100.2' ) );
		$trusted     = new TrustedProxies( array( '172.18.0.0/16' ), false );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array() );

		$this->assertSame( 'good', $service->trusted_proxy_site_status_test()['status'] );
	}

	public function test_site_status_test_is_good_when_peer_is_public(): void {
		// Headers present but the peer is already public — no misconfiguration.
		$request     = ServerRequestFactory::make( '8.8.8.8', array( 'X-Real-IP' => '198.51.100.2' ) );
		$trusted     = new TrustedProxies( array(), false );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array() );

		$this->assertSame( 'good', $service->trusted_proxy_site_status_test()['status'] );
	}

	public function test_site_status_test_is_good_when_no_forwarding_headers_present(): void {
		$request     = ServerRequestFactory::make( '172.18.0.5' );
		$trusted     = new TrustedProxies( array(), false );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array() );

		$this->assertSame( 'good', $service->trusted_proxy_site_status_test()['status'] );
	}

	public function test_site_status_test_gated_on_manage_options(): void {
		$GLOBALS['universal_geo_test_current_user_can'] = false;

		$request     = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '198.51.100.2' ) );
		$trusted     = new TrustedProxies( array(), false );
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$service     = new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted, array() );

		// Even though this scenario would otherwise be critical, an
		// unauthorized user must never see that verdict.
		$this->assertSame( 'good', $service->trusted_proxy_site_status_test()['status'] );
	}

	// ---- Class shape --------------------------------------------------------------------

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( DiagnosticsService::class ) )->isFinal() );
	}
}
