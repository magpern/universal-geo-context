<?php
/**
 * Unit tests for the src/api.php public function surface.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionFunction;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Plugin;
use UniversalGeo\Settings;

/**
 * Covers Revision 3 §13's exact six-function public surface. src/api.php is
 * loaded once, automatically, via Composer autoload.files (confirmed by
 * these functions existing at all — see the function_exists() assertions
 * below); no test here requires it. All five convenience helpers must share
 * exactly one underlying resolution per request, mirroring Plugin::context()'s
 * own memoization.
 */
final class ApiTest extends TestCase {

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

	private function boot_with_known_country( string $country = 'SE', string $ip = '203.0.113.1' ): void {
		$GLOBALS['universal_geo_test_options'][ Settings::OPTION_NAME ] = array(
			'schema_version'  => Settings::SCHEMA_VERSION,
			'default_country' => $country,
		);
		$_SERVER['REMOTE_ADDR'] = $ip;

		Plugin::instance()->init();
	}

	// ---- Existence and signatures ----------------------------------------------

	/**
	 * @dataProvider function_name_provider
	 */
	public function test_function_exists( string $name ): void {
		$this->assertTrue( function_exists( $name ) );
	}

	public function function_name_provider(): array {
		return array(
			array( 'universal_geo_get_context' ),
			array( 'universal_geo_get_country_code' ),
			array( 'universal_geo_get_region_code' ),
			array( 'universal_geo_get_source' ),
			array( 'universal_geo_get_confidence' ),
			array( 'universal_geo_api_version' ),
		);
	}

	public function test_no_extra_universal_geo_functions_exist(): void {
		// Every function actually declared by src/api.php, cross-checked
		// against the exact Revision 3 §13 list of six.
		$declared = array_filter(
			get_defined_functions()['user'],
			static fn( string $name ) => str_starts_with( $name, 'universal_geo_' )
		);

		sort( $declared );

		$this->assertSame(
			array(
				'universal_geo_api_version',
				'universal_geo_get_confidence',
				'universal_geo_get_context',
				'universal_geo_get_country_code',
				'universal_geo_get_region_code',
				'universal_geo_get_source',
			),
			array_values( $declared )
		);
	}

	public function test_get_context_return_type_is_visitor_context(): void {
		$type = ( new ReflectionFunction( 'universal_geo_get_context' ) )->getReturnType();
		$this->assertSame( VisitorContext::class, (string) $type );
	}

	public function test_api_version_returns_one(): void {
		$this->assertSame( 1, universal_geo_api_version() );
	}

	public function test_api_version_does_not_require_boot(): void {
		// Deliberately not booted; api_version() is pure and must not
		// touch Plugin::context() at all.
		$this->assertSame( 1, universal_geo_api_version() );
		$this->assertSame( array(), $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	// ---- Return values, known context -----------------------------------------

	public function test_get_context_returns_a_visitor_context(): void {
		$this->boot_with_known_country();
		$this->assertInstanceOf( VisitorContext::class, universal_geo_get_context() );
	}

	public function test_get_country_code_known(): void {
		$this->boot_with_known_country( 'SE' );
		$this->assertSame( 'SE', universal_geo_get_country_code() );
	}

	public function test_get_region_code_is_always_null(): void {
		$this->boot_with_known_country( 'SE' );
		$this->assertNull( universal_geo_get_region_code() );
	}

	public function test_get_source_known(): void {
		$this->boot_with_known_country( 'SE' );
		$this->assertSame( 'default', universal_geo_get_source() );
	}

	public function test_get_confidence_known(): void {
		$this->boot_with_known_country( 'SE' );
		$this->assertSame( 0.10, universal_geo_get_confidence() );
	}

	// ---- Return values, unknown context -----------------------------------------

	public function test_get_country_code_unknown(): void {
		$this->boot_with_known_country( '' );
		$this->assertNull( universal_geo_get_country_code() );
	}

	public function test_get_source_unknown(): void {
		$this->boot_with_known_country( '' );
		$this->assertSame( 'unknown', universal_geo_get_source() );
	}

	public function test_get_confidence_unknown(): void {
		$this->boot_with_known_country( '' );
		$this->assertSame( 0.0, universal_geo_get_confidence() );
	}

	// ---- Shared memoization across all five context-dependent helpers ----------

	public function test_all_helpers_share_one_resolution(): void {
		$this->boot_with_known_country();

		universal_geo_get_context();
		universal_geo_get_country_code();
		universal_geo_get_region_code();
		universal_geo_get_source();
		universal_geo_get_confidence();

		// One derived-cache write total — proves every helper reused the
		// same underlying resolution rather than each building its own.
		$this->assertCount( 1, $GLOBALS['universal_geo_test_object_cache_calls'] );
	}

	public function test_all_helpers_reflect_the_same_filtered_context(): void {
		$this->boot_with_known_country( 'SE' );

		add_filter( 'universal_geo_context', static fn() => new VisitorContext( 'DE', null, 'default', 0.10 ) );

		$this->assertSame( 'DE', universal_geo_get_country_code() );
		$this->assertSame( 'DE', universal_geo_get_context()->country_code );
	}

	public function test_helpers_before_boot_return_unknown_without_error_propagating(): void {
		set_error_handler( static fn() => true, E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		$country_code = universal_geo_get_country_code();
		restore_error_handler();

		$this->assertNull( $country_code );
	}
}
