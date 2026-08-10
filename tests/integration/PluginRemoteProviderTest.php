<?php
/**
 * Integration tests for the remote provider's full Plugin composition.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration;

use ReflectionClass;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Plugin;
use UniversalGeo\Settings;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationCookie;
use UniversalGeo\Simulation\SimulationState;
use WP_UnitTestCase;

/**
 * Covers the two end-to-end acceptance items the M4 report requires that
 * only a real WordPress bootstrap (real `Plugin::init()`, real
 * `wp_safe_remote_get()` seam via `pre_http_request`) can exercise:
 *
 * 1. The disabled-state outbound-HTTP trap: remote is disabled by default
 *    on a fresh install, and resolving a context — or running a full
 *    diagnostics probe(), which visits every provider regardless of
 *    availability — makes zero outbound HTTP requests.
 * 2. The enabled end-to-end canned-response path: once enabled,
 *    acknowledged, and credentialed, a real resolution through the real
 *    object graph reaches the remote provider and reports source='remote',
 *    confidence=0.85.
 *
 * No test in this class ever performs a real outbound HTTP request — every
 * `pre_http_request` filter here either fails the test if invoked (the trap
 * tests) or returns a canned response (the enabled-path test).
 */
final class PluginRemoteProviderTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		$this->reset_plugin_singleton();
		parent::tearDown();
	}

	private function reset_plugin_singleton(): void {
		$reflection = new ReflectionClass( Plugin::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * Builds the real DiagnosticsService instance Plugin::init() would have
	 * constructed, via the same private build_graph() the admin registration
	 * path uses — since Plugin does not expose its internal graph publicly.
	 */
	private function diagnostics(): DiagnosticsService {
		$reflection = new ReflectionClass( Plugin::class );
		$method     = $reflection->getMethod( 'build_graph' );
		$method->setAccessible( true );
		$graph = $method->invoke( Plugin::instance() );

		return new DiagnosticsService(
			$graph['resolver'],
			$graph['client_ip_resolver'],
			$graph['server_request'],
			$graph['trusted_proxies'],
			$graph['settings'],
			$graph['provider_health_store'],
			$graph['maxmind_provider'],
			$graph['circuit_breaker'],
			$graph['remote_credential_source'],
			$graph['database_manager'],
			$graph['maxmind_path_source']
		, new GeoCache( false, 900, 'sig' ), new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-m12-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ), new SimulationState( new SimulationCookie(), new SimulationAuthorization() ));
	}

	// ---- No-network Site Health assertions (M4) ----------------------------------

	public function test_remote_site_status_test_registers_as_the_third_test(): void {
		Plugin::activate();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->reset_plugin_singleton();
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		Plugin::instance()->init();

		$this->diagnostics()->register();
		$tests = apply_filters( 'site_status_tests', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own hook.

		$this->assertArrayHasKey( DiagnosticsService::TEST_TRUSTED_PROXY, $tests['direct'] );
		$this->assertArrayHasKey( DiagnosticsService::TEST_MAXMIND, $tests['direct'] );
		$this->assertArrayHasKey( DiagnosticsService::TEST_REMOTE, $tests['direct'] );
		$this->assertArrayHasKey( DiagnosticsService::TEST_MAXMIND_MANAGED, $tests['direct'] );
	}

	public function test_remote_site_status_test_makes_zero_outbound_http_when_enabled(): void {
		Plugin::activate();

		Settings::save(
			array(
				'remote_enabled'               => true,
				'remote_transfer_acknowledged' => true,
				'remote_account_id'            => 'test-account',
				'remote_license_key'           => 'test-license',
			)
		);
		GeoCache::bump_epoch();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		add_filter(
			'pre_http_request',
			function () {
				$this->fail( 'The remote-provider Site Health test must never perform an outbound HTTP request.' );
			},
			10,
			3
		);

		$this->reset_plugin_singleton();
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		Plugin::instance()->init();

		$result = $this->diagnostics()->remote_site_status_test();

		$this->assertContains( $result['status'], array( 'good', 'recommended' ) );
	}

	public function test_remote_site_status_test_never_returns_critical_when_enabled_without_credentials(): void {
		Plugin::activate();

		Settings::save(
			array(
				'remote_enabled'               => true,
				'remote_transfer_acknowledged' => true,
			)
		);
		GeoCache::bump_epoch();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->reset_plugin_singleton();
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		Plugin::instance()->init();

		$result = $this->diagnostics()->remote_site_status_test();

		$this->assertSame( 'recommended', $result['status'] );
	}

	// ---- The disabled-state outbound-HTTP trap -----------------------------------

	public function test_remote_is_disabled_by_default_and_resolution_makes_zero_outbound_http(): void {
		Plugin::activate();

		add_filter(
			'pre_http_request',
			function () {
				$this->fail( 'No outbound HTTP request may occur while the remote provider is disabled.' );
			},
			10,
			3
		);

		$this->reset_plugin_singleton();
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		Plugin::instance()->init();

		$context = universal_geo_get_context();

		$this->assertFalse( $context->is_known() );
	}

	public function test_diagnostics_probe_of_a_disabled_remote_provider_makes_zero_outbound_http(): void {
		Plugin::activate();

		add_filter(
			'pre_http_request',
			function () {
				$this->fail( 'No outbound HTTP request may occur while probing a disabled remote provider.' );
			},
			10,
			3
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->reset_plugin_singleton();
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		Plugin::instance()->init();

		// The Diagnostics tab's own report() calls ContextResolver::probe(),
		// which visits every provider — including a disabled remote one —
		// without short-circuiting.
		$reflection = new ReflectionClass( Plugin::class );
		$method     = $reflection->getMethod( 'build_graph' );
		$method->setAccessible( true );
		$graph = $method->invoke( Plugin::instance() );

		$probe      = $graph['resolver']->probe();
		$remote_row = null;

		foreach ( $probe as $row ) {
			if ( 'remote' === $row['provider'] ) {
				$remote_row = $row;
			}
		}

		$this->assertNotNull( $remote_row );
		$this->assertFalse( $remote_row['available'] );
		$this->assertSame( 'unavailable', $remote_row['reason'] );
	}

	// ---- The enabled end-to-end canned-response path -----------------------------

	public function test_enabled_remote_provider_resolves_end_to_end_with_a_canned_response(): void {
		Plugin::activate();

		Settings::save(
			array(
				'remote_enabled'               => true,
				'remote_transfer_acknowledged' => true,
				'remote_account_id'            => 'test-account',
				'remote_license_key'           => 'test-license',
			)
		);
		GeoCache::bump_epoch();

		add_filter(
			'pre_http_request',
			static fn () => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"country":{"iso_code":"DE"}}',
			),
			10,
			3
		);

		$this->reset_plugin_singleton();
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		Plugin::instance()->init();

		$context = universal_geo_get_context();

		$this->assertSame( 'DE', $context->country_code );
		$this->assertSame( 'remote', $context->source );
		$this->assertSame( 0.85, $context->confidence );
	}

	public function test_enabled_remote_provider_without_credentials_never_calls_the_transport(): void {
		Plugin::activate();

		// remote_enabled cannot itself sanitize to true without the
		// acknowledgement, and — separately — is_available() also requires
		// both credentials; this submission has the acknowledgement but no
		// credentials at all, so the provider must stay unavailable.
		Settings::save(
			array(
				'remote_enabled'               => true,
				'remote_transfer_acknowledged' => true,
			)
		);
		GeoCache::bump_epoch();

		add_filter(
			'pre_http_request',
			function () {
				$this->fail( 'No outbound HTTP request may occur without both credentials configured.' );
			},
			10,
			3
		);

		$this->reset_plugin_singleton();
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		Plugin::instance()->init();

		$context = universal_geo_get_context();

		$this->assertFalse( $context->is_known() );
	}
}
