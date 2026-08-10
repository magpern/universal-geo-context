<?php
/**
 * Integration tests for UniversalGeo\Cli\Command.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration\Cli;

use ReflectionClass;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Cli\Command;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\Plugin;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationCookie;
use UniversalGeo\Simulation\SimulationState;
use UniversalGeo\Diagnostics\OperationalStatusService;
use WP_UnitTestCase;

/**
 * Covers the WP-CLI-facing wrapper methods against a real WordPress
 * environment — the piece the unit suite (tests/unit/Cli/CommandTest.php)
 * cannot reach, since it exercises resolve_format()/build_context_payload()/
 * flatten_report() directly rather than the WP_CLI-touching entry points.
 *
 * Deliberately smoke-level for the output-producing paths: WP_CLI::log()/
 * success() safely no-op with no logger configured (WP_CLI::error() would
 * exit() the process outside WP-CLI's own capture_exit test mode, so no
 * test here exercises an invalid-argument path — that is the unit suite's
 * job, and manual/live CLI acceptance's). \cli\Table (php-cli-tools) writes
 * table-format output via fwrite(STDOUT, ...), which bypasses PHP's
 * ob_start() output buffering, so this suite does not assert on rendered
 * STDOUT content — only that the real, fully-wired command completes
 * without a fatal error against a live provider chain and diagnostics
 * report.
 */
final class CommandTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		update_option( \UniversalGeo\Settings::OPTION_NAME, \UniversalGeo\Settings::defaults() );

		// context() without --ip calls Plugin::instance()->context() — the
		// same public boundary universal_geo_get_context() itself uses —
		// which _doing_it_wrong()s if Plugin was never booted (the same
		// setUp() pattern DiagnosticsServiceTest's own integration-adjacent
		// unit tests already establish for exactly this reason).
		$reflection = new ReflectionClass( Plugin::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		Plugin::instance()->init();
	}

	private function command(): Command {
		$request         = ServerRequest::capture( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$trusted_proxies = new TrustedProxies( array(), false );
		$ip_resolver     = new ClientIpResolver( $request, $trusted_proxies );
		$resolver        = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );
		$diagnostics     = new DiagnosticsService(
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
				sys_get_temp_dir() . '/ugeo-cli-command-integration-test-unused',
				'',
				'',
				true,
				new FakeHttpTransport(),
				new ArchiveExtractor(),
				new UpdateLock()
			),
			'none',
			new GeoCache( false, 900, 'sig' ),
			new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-m12-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ),
			new SimulationState( new SimulationCookie(), new SimulationAuthorization() )
		);

		$operational = new OperationalStatusService(
			$resolver,
			$request,
			$trusted_proxies,
			array(),
			new ProviderHealthStore(),
			new MaxMindProvider( '' ),
			new CircuitBreaker(),
			'none',
			new DatabaseManager( sys_get_temp_dir() . '/ugeo-cli-ops', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ),
			new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-cli-ops2', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ),
			new SimulationState( new SimulationCookie(), new SimulationAuthorization() ),
			'none'
		);

		return new Command( $resolver, $diagnostics, $operational, $trusted_proxies );
	}

	/**
	 * The WP_CLI-constant-defined registration path is deliberately not
	 * exercised here: defining that constant cannot be undone within a
	 * process, and PHPUnit's process-isolation feature is unsafe to combine
	 * with WP_UnitTestCase (a fresh process does not share the parent's
	 * DB-rollback transaction, risking leftover test data). That path is
	 * covered by the unit suite's resolve_format()/build_context_payload()/
	 * flatten_report() tests (the actual logic add_command() wires up) plus
	 * manual/live CLI acceptance for add_command() itself. (Note: naming
	 * PHPUnit's own isolation annotation literally in this docblock — even
	 * as prose — would make PHPUnit apply it for real; deliberately not
	 * spelled out here for that reason.)
	 */
	public function test_register_is_a_safe_no_op_without_the_wp_cli_constant(): void {
		$this->command()->register();

		// WP_CLI::add_command() bails out immediately when the WP_CLI
		// constant is undefined (its own guard) — proving this call
		// completes without throwing.
		$this->addToAssertionCount( 1 );
	}

	public function test_context_without_ip_completes_against_a_real_request(): void {
		$this->command()->context( array(), array( 'format' => 'json' ) );

		$this->addToAssertionCount( 1 );
	}

	public function test_context_with_a_valid_ip_completes(): void {
		$this->command()->context(
			array(),
			array(
				'ip'     => '203.0.113.55',
				'format' => 'json',
			)
		);

		$this->addToAssertionCount( 1 );
	}

	public function test_diagnostics_completes_against_a_real_report(): void {
		$this->command()->diagnostics( array(), array( 'format' => 'json' ) );

		$this->addToAssertionCount( 1 );
	}

	public function test_cache_flush_bumps_the_real_cache_epoch(): void {
		$before = get_option( 'universal_geo_cache_epoch', 1 );

		$this->command()->cache_flush( array(), array() );

		$after = get_option( 'universal_geo_cache_epoch', 1 );

		$this->assertGreaterThan( $before, $after );
	}

	public function test_cache_flush_is_idempotent(): void {
		$this->command()->cache_flush( array(), array() );
		$first = get_option( 'universal_geo_cache_epoch', 1 );

		$this->command()->cache_flush( array(), array() );
		$second = get_option( 'universal_geo_cache_epoch', 1 );

		$this->assertGreaterThan( $first, $second );
	}
}
