<?php
/**
 * Unit tests for UniversalGeo\Cli\Command.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Cli;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Cli\Command;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\Model\GeoCandidate;
use UniversalGeo\Plugin;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use UniversalGeo\Tests\Support\ServerRequestFactory;
use UniversalGeo\Tests\Unit\Doubles\FakeGeoProvider;

/**
 * Covers resolve_format(), build_context_payload(), and flatten_report() —
 * the pure-ish, independently testable logic behind the three WP-CLI
 * commands (M5). The WP-CLI-facing wrapper methods (context(), diagnostics(),
 * cache_flush(), register()) are not unit-testable beyond these methods,
 * since WP_CLI::error() exits the process outside WP-CLI's own
 * capture_exit test mode — the same "not unit-testable beyond X" pattern
 * AdminScreen::redirect_with_notice() already established; verified via
 * manual/live CLI acceptance instead.
 */
final class CommandTest extends TestCase {

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
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * @param array<int, \UniversalGeo\Contracts\GeoProviderInterface> $providers Providers in resolution order.
	 */
	private function command( array $providers = array() ): Command {
		$request         = ServerRequestFactory::make( '203.0.113.1' );
		$trusted_proxies = new TrustedProxies( array(), false );
		$ip_resolver     = new ClientIpResolver( $request, $trusted_proxies );
		$resolver        = new ContextResolver( $ip_resolver, $providers, new GeoCache( false, 900, 'sig' ) );

		$diagnostics = new DiagnosticsService(
			$resolver,
			$ip_resolver,
			$request,
			$trusted_proxies,
			array(),
			new ProviderHealthStore(),
			new MaxMindProvider( '' ),
			new CircuitBreaker(),
			'none',
			new DatabaseManager(
				sys_get_temp_dir() . '/ugeo-cli-command-test-unused',
				'',
				'',
				true,
				new FakeHttpTransport(),
				new ArchiveExtractor(),
				new UpdateLock()
			),
			'none'
		);

		return new Command( $resolver, $diagnostics );
	}

	// ---- resolve_format() -------------------------------------------------------------

	public function test_resolve_format_defaults_to_table(): void {
		$this->assertSame( 'table', $this->command()->resolve_format( array() ) );
	}

	/**
	 * @dataProvider valid_format_provider
	 */
	public function test_resolve_format_accepts_known_formats( string $format ): void {
		$this->assertSame( $format, $this->command()->resolve_format( array( 'format' => $format ) ) );
	}

	public function valid_format_provider(): array {
		return array(
			'table' => array( 'table' ),
			'json'  => array( 'json' ),
			'yaml'  => array( 'yaml' ),
		);
	}

	public function test_resolve_format_rejects_unknown_format(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->command()->resolve_format( array( 'format' => 'xml' ) );
	}

	// ---- build_context_payload() — --ip mode -------------------------------------------

	public function test_build_context_payload_with_ip_rejects_invalid_ip(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->command()->build_context_payload( array( 'ip' => 'not-an-ip' ) );
	}

	public function test_build_context_payload_with_ip_masks_by_default(): void {
		$payload = $this->command()->build_context_payload( array( 'ip' => '203.0.113.55' ) );

		$this->assertSame( '203.0.113.x', $payload['ip'] );
	}

	public function test_build_context_payload_with_ip_shows_full_ip_when_allowed(): void {
		$payload = $this->command()->build_context_payload(
			array(
				'ip'            => '203.0.113.55',
				'allow-full-ip' => true,
			)
		);

		$this->assertSame( '203.0.113.55', $payload['ip'] );
	}

	public function test_build_context_payload_with_ip_and_no_matching_provider_is_unknown(): void {
		$payload = $this->command( array( new FakeGeoProvider( 'stub', true, null ) ) )
			->build_context_payload( array( 'ip' => '203.0.113.55' ) );

		$this->assertNull( $payload['country_code'] );
		$this->assertSame( 'unknown', $payload['source'] );
	}

	public function test_build_context_payload_with_ip_reports_the_winning_provider(): void {
		$provider = new FakeGeoProvider( 'stub-provider', true, new GeoCandidate( 'SE', null ) );

		$payload = $this->command( array( $provider ) )
			->build_context_payload( array( 'ip' => '203.0.113.55' ) );

		$this->assertSame( 'SE', $payload['country_code'] );
		$this->assertSame( 'stub-provider', $payload['source'] );
	}

	// ---- build_context_payload() — current-request mode --------------------------------

	public function test_build_context_payload_without_ip_uses_the_current_request_context(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		Plugin::instance()->init();

		$payload = $this->command()->build_context_payload( array() );

		$this->assertArrayHasKey( 'country_code', $payload );
		$this->assertArrayHasKey( 'confidence', $payload );
		$this->assertArrayHasKey( 'is_cached', $payload );
		$this->assertArrayHasKey( 'source', $payload );
	}

	public function test_build_context_payload_ignores_an_empty_ip_argument(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		Plugin::instance()->init();

		$payload = $this->command()->build_context_payload( array( 'ip' => '' ) );

		// The current-request shape (confidence/is_cached), not the --ip probe shape.
		$this->assertArrayHasKey( 'confidence', $payload );
	}

	// ---- flatten_report() -------------------------------------------------------------

	public function test_flatten_report_produces_one_row_per_leaf_field(): void {
		$rows = $this->command()->flatten_report(
			array(
				'client_address' => array(
					'peer_masked' => '203.0.113.x',
					'is_public'   => true,
				),
			)
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( 'client_address', $rows[0]['section'] );
	}

	public function test_flatten_report_uses_field_labels_for_known_keys(): void {
		$rows = $this->command()->flatten_report(
			array(
				'client_address' => array( 'peer_masked' => '203.0.113.x' ),
			)
		);

		$this->assertSame( 'Peer address (masked)', $rows[0]['field'] );
	}

	public function test_flatten_report_falls_back_to_the_raw_key_for_unknown_fields(): void {
		$rows = $this->command()->flatten_report(
			array(
				'client_address' => array( 'totally_unmapped_field' => 'value' ),
			)
		);

		$this->assertSame( 'totally_unmapped_field', $rows[0]['field'] );
	}

	public function test_flatten_report_flattens_a_list_of_rows(): void {
		$rows = $this->command()->flatten_report(
			array(
				'providers' => array(
					array(
						'provider'     => 'cloudflare',
						'country_code' => 'SE',
					),
					array(
						'provider'     => 'default',
						'country_code' => null,
					),
				),
			)
		);

		$this->assertCount( 4, $rows );
	}

	public function test_flatten_report_converts_booleans_to_yes_no(): void {
		$rows = $this->command()->flatten_report(
			array( 'section' => array( 'flag' => true ) )
		);

		$this->assertSame( 'yes', $rows[0]['value'] );
	}

	public function test_flatten_report_converts_null_to_empty_string(): void {
		$rows = $this->command()->flatten_report(
			array( 'section' => array( 'field' => null ) )
		);

		$this->assertSame( '', $rows[0]['value'] );
	}

	// ---- construction -------------------------------------------------------------

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( Command::class ) )->isFinal() );
	}
}
