<?php
/**
 * Integration: exact-once probe call counting for v1.8.1 acceptance.
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
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationCookie;
use UniversalGeo\Simulation\SimulationState;
use WP_UnitTestCase;

/**
 * Authoritative integration proof for the passive-diagnostics invariant
 * that `PassiveDiagnosticsGuardTest::test_report_never_probes()` (unit
 * suite) explicitly defers to "integration tests using spy transport
 * call counting".
 *
 * Counts real outbound HTTP via the `pre_http_request` filter — the same
 * seam `PluginRemoteProviderTest` uses — on the real object graph built
 * by `Plugin::instance()->init()` / `build_graph()`, with the remote
 * provider enabled and credentialed so `ContextResolver::probe()` has a
 * live transport call to make. The visitor context is resolved once up
 * front (mirroring the first real pageview) so its own, unrelated
 * `resolve()` call — cached via `GeoCache` — cannot be mistaken for a
 * `probe()` call in the counts that follow.
 *
 * @internal
 */
final class DiagnosticsProbeExactOnceTest extends WP_UnitTestCase {

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
	 * Builds the real DiagnosticsService and ContextResolver Plugin::init()
	 * would have constructed, via the same private build_graph() the admin
	 * registration path uses (identical to PluginRemoteProviderTest's helper).
	 *
	 * @return array{diagnostics: DiagnosticsService, resolver: \UniversalGeo\Resolver\ContextResolver}
	 */
	private function real_graph(): array {
		$reflection = new ReflectionClass( Plugin::class );
		$method     = $reflection->getMethod( 'build_graph' );
		$method->setAccessible( true );
		$graph = $method->invoke( Plugin::instance() );

		$diagnostics = new DiagnosticsService(
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
			$graph['maxmind_path_source'],
			$graph['cache'],
			$graph['update_scheduler'],
			new SimulationState( new SimulationCookie(), new SimulationAuthorization() )
		);

		return array(
			'diagnostics' => $diagnostics,
			'resolver'    => $graph['resolver'],
		);
	}

	/**
	 * Walks a full admin diagnostics session in order — passive load,
	 * explicit refresh, PRG landing, repeated passive reload — asserting
	 * the live-transport call count at every step, exactly as v1.8.1's
	 * acceptance requires:
	 *
	 * 1. passive DiagnosticsService::report()      -> 0 calls
	 * 2. explicit resolver->probe() (admin refresh) -> exactly 1 call
	 * 3. PRG landing report() (passive follow-up)   -> 0 additional calls
	 * 4. three more passive report() reloads        -> 0 additional calls
	 *
	 * @return void
	 */
	public function test_diagnostics_session_probes_exactly_once_on_explicit_refresh_only(): void {
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

		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"country":{"iso_code":"DE"}}',
				);
			},
			10,
			3
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->reset_plugin_singleton();
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		Plugin::instance()->init();

		// Pre-warm: resolve the visitor context once, exactly like the
		// first real pageview would (via the public API, memoized on the
		// Plugin singleton and cached in GeoCache). This is the resolver's
		// resolve() path, not probe() — it is expected to make its own,
		// unrelated live call, which must not be counted as a "probe".
		universal_geo_get_context();
		$this->assertSame( 1, $call_count, 'Precondition: the pre-warm context resolution made its one expected live call.' );
		$call_count = 0;

		$graph = $this->real_graph();

		// 1. Passive load: DiagnosticsService::report() must make zero
		// live transport calls (docblock: "Never calls
		// ContextResolver::probe() — passive reporting only").
		$report = $graph['diagnostics']->report();
		$this->assertIsArray( $report );
		$this->assertArrayHasKey( 'providers', $report );
		$this->assertSame( 0, $call_count, 'Passive report() must make zero live transport calls.' );

		// 2. Explicit refresh: the admin_post handler
		// (OverviewPage::handle_refresh_providers()) calls
		// $this->resolver->probe() directly — exactly what is invoked here.
		$graph['resolver']->probe();
		$this->assertSame( 1, $call_count, 'An explicit provider refresh must make exactly one live transport call.' );

		// 3. PRG landing GET: passive follow-up report() must add zero
		// further calls beyond the explicit refresh above.
		$graph['diagnostics']->report();
		$this->assertSame( 1, $call_count, 'The PRG landing report() must add zero additional live transport calls.' );

		// 4. Repeated passive reloads (e.g. page refreshes): zero further
		// calls each time — this is the double-probe regression this
		// milestone fixes.
		for ( $i = 0; $i < 3; $i++ ) {
			$graph['diagnostics']->report();
		}
		$this->assertSame( 1, $call_count, 'Repeated passive report() reloads must add zero additional live transport calls.' );
	}
}
