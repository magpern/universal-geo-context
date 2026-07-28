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
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Plugin;
use UniversalGeo\Settings;

/**
 * Covers the M1 runtime composition root: lazy resolver construction (built
 * in init(), never resolved until context() is actually called), the
 * default-country-only object graph, and the universal_geo_context /
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

		$this->reset_plugin_singleton();

		if ( array_key_exists( 'REMOTE_ADDR', $_SERVER ) ) {
			$this->original_remote_addr = $_SERVER['REMOTE_ADDR'];
		}
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		if ( isset( $this->original_remote_addr ) ) {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
			unset( $this->original_remote_addr );
		}

		$this->reset_plugin_singleton();

		parent::tearDown();
	}

	/**
	 * @var string
	 */
	private $original_remote_addr;

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
}
