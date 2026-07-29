<?php
/**
 * End-to-end live-flow acceptance test.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration;

use ReflectionClass;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\Plugin;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Settings;
use WP_UnitTestCase;

/**
 * A consolidated end-to-end check exercising the full, real plugin
 * bootstrap (`universal-geo-context.php` + `Plugin::init()`, not a
 * hand-assembled object graph) against a real WordPress + MySQL backend —
 * the M3 architecture report's live-site acceptance checklist, run here
 * rather than against a live WordPress instance: the plugin is not deployed
 * to any live site on this VPS (no bind-mount, no activation anywhere under
 * `data/wordpress/html/wp-content/plugins/`), so this integration
 * environment (real WordPress core, real MySQL, the real MaxMind reader
 * library, the real public test `.mmdb`) is the closest available
 * substitute and covers every item on that checklist except two
 * production-environment facts a real deployment alone can answer: whether
 * a persistent object cache (e.g. Redis) is active, and whether a
 * reader-bearing plugin happens to be active on that specific site.
 */
final class LiveAcceptanceTest extends WP_UnitTestCase {

	private const COUNTRY_DB = __DIR__ . '/../fixtures/GeoIP2-Country-Test.mmdb';

	private function reset_plugin_singleton(): void {
		$reflection = new ReflectionClass( Plugin::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	public function test_full_plugin_bootstrap_end_to_end_with_a_real_maxmind_database(): void {
		Plugin::activate();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Configure a real .mmdb under wp-content/uploads/ via the real Settings API.
		$upload_dir = wp_upload_dir();
		$target     = rtrim( $upload_dir['basedir'], '/' ) . '/geo-live-acceptance.mmdb';
		copy( self::COUNTRY_DB, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		Settings::save( array( 'maxmind_db_path' => $target ) );
		GeoCache::bump_epoch();

		$this->reset_plugin_singleton();
		$_SERVER['REMOTE_ADDR'] = '214.78.120.1';
		$plugin                 = Plugin::instance();
		$plugin->init();

		// Known-country resolution through the real public API.
		$context = universal_geo_get_context();
		$this->assertSame( 'US', $context->country_code );
		$this->assertSame( 'maxmind', $context->source );
		$this->assertSame( 0.90, $context->confidence );

		// Diagnostics metadata (a fresh, identically-configured MaxMindProvider
		// stands in for the one the built graph injects into DiagnosticsService,
		// since Plugin does not expose its internal graph publicly).
		$provider = new MaxMindProvider( $target );
		$metadata = $provider->metadata();
		$this->assertNotNull( $metadata );
		$this->assertSame( 'GeoIP2-Country', $metadata->database_type );

		// Site Health status. The public MaxMind test fixture is deliberately
		// old — the M3 architecture report's own "exercises the critical
		// path honestly" acceptance note, not a bug: a real GeoLite2
		// database kept up to date would report 'good' instead.
		$trusted     = new TrustedProxies( array(), false );
		$request     = ServerRequest::capture( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ip_resolver = new ClientIpResolver( $request, $trusted );
		$resolver    = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$diagnostics = new DiagnosticsService(
			$resolver,
			$ip_resolver,
			$request,
			$trusted,
			array(),
			new ProviderHealthStore(),
			$provider
		);
		$this->assertSame( 'critical', $diagnostics->maxmind_site_status_test()['status'] );

		// A second identical lookup within the same request reuses the
		// already-memoized VisitorContext instance rather than re-resolving.
		$this->assertSame( $context, universal_geo_get_context() );

		// A settings save invalidates via the epoch: a new Plugin instance
		// resolving the same IP after a bump must not carry over any stale
		// state and still resolves correctly.
		$this->reset_plugin_singleton();
		Settings::save(
			array(
				'maxmind_db_path' => $target,
				'default_country' => 'SE',
			)
		);
		GeoCache::bump_epoch();
		Plugin::instance()->init();
		$this->assertSame( 'US', universal_geo_get_context()->country_code );

		// A corrupt database degrades safely — the page request completes,
		// never a fatal — and falls through to the next provider (none
		// available in this configuration, so the visitor is unknown).
		$corrupt = rtrim( WP_CONTENT_DIR, '/' ) . '/geo-corrupt-live-acceptance.mmdb';
		file_put_contents( $corrupt, 'not a real mmdb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		Settings::save( array( 'maxmind_db_path' => $corrupt ) );
		GeoCache::bump_epoch();
		$this->reset_plugin_singleton();
		Plugin::instance()->init();
		$this->assertFalse( universal_geo_get_context()->is_known() );

		// The provider-health record for that failure is bounded and scrubbed.
		$health_option = get_option( ProviderHealthStore::OPTION_NAME );
		$this->assertIsArray( $health_option );
		$this->assertArrayHasKey( 'maxmind', $health_option );
		$this->assertSame(
			array( 'last_error_class', 'last_error_message', 'approx_count', 'last_seen_at' ),
			array_keys( $health_option['maxmind'] )
		);
		$this->assertStringNotContainsString( '214.78.120.1', $health_option['maxmind']['last_error_message'] );

		unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $corrupt ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}
