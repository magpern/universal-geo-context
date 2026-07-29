<?php
/**
 * Unit tests for UniversalGeo\Plugin.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Model\GeoCandidate;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Plugin;
use UniversalGeo\Settings;
use UniversalGeo\Tests\Unit\Doubles\TrackingGeoProvider;

/**
 * Covers the runtime composition root: lazy resolver construction (built in
 * init(), never resolved until context() is actually called), the M2 object
 * graph (TrustedProxies, ClientIpResolver, CloudflareHeaderProvider,
 * WooCommerceProvider, DefaultCountryProvider), the universal_geo_providers
 * and universal_geo_default_country filters, the universal_geo_provider_failed
 * action, the expanded config_sig, and the universal_geo_context /
 * universal_geo_context_resolved hooks that fire at the context() boundary
 * (not inside the frozen ContextResolver).
 */
final class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['universal_geo_test_options']                = array();
		$GLOBALS['universal_geo_test_object_cache']           = array();
		$GLOBALS['universal_geo_test_object_cache_calls']     = array();
		$GLOBALS['universal_geo_test_using_ext_object_cache'] = true;
		$GLOBALS['universal_geo_test_filters']                = array();
		$GLOBALS['universal_geo_test_actions']                = array();
		$GLOBALS['universal_geo_test_is_admin']               = false;

		$this->reset_plugin_singleton();

		// Snapshot the whole superglobal, not just REMOTE_ADDR: M2 tests
		// set forwarding headers too (HTTP_CF_IPCOUNTRY, HTTP_X_REAL_IP),
		// and Plugin::build_resolver() now reads $_SERVER wholesale via
		// ServerRequest::capture().
		$this->original_server = $_SERVER;
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	protected function tearDown(): void {
		$_SERVER = $this->original_server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->reset_plugin_singleton();

		parent::tearDown();
	}

	/**
	 * @var array<string, mixed>
	 */
	private $original_server;

	private function reset_plugin_singleton(): void {
		$reflection = new ReflectionClass( Plugin::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	private function set_default_country( string $country ): void {
		$GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ] = array(
			'schema_version'  => Settings::SCHEMA_VERSION,
			'default_country' => $country,
		);
	}

	/**
	 * @param string   $default_country  Default country setting.
	 * @param string[] $trusted_proxies  Trusted CIDRs setting.
	 * @param bool     $trust_cloudflare Cloudflare preset setting.
	 */
	private function set_settings(
		string $default_country = '',
		array $trusted_proxies = array(),
		bool $trust_cloudflare = false,
		string $maxmind_db_path = ''
	): void {
		$GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ] = array(
			'schema_version'        => Settings::SCHEMA_VERSION,
			'default_country'       => $default_country,
			'trusted_proxies'       => $trusted_proxies,
			'trust_cloudflare'      => $trust_cloudflare,
			'derived_cache_enabled' => true,
			'derived_cache_ttl'     => 900,
			'maxmind_db_path'       => $maxmind_db_path,
		);
	}

	// ---- Lazy composition ----------------------------------------------------

	public function test_instance_returns_the_same_object_every_call(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_context_before_init_returns_unknown(): void {
		set_error_handler( static fn() => true, E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		$context = Plugin::instance()->context();
		restore_error_handler();

		$this->assertFalse( $context->is_known() );
	}

	public function test_context_before_init_does_not_touch_the_cache(): void {
		// Swallow _doing_it_wrong()'s E_USER_WARNING directly, rather than
		// via expectWarning(), so the assertion below can still run
		// afterward in the same test. Test-only; not production debug code.
		set_error_handler( static fn() => true, E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		Plugin::instance()->context();
		restore_error_handler();

		$this->assertSame( array(), $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	public function test_init_does_not_resolve_anything(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		Plugin::instance()->init();

		$this->assertSame( array(), $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	public function test_init_is_idempotent(): void {
		$plugin = Plugin::instance();
		$plugin->init();
		$plugin->init();

		$this->assertFalse( $plugin->context()->is_known() ); // no fixture set; just confirms no error from double-init.
	}

	// ---- Resolution through the real M1 object graph --------------------------

	public function test_empty_default_country_produces_unknown(): void {
		$this->set_default_country( '' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$plugin = Plugin::instance();
		$plugin->init();

		$this->assertFalse( $plugin->context()->is_known() );
	}

	public function test_configured_default_country_resolves(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'SE', $context->country_code );
		$this->assertSame( 'default', $context->source );
		$this->assertSame( 0.10, $context->confidence );
		$this->assertFalse( $context->is_cached );
	}

	public function test_missing_remote_addr_produces_unknown(): void {
		$this->set_default_country( 'SE' );
		// REMOTE_ADDR intentionally left unset.

		$plugin = Plugin::instance();
		$plugin->init();

		$this->assertFalse( $plugin->context()->is_known() );
	}

	// ---- GeoCache wiring (enabled, TTL 900, deterministic config_sig) ---------

	public function test_cache_is_active_and_shared_across_plugin_instances(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$first = Plugin::instance();
		$first->init();
		$first->context();

		$this->reset_plugin_singleton();

		$second = Plugin::instance();
		$second->init();
		$context = $second->context();

		// A second, independently-constructed Plugin/resolver graph hit the
		// first one's cache entry — proving GeoCache is active (enabled by
		// default) and its config_sig is deterministic for the same settings.
		$this->assertTrue( $context->is_cached );
		$this->assertSame( 'SE', $context->country_code );
	}

	public function test_different_default_country_produces_a_different_config_sig(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$first = Plugin::instance();
		$first->init();
		$first->context();

		$this->reset_plugin_singleton();
		$this->set_default_country( 'DE' );

		$second = Plugin::instance();
		$second->init();
		$context = $second->context();

		// A different resolution-affecting setting must not reuse the old
		// config_sig's cache entry.
		$this->assertFalse( $context->is_cached );
		$this->assertSame( 'DE', $context->country_code );
	}

	public function test_external_object_cache_absent_is_a_safe_no_op(): void {
		$GLOBALS['universal_geo_test_using_ext_object_cache'] = false;
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'SE', $context->country_code );
		$this->assertFalse( $context->is_cached );
		$this->assertSame( array(), $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	public function test_composition_does_not_mutate_settings(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		$before                 = $GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ];

		$plugin = Plugin::instance();
		$plugin->init();
		$plugin->context();

		// GeoCache legitimately adds its own, separate 'universal_geo_cache_salt'
		// option (lazy salt generation) — that is not a Settings mutation.
		// Only the settings option itself must stay untouched.
		$this->assertSame( $before, $GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ] );
	}

	// ---- M2: TrustedProxies + ClientIpResolver + CloudflareHeaderProvider wiring ----

	public function test_cloudflare_provider_resolves_when_peer_is_a_real_cloudflare_address(): void {
		$this->set_settings( '', array(), true );
		$_SERVER['REMOTE_ADDR']       = '173.245.48.1'; // A real Cloudflare-owned address.
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'SE';

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'SE', $context->country_code );
		$this->assertSame( 'cloudflare', $context->source );
		$this->assertSame( 0.95, $context->confidence );
	}

	public function test_cloudflare_provider_resolves_in_chained_mode(): void {
		// Chained topology: a configured trusted proxy (itself not a
		// Cloudflare address) sits between Cloudflare and PHP.
		$this->set_settings( '', array( '172.18.0.0/16' ), true );
		$_SERVER['REMOTE_ADDR']       = '172.18.0.5';
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'DE', $context->country_code );
		$this->assertSame( 'cloudflare', $context->source );
	}

	public function test_cloudflare_provider_does_not_fire_when_preset_disabled(): void {
		$this->set_settings( 'SE', array(), false );
		$_SERVER['REMOTE_ADDR']       = '173.245.48.1';
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		// Falls through to the default-country provider instead.
		$this->assertSame( 'SE', $context->country_code );
		$this->assertSame( 'default', $context->source );
	}

	public function test_untrusted_peer_ignores_cloudflare_header_entirely(): void {
		$this->set_settings( 'SE', array(), true );
		$_SERVER['REMOTE_ADDR']       = '198.51.100.9'; // Not a Cloudflare address.
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'SE', $context->country_code );
		$this->assertSame( 'default', $context->source );
	}

	// ---- universal_geo_default_country filter ----------------------------------

	public function test_default_country_filter_overrides_the_configured_value(): void {
		$this->set_settings( '' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		add_filter( 'universal_geo_default_country', static fn() => 'DE' );

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'DE', $context->country_code );
		$this->assertSame( 'default', $context->source );
	}

	public function test_default_country_filter_receives_the_configured_value(): void {
		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$received = null;
		add_filter(
			'universal_geo_default_country',
			function ( $value ) use ( &$received ) {
				$received = $value;
				return $value;
			}
		);

		$plugin = Plugin::instance();
		$plugin->init();

		$this->assertSame( 'SE', $received );
	}

	public function test_default_country_filter_invalid_result_falls_back_to_the_configured_value(): void {
		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		add_filter( 'universal_geo_default_country', static fn() => 'not-a-country' );

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'SE', $context->country_code );
	}

	public function test_default_country_filter_non_string_result_falls_back_to_the_configured_value(): void {
		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		add_filter( 'universal_geo_default_country', static fn() => 12345 );

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'SE', $context->country_code );
	}

	// ---- universal_geo_providers filter -----------------------------------------

	public function test_providers_filter_can_add_a_higher_priority_provider(): void {
		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$injected = new TrackingGeoProvider( 'injected', true, new GeoCandidate( 'FR', null ) );
		add_filter(
			'universal_geo_providers',
			static function ( array $providers ) use ( $injected ): array {
				array_unshift( $providers, $injected );
				return $providers;
			}
		);

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'FR', $context->country_code );
		$this->assertSame( 'injected', $context->source );
	}

	public function test_providers_filter_receives_the_default_providers_in_order(): void {
		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$received_ids = null;
		add_filter(
			'universal_geo_providers',
			static function ( array $providers ) use ( &$received_ids ): array {
				$received_ids = array_map( static fn( GeoProviderInterface $p ) => $p->get_id(), $providers );
				return $providers;
			}
		);

		$plugin = Plugin::instance();
		$plugin->init();

		$this->assertSame( array( 'cloudflare', 'maxmind', 'woocommerce', 'remote', 'default' ), $received_ids );
	}

	public function test_providers_filter_non_conforming_element_is_dropped_without_fatal(): void {
		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		add_filter(
			'universal_geo_providers',
			static function ( array $providers ): array {
				$providers[] = 'not-a-provider';
				return $providers;
			}
		);

		set_error_handler( static fn() => true, E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();
		restore_error_handler();

		// The rest of the chain still works — a garbage filter element never
		// reaches ContextResolver's throwing constructor.
		$this->assertSame( 'SE', $context->country_code );
	}

	public function test_providers_filter_non_array_result_falls_back_to_the_default_providers(): void {
		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		add_filter( 'universal_geo_providers', static fn() => 'not-an-array' );

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'SE', $context->country_code );
	}

	// ---- universal_geo_provider_failed action -----------------------------------

	public function test_provider_failed_action_fires_with_the_provider_id_and_a_reason(): void {
		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$throwing = new TrackingGeoProvider( 'throwing-provider', true, null, true );
		add_filter(
			'universal_geo_providers',
			static function ( array $providers ) use ( $throwing ): array {
				array_unshift( $providers, $throwing );
				return $providers;
			}
		);

		$received = null;
		add_action(
			'universal_geo_provider_failed',
			static function ( $provider_id, $reason ) use ( &$received ) {
				$received = array( $provider_id, $reason );
			}
		);

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'throwing-provider', $received[0] );
		$this->assertStringContainsString( 'RuntimeException', $received[1] );
		// The chain continues past the throwing provider to the default.
		$this->assertSame( 'SE', $context->country_code );
	}

	/**
	 * M3 F2: the failure callback must both fire the action (BC, verified
	 * above) AND record into ProviderHealthStore — verified here via the
	 * store's own option, since Plugin does not expose the store instance
	 * publicly.
	 */
	public function test_provider_failure_is_also_recorded_into_the_provider_health_store(): void {
		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$throwing = new TrackingGeoProvider( 'throwing-provider', true, null, true );
		add_filter(
			'universal_geo_providers',
			static function ( array $providers ) use ( $throwing ): array {
				array_unshift( $providers, $throwing );
				return $providers;
			}
		);

		$plugin = Plugin::instance();
		$plugin->init();
		$plugin->context();

		$stored = $GLOBALS['universal_geo_test_options']['universal_geo_provider_health'] ?? null;

		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'throwing-provider', $stored );
		$this->assertSame( 'RuntimeException', $stored['throwing-provider']['last_error_class'] );
	}

	public function test_successful_resolution_never_writes_to_the_provider_health_store(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$plugin = Plugin::instance();
		$plugin->init();
		$plugin->context();

		$this->assertArrayNotHasKey( 'universal_geo_provider_health', $GLOBALS['universal_geo_test_options'] );
	}

	// ---- config_sig covers the new trust-boundary settings ------------------------

	public function test_different_trusted_proxies_produce_a_different_config_sig(): void {
		$this->set_settings( 'SE', array( '172.18.0.0/16' ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$first = Plugin::instance();
		$first->init();
		$first->context();

		$this->reset_plugin_singleton();
		$this->set_settings( 'SE', array( '10.0.0.0/8' ) );

		$second = Plugin::instance();
		$second->init();
		$context = $second->context();

		$this->assertFalse( $context->is_cached );
	}

	public function test_different_trust_cloudflare_produces_a_different_config_sig(): void {
		$this->set_settings( 'SE', array(), false );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$first = Plugin::instance();
		$first->init();
		$first->context();

		$this->reset_plugin_singleton();
		$this->set_settings( 'SE', array(), true );

		$second = Plugin::instance();
		$second->init();
		$context = $second->context();

		$this->assertFalse( $context->is_cached );
	}

	public function test_different_maxmind_db_path_produces_a_different_config_sig(): void {
		// Both must resolve (realpath + WP_CONTENT_DIR containment) to a
		// non-empty effective path for the sig inputs to actually differ —
		// a nonexistent path degrades to '' regardless of what was configured.
		$path_a = WP_CONTENT_DIR . '/plugin-test-geo-a.mmdb';
		$path_b = WP_CONTENT_DIR . '/plugin-test-geo-b.mmdb';
		file_put_contents( $path_a, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path_b, 'b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->set_settings( 'SE', array(), false, $path_a );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$first = Plugin::instance();
		$first->init();
		$first->context();

		$this->reset_plugin_singleton();
		$this->set_settings( 'SE', array(), false, $path_b );

		$second = Plugin::instance();
		$second->init();
		$context = $second->context();

		unlink( $path_a ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $path_b ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertFalse( $context->is_cached );
	}

	public function test_identical_maxmind_db_path_shares_the_same_config_sig(): void {
		$path = WP_CONTENT_DIR . '/plugin-test-geo.mmdb';
		file_put_contents( $path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->set_settings( 'SE', array(), false, $path );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$first = Plugin::instance();
		$first->init();
		$first->context();

		$this->reset_plugin_singleton();

		$second = Plugin::instance();
		$second->init();
		$context = $second->context();

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertTrue( $context->is_cached );
	}

	public function test_nonexistent_maxmind_db_path_resolves_to_empty_and_does_not_affect_config_sig(): void {
		// A settings value that fails containment/existence degrades to ''
		// at graph build — indistinguishable, for caching purposes, from no
		// path configured at all.
		$this->set_settings( 'SE', array(), false, '' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$first = Plugin::instance();
		$first->init();
		$first->context();

		$this->reset_plugin_singleton();
		$this->set_settings( 'SE', array(), false, WP_CONTENT_DIR . '/plugin-test-does-not-exist.mmdb' );

		$second = Plugin::instance();
		$second->init();
		$context = $second->context();

		$this->assertTrue( $context->is_cached );
	}

	public function test_identical_m2_settings_share_the_same_config_sig(): void {
		$this->set_settings( 'SE', array( '172.18.0.0/16' ), true );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$first = Plugin::instance();
		$first->init();
		$first->context();

		$this->reset_plugin_singleton();

		$second = Plugin::instance();
		$second->init();
		$context = $second->context();

		$this->assertTrue( $context->is_cached );
	}

	// ---- universal_geo_context filter -----------------------------------------

	public function test_context_filter_receives_the_resolved_context(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$received = null;
		add_filter(
			'universal_geo_context',
			function ( $context ) use ( &$received ) {
				$received = $context;
				return $context;
			}
		);

		$plugin = Plugin::instance();
		$plugin->init();
		$plugin->context();

		$this->assertInstanceOf( VisitorContext::class, $received );
		$this->assertSame( 'SE', $received->country_code );
	}

	public function test_context_filter_result_is_used(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$replacement = new VisitorContext( 'DE', null, 'default', 0.10 );
		add_filter(
			'universal_geo_context',
			static fn() => $replacement
		);

		$plugin = Plugin::instance();
		$plugin->init();

		$this->assertSame( 'DE', $plugin->context()->country_code );
	}

	public function test_context_filter_runs_on_a_cache_hit(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		// Warm the cache with an unfiltered first instance.
		$warm = Plugin::instance();
		$warm->init();
		$warm->context();
		$this->reset_plugin_singleton();

		$fired = false;
		add_filter(
			'universal_geo_context',
			static function ( $context ) use ( &$fired ) {
				$fired = true;
				return $context;
			}
		);

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertTrue( $context->is_cached );
		$this->assertTrue( $fired );
	}

	public function test_context_filter_fires_at_most_once_per_plugin_instance(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$calls = 0;
		add_filter(
			'universal_geo_context',
			static function ( $context ) use ( &$calls ) {
				++$calls;
				return $context;
			}
		);

		$plugin = Plugin::instance();
		$plugin->init();
		$plugin->context();
		$plugin->context();
		$plugin->context();

		$this->assertSame( 1, $calls );
	}

	public function test_invalid_non_object_filter_result_is_discarded(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		add_filter( 'universal_geo_context', static fn() => 'not-a-context' );

		$plugin = Plugin::instance();
		$plugin->init();

		set_error_handler( static fn() => true, E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		$context = $plugin->context();
		restore_error_handler();

		$this->assertSame( 'SE', $context->country_code );
	}

	public function test_filter_result_with_invalid_country_is_discarded(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		// 'XX' is structurally two uppercase letters (VisitorContext's own
		// constructor accepts it) but not a real ISO 3166-1 country —
		// exactly the case Revision 3 §14 requires re-validation to catch.
		add_filter( 'universal_geo_context', static fn() => new VisitorContext( 'XX', null, 'default', 0.10 ) );

		$plugin = Plugin::instance();
		$plugin->init();

		set_error_handler( static fn() => true, E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		$context = $plugin->context();
		restore_error_handler();

		$this->assertSame( 'SE', $context->country_code );
	}

	// ---- universal_geo_context_resolved action ---------------------------------

	public function test_resolved_action_receives_the_filtered_context(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$replacement = new VisitorContext( 'DE', null, 'default', 0.10 );
		add_filter( 'universal_geo_context', static fn() => $replacement );

		$received = null;
		add_action(
			'universal_geo_context_resolved',
			function ( $context ) use ( &$received ) {
				$received = $context;
			}
		);

		$plugin = Plugin::instance();
		$plugin->init();
		$plugin->context();

		$this->assertSame( $replacement, $received );
	}

	public function test_resolved_action_fires_for_unknown_contexts(): void {
		$this->set_default_country( '' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$fired    = false;
		$callback = static function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'universal_geo_context_resolved', $callback );

		$plugin = Plugin::instance();
		$plugin->init();
		$plugin->context();

		$this->assertTrue( $fired );
	}

	public function test_resolved_action_fires_at_most_once_per_plugin_instance(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$calls    = 0;
		$callback = static function () use ( &$calls ) {
			++$calls;
		};
		add_action( 'universal_geo_context_resolved', $callback );

		$plugin = Plugin::instance();
		$plugin->init();
		$plugin->context();
		$plugin->context();

		$this->assertSame( 1, $calls );
	}

	public function test_only_a_visitor_context_crosses_the_hook_boundary(): void {
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$argument_count = null;
		add_action(
			'universal_geo_context_resolved',
			function ( ...$args ) use ( &$argument_count ) {
				$argument_count = count( $args );
			}
		);

		$plugin = Plugin::instance();
		$plugin->init();
		$plugin->context();

		$this->assertSame( 1, $argument_count );
	}

	// ---- M2: admin registration (Revision 3 §4.5) ---------------------------------

	public function test_admin_services_are_not_registered_on_the_frontend(): void {
		$GLOBALS['universal_geo_test_is_admin'] = false;
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		Plugin::instance()->init();

		$this->assertArrayNotHasKey( 'admin_menu', $GLOBALS['universal_geo_test_actions'] );
	}

	public function test_admin_services_are_registered_on_an_admin_request(): void {
		$GLOBALS['universal_geo_test_is_admin'] = true;
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		Plugin::instance()->init();

		$this->assertArrayHasKey( 'admin_menu', $GLOBALS['universal_geo_test_actions'] );
		$this->assertArrayHasKey( 'admin_post_universal_geo_save_settings', $GLOBALS['universal_geo_test_actions'] );
	}

	public function test_admin_services_register_the_site_health_test(): void {
		$GLOBALS['universal_geo_test_is_admin'] = true;
		$this->set_default_country( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		Plugin::instance()->init();

		$this->assertArrayHasKey( 'site_status_tests', $GLOBALS['universal_geo_test_filters'] );
	}

	// ---- M3: resolved_maxmind_db_path() path-resolution chain -------------------

	private const FIXTURE_COUNTRY_DB = __DIR__ . '/../fixtures/GeoIP2-Country-Test.mmdb';

	/**
	 * @param array<string, mixed> $settings The sanitized settings array.
	 */
	private function resolved_maxmind_db_path( array $settings ): string {
		$reflection = new ReflectionMethod( Plugin::class, 'resolved_maxmind_db_path' );
		$reflection->setAccessible( true );

		return $reflection->invoke( Plugin::instance(), $settings );
	}

	private function base_settings(
		string $maxmind_db_path = '',
		string $remote_account_id = '',
		string $remote_license_key = ''
	): array {
		return array(
			'schema_version'               => Settings::SCHEMA_VERSION,
			'default_country'              => '',
			'trusted_proxies'              => array(),
			'trust_cloudflare'             => false,
			'derived_cache_enabled'        => true,
			'derived_cache_ttl'            => 900,
			'maxmind_db_path'              => $maxmind_db_path,
			'remote_enabled'               => false,
			'remote_account_id'            => $remote_account_id,
			'remote_license_key'           => $remote_license_key,
			'remote_transfer_acknowledged' => false,
		);
	}

	/**
	 * @param array<string, mixed> $settings The sanitized settings array.
	 *
	 * @return array{account_id: string, license_key: string, source: string}
	 */
	private function resolved_remote_credentials( array $settings ): array {
		$reflection = new ReflectionMethod( Plugin::class, 'resolved_remote_credentials' );
		$reflection->setAccessible( true );

		return $reflection->invoke( Plugin::instance(), $settings );
	}

	public function test_resolved_maxmind_db_path_empty_settings_and_no_wc_option_resolves_to_empty(): void {
		// wp_upload_dir() does not exist in this unit bootstrap (proven by
		// DiagnosticsServiceTest) — the WooCommerce auto-detect branch
		// degrades to '' rather than fatal, deterministically, here too.
		$this->assertFalse( function_exists( 'wp_upload_dir' ) );

		$this->assertSame( '', $this->resolved_maxmind_db_path( $this->base_settings() ) );
	}

	public function test_resolved_maxmind_db_path_accepts_a_path_contained_under_wp_content_dir(): void {
		$path = WP_CONTENT_DIR . '/plugin-resolution-test.mmdb';
		copy( self::FIXTURE_COUNTRY_DB, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$result = $this->resolved_maxmind_db_path( $this->base_settings( $path ) );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertSame( $path, $result );
	}

	public function test_resolved_maxmind_db_path_rejects_a_path_outside_wp_content_dir(): void {
		// The committed fixture lives under tests/fixtures/, never under the
		// fake WP_CONTENT_DIR fixture directory bootstrap.php defines.
		$result = $this->resolved_maxmind_db_path( $this->base_settings( self::FIXTURE_COUNTRY_DB ) );

		$this->assertSame( '', $result );
	}

	public function test_resolved_maxmind_db_path_rejects_a_nonexistent_settings_path(): void {
		$result = $this->resolved_maxmind_db_path( $this->base_settings( WP_CONTENT_DIR . '/does-not-exist.mmdb' ) );

		$this->assertSame( '', $result );
	}

	public function test_resolved_maxmind_db_path_filter_overrides_an_empty_result(): void {
		add_filter( 'universal_geo_maxmind_db_path', static fn() => self::FIXTURE_COUNTRY_DB );

		$result = $this->resolved_maxmind_db_path( $this->base_settings() );

		$this->assertSame( self::FIXTURE_COUNTRY_DB, $result );
	}

	public function test_resolved_maxmind_db_path_filter_receives_the_pre_filter_path(): void {
		$path = WP_CONTENT_DIR . '/plugin-resolution-filter-input-test.mmdb';
		copy( self::FIXTURE_COUNTRY_DB, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$received = null;
		add_filter(
			'universal_geo_maxmind_db_path',
			function ( $value ) use ( &$received ) {
				$received = $value;
				return $value;
			}
		);

		$this->resolved_maxmind_db_path( $this->base_settings( $path ) );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertSame( $path, $received );
	}

	public function test_resolved_maxmind_db_path_filter_non_string_result_is_discarded(): void {
		add_filter( 'universal_geo_maxmind_db_path', static fn() => array( 'not-a-string' ) );

		set_error_handler( static fn() => true, E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		$result = $this->resolved_maxmind_db_path( $this->base_settings() );
		restore_error_handler();

		$this->assertSame( '', $result );
	}

	public function test_maxmind_provider_resolves_via_full_plugin_wiring_using_the_filter(): void {
		// Proves the whole chain end-to-end: filter -> Plugin -> MaxMindProvider
		// -> ContextResolver -> VisitorContext, against the real public test
		// fixture (214.78.120.0/22 -> US, per tests/fixtures/README.md).
		add_filter( 'universal_geo_maxmind_db_path', static fn() => self::FIXTURE_COUNTRY_DB );

		$this->set_settings( '' );
		$_SERVER['REMOTE_ADDR'] = '214.78.120.1';

		$plugin = Plugin::instance();
		$plugin->init();
		$context = $plugin->context();

		$this->assertSame( 'US', $context->country_code );
		$this->assertSame( 'maxmind', $context->source );
		$this->assertSame( 0.90, $context->confidence );
	}

	public function test_maxmind_provider_slots_between_cloudflare_and_woocommerce(): void {
		$received_ids = null;
		add_filter(
			'universal_geo_providers',
			static function ( array $providers ) use ( &$received_ids ): array {
				$received_ids = array_map( static fn( GeoProviderInterface $p ) => $p->get_id(), $providers );
				return $providers;
			}
		);

		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$plugin = Plugin::instance();
		$plugin->init();

		$this->assertSame( array( 'cloudflare', 'maxmind', 'woocommerce', 'remote', 'default' ), $received_ids );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maxmind_constant_wins_over_settings_and_wc_and_bypasses_the_filter(): void {
		define( 'UNIVERSAL_GEO_MAXMIND_DB', '/etc/somewhere/outside/wp-content/geo.mmdb' );

		$filter_called = false;
		add_filter(
			'universal_geo_maxmind_db_path',
			static function ( $value ) use ( &$filter_called ) {
				$filter_called = true;
				return $value;
			}
		);

		$result = $this->resolved_maxmind_db_path( $this->base_settings( self::FIXTURE_COUNTRY_DB ) );

		$this->assertSame( '/etc/somewhere/outside/wp-content/geo.mmdb', $result );
		$this->assertFalse( $filter_called, 'The filter must not be consulted when the constant wins.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maxmind_constant_may_point_outside_wp_content_dir(): void {
		$outside = sys_get_temp_dir() . '/universal-geo-constant-test-' . uniqid() . '.mmdb';
		copy( self::FIXTURE_COUNTRY_DB, $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		define( 'UNIVERSAL_GEO_MAXMIND_DB', $outside );

		$result = $this->resolved_maxmind_db_path( $this->base_settings() );

		unlink( $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertSame( $outside, $result );
	}

	// ---- M4: resolved_remote_credentials() precedence chain --------------------

	public function test_remote_credentials_none_when_nothing_configured(): void {
		$result = $this->resolved_remote_credentials( $this->base_settings() );

		$this->assertSame(
			array(
				'account_id'  => '',
				'license_key' => '',
				'source'      => 'none',
			),
			$result
		);
	}

	public function test_remote_credentials_uses_the_settings_pair_when_both_present(): void {
		$result = $this->resolved_remote_credentials( $this->base_settings( '', '12345', 'abc123XYZ' ) );

		$this->assertSame(
			array(
				'account_id'  => '12345',
				'license_key' => 'abc123XYZ',
				'source'      => 'settings',
			),
			$result
		);
	}

	public function test_remote_credentials_none_when_only_account_id_is_present(): void {
		$result = $this->resolved_remote_credentials( $this->base_settings( '', '12345', '' ) );

		$this->assertSame( 'none', $result['source'] );
	}

	public function test_remote_credentials_none_when_only_license_key_is_present(): void {
		$result = $this->resolved_remote_credentials( $this->base_settings( '', '', 'abc123XYZ' ) );

		$this->assertSame( 'none', $result['source'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_remote_credentials_constants_win_over_settings(): void {
		define( 'UNIVERSAL_GEO_REMOTE_ACCOUNT_ID', 'constant-account' );
		define( 'UNIVERSAL_GEO_REMOTE_LICENSE_KEY', 'constant-license' );

		$result = $this->resolved_remote_credentials( $this->base_settings( '', 'settings-account', 'settings-license' ) );

		$this->assertSame(
			array(
				'account_id'  => 'constant-account',
				'license_key' => 'constant-license',
				'source'      => 'constants',
			),
			$result
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_remote_credentials_never_combines_one_constant_with_one_setting(): void {
		define( 'UNIVERSAL_GEO_REMOTE_ACCOUNT_ID', 'constant-account' );
		// UNIVERSAL_GEO_REMOTE_LICENSE_KEY intentionally left undefined.

		$result = $this->resolved_remote_credentials( $this->base_settings( '', '', 'settings-license' ) );

		// Falls through to the settings pair evaluation, which also fails
		// (account_id is '' in settings) — never a constant paired with a
		// setting.
		$this->assertSame( 'none', $result['source'] );
	}

	// ---- M4: config_sig covers the new remote-provider settings ----------------

	public function test_different_remote_enabled_produces_a_different_config_sig(): void {
		$this->set_settings( 'SE' );
		$GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ]['remote_enabled']               = false;
		$GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ]['remote_transfer_acknowledged'] = false;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$first = Plugin::instance();
		$first->init();
		$first->context();

		$this->reset_plugin_singleton();
		$GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ]['remote_enabled']               = true;
		$GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ]['remote_transfer_acknowledged'] = true;

		$second = Plugin::instance();
		$second->init();
		$context = $second->context();

		$this->assertFalse( $context->is_cached );
	}

	public function test_different_remote_credential_source_produces_a_different_config_sig(): void {
		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$first = Plugin::instance();
		$first->init();
		$first->context();

		$this->reset_plugin_singleton();
		$GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ]['remote_account_id']  = 'an-account';
		$GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ]['remote_license_key'] = 'a-license';

		$second = Plugin::instance();
		$second->init();
		$context = $second->context();

		$this->assertFalse( $context->is_cached );
	}

	public function test_identical_remote_settings_share_the_same_config_sig(): void {
		$this->set_settings( 'SE' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

		$first = Plugin::instance();
		$first->init();
		$first->context();

		$this->reset_plugin_singleton();

		$second = Plugin::instance();
		$second->init();
		$context = $second->context();

		$this->assertTrue( $context->is_cached );
	}
}
