<?php
/**
 * Integration tests for UniversalGeo\Providers\MaxMindProvider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration\Providers;

use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Model\ResolvedClientIp;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Tests\Integration\Support\FixedResultClientIpResolver;
use WP_UnitTestCase;

/**
 * Covers MaxMindProvider against the real public MaxMind test databases
 * (tests/fixtures/, licensing decision recorded in tests/fixtures/README.md)
 * under a genuine WordPress-loaded environment, plus its behavior wired
 * through ContextResolver::probe() — the M3 architecture report §6 3C
 * integration coverage list.
 */
final class MaxMindProviderTest extends WP_UnitTestCase {

	private const COUNTRY_DB = __DIR__ . '/../../fixtures/GeoIP2-Country-Test.mmdb';
	private const CITY_DB    = __DIR__ . '/../../fixtures/GeoIP2-City-Test.mmdb';

	private function resolved_ip( string $ip ): ResolvedClientIp {
		return new ResolvedClientIp( $ip, 'REMOTE_ADDR', true, true );
	}

	private function resolver_for( MaxMindProvider $provider, string $ip ): ContextResolver {
		return new ContextResolver(
			new FixedResultClientIpResolver( $this->resolved_ip( $ip ) ),
			array( $provider ),
			new GeoCache( false, 900, 'sig' )
		);
	}

	// ---- Known-answer lookups ---------------------------------------------------

	public function test_known_country_lookup_against_the_country_database(): void {
		$provider  = new MaxMindProvider( self::COUNTRY_DB );
		$candidate = $provider->resolve( '214.78.120.1' );

		$this->assertNotNull( $candidate );
		$this->assertSame( 'US', $candidate->country_code );
		$this->assertNull( $candidate->region_code );
	}

	public function test_missing_record_returns_null(): void {
		$provider = new MaxMindProvider( self::COUNTRY_DB );

		// Not present in the small public test tree.
		$this->assertNull( $provider->resolve( '8.8.8.8' ) );
	}

	public function test_city_database_returns_country_only(): void {
		$provider  = new MaxMindProvider( self::CITY_DB );
		$candidate = $provider->resolve( '214.78.120.1' );

		$this->assertNotNull( $candidate );
		$this->assertSame( 'US', $candidate->country_code );
		// The City fixture carries a California subdivision for this
		// address (confirmed against source-data/GeoIP2-City-Test.json) —
		// region must stay null regardless.
		$this->assertNull( $candidate->region_code );
	}

	// ---- Metadata -----------------------------------------------------------------

	public function test_metadata_reports_expected_fields(): void {
		$provider = new MaxMindProvider( self::COUNTRY_DB );
		$provider->resolve( '214.78.120.1' );
		$metadata = $provider->metadata();

		$this->assertNotNull( $metadata );
		$this->assertSame( 'GeoIP2-Country', $metadata->database_type );
		$this->assertGreaterThan( 0, $metadata->build_epoch );
		$this->assertIsInt( $metadata->build_age_days( time() ) );
	}

	public function test_city_metadata_reports_city_database_type(): void {
		$provider = new MaxMindProvider( self::CITY_DB );
		$metadata = $provider->metadata();

		$this->assertNotNull( $metadata );
		$this->assertSame( 'GeoIP2-City', $metadata->database_type );
	}

	// ---- Missing / corrupt database degrades safely --------------------------------

	public function test_missing_file_is_unavailable(): void {
		$provider = new MaxMindProvider( '/nonexistent/path/geo.mmdb' );

		$this->assertFalse( $provider->is_available() );
	}

	public function test_corrupt_database_is_caught_by_the_resolver_and_the_chain_continues(): void {
		$corrupt_path = wp_tempnam( 'ugeo-corrupt-' );
		file_put_contents( $corrupt_path, 'not a valid mmdb file' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$provider = new MaxMindProvider( $corrupt_path );
		$resolver = new ContextResolver(
			new FixedResultClientIpResolver( $this->resolved_ip( '8.8.8.8' ) ),
			array( $provider ),
			new GeoCache( false, 900, 'sig' )
		);

		$context = $resolver->resolve();

		unlink( $corrupt_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		// The corrupt database threw; ContextResolver caught it and the
		// chain fell through to no result (no other provider configured) —
		// never a fatal.
		$this->assertFalse( $context->is_known() );
	}

	public function test_provider_failed_action_fires_for_a_corrupt_database(): void {
		$corrupt_path = wp_tempnam( 'ugeo-corrupt-' );
		file_put_contents( $corrupt_path, 'not a valid mmdb file' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$provider = new MaxMindProvider( $corrupt_path );
		$received = null;

		$resolver = new ContextResolver(
			new FixedResultClientIpResolver( $this->resolved_ip( '8.8.8.8' ) ),
			array( $provider ),
			new GeoCache( false, 900, 'sig' ),
			static function ( string $provider_id, string $reason ) use ( &$received ): void {
				$received = array( $provider_id, $reason );
			}
		);

		$resolver->resolve();
		unlink( $corrupt_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( 'maxmind', $received[0] );
	}

	// ---- probe() ---------------------------------------------------------------------

	public function test_probe_reports_ok_for_a_known_answer(): void {
		$provider = new MaxMindProvider( self::COUNTRY_DB );
		$resolver = $this->resolver_for( $provider, '214.78.120.1' );

		$rows = $resolver->probe();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'maxmind', $rows[0]['provider'] );
		$this->assertSame( 'ok', $rows[0]['reason'] );
		$this->assertSame( 'US', $rows[0]['country_code'] );
	}

	public function test_probe_reports_miss_for_an_unresolvable_ip(): void {
		$provider = new MaxMindProvider( self::COUNTRY_DB );
		$resolver = $this->resolver_for( $provider, '8.8.8.8' );

		$rows = $resolver->probe();

		$this->assertSame( 'miss', $rows[0]['reason'] );
	}

	public function test_probe_reports_unavailable_for_a_missing_database(): void {
		$provider = new MaxMindProvider( '/nonexistent/path/geo.mmdb' );
		$resolver = $this->resolver_for( $provider, '8.8.8.8' );

		$rows = $resolver->probe();

		$this->assertSame( 'unavailable', $rows[0]['reason'] );
	}

	public function test_probe_reports_failed_for_a_corrupt_database(): void {
		$corrupt_path = wp_tempnam( 'ugeo-corrupt-' );
		file_put_contents( $corrupt_path, 'not a valid mmdb file' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$provider = new MaxMindProvider( $corrupt_path );
		$resolver = $this->resolver_for( $provider, '8.8.8.8' );

		$rows = $resolver->probe();

		unlink( $corrupt_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertSame( 'failed', $rows[0]['reason'] );
	}
}
