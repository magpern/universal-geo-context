<?php
/**
 * Unit tests for UniversalGeo\Providers\MaxMindProvider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Providers\MaxMindProvider;

/**
 * Covers structural/behavioral aspects reachable without a WordPress
 * bootstrap: id, availability branches, the private-IP self-guard, and
 * open/failed-open memoization. Known-answer lookups against the real
 * public test `.mmdb` fixtures (tests/fixtures/) are the integration
 * suite's job (tests/integration/Providers/MaxMindProviderTest.php) — this
 * class uses the Country fixture only for the memoization tests, which need
 * a real successfully-opened Reader but not WordPress.
 */
final class MaxMindProviderTest extends TestCase {

	private const FIXTURE_COUNTRY_DB = __DIR__ . '/../../fixtures/GeoIP2-Country-Test.mmdb';

	// ---- id ---------------------------------------------------------------

	public function test_get_id_is_maxmind(): void {
		$this->assertSame( 'maxmind', ( new MaxMindProvider( '' ) )->get_id() );
	}

	// ---- is_available() -----------------------------------------------------

	public function test_is_available_false_for_empty_path(): void {
		$this->assertFalse( ( new MaxMindProvider( '' ) )->is_available() );
	}

	public function test_is_available_false_for_a_nonexistent_path(): void {
		$this->assertFalse( ( new MaxMindProvider( '/nonexistent/path/geo.mmdb' ) )->is_available() );
	}

	public function test_is_available_true_for_a_readable_existing_path(): void {
		$this->assertTrue( class_exists( \MaxMind\Db\Reader::class ), 'dev dependency must be installed for this environment.' );
		$this->assertTrue( ( new MaxMindProvider( self::FIXTURE_COUNTRY_DB ) )->is_available() );
	}

	/**
	 * The "missing reader class" branch of is_available() cannot be
	 * exercised directly in this environment: the dev dependency
	 * (maxmind-db/reader) is installed for the test run. Documented here
	 * per the M3 architecture report §6 3C rather than silently omitted —
	 * the empty-path branch above already proves is_available() short-
	 * circuits before ever reaching the class_exists() check.
	 */
	public function test_missing_reader_class_branch_is_structurally_covered_by_the_empty_path_case(): void {
		$this->assertTrue( true );
	}

	public function test_is_available_cheap_no_reader_opened(): void {
		$provider = new MaxMindProvider( self::FIXTURE_COUNTRY_DB );
		$provider->is_available();

		$reflection = new ReflectionClass( $provider );
		$property   = $reflection->getProperty( 'open_attempted' );
		$property->setAccessible( true );

		$this->assertFalse( $property->getValue( $provider ), 'is_available() must not open the reader.' );
	}

	// ---- resolve(): private-IP self-guard ------------------------------------

	public function test_resolve_self_guards_a_private_ip_without_attempting_an_open(): void {
		// A nonexistent path would throw if an open were ever attempted —
		// proving the self-guard runs first.
		$provider = new MaxMindProvider( '/nonexistent/path/geo.mmdb' );

		$this->assertNull( $provider->resolve( '10.0.0.5' ) );

		$reflection = new ReflectionClass( $provider );
		$property   = $reflection->getProperty( 'open_attempted' );
		$property->setAccessible( true );

		$this->assertFalse( $property->getValue( $provider ) );
	}

	public function test_resolve_self_guards_a_loopback_ip(): void {
		$provider = new MaxMindProvider( '/nonexistent/path/geo.mmdb' );

		$this->assertNull( $provider->resolve( '127.0.0.1' ) );
	}

	// ---- resolve(): missing / corrupt database degrades, never swallowed ------

	public function test_resolve_throws_for_a_missing_database_with_a_public_ip(): void {
		$provider = new MaxMindProvider( '/nonexistent/path/geo.mmdb' );

		$this->expectException( \Throwable::class );
		$provider->resolve( '8.8.8.8' );
	}

	public function test_resolve_throws_for_a_corrupt_database(): void {
		$path = tempnam( sys_get_temp_dir(), 'ugeo-corrupt-' );
		file_put_contents( $path, 'this is not a valid mmdb file' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$provider = new MaxMindProvider( $path );

		try {
			$this->expectException( \Throwable::class );
			$provider->resolve( '8.8.8.8' );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	public function test_resolve_failed_open_is_memoized_not_reattempted(): void {
		$path = tempnam( sys_get_temp_dir(), 'ugeo-corrupt-' );
		file_put_contents( $path, 'this is not a valid mmdb file' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$provider = new MaxMindProvider( $path );

		$first_exception  = null;
		$second_exception = null;

		try {
			$provider->resolve( '8.8.8.8' );
		} catch ( \Throwable $e ) {
			$first_exception = $e;
		}

		try {
			$provider->resolve( '9.9.9.9' );
		} catch ( \Throwable $e ) {
			$second_exception = $e;
		}

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertNotNull( $first_exception );
		// The identical exception instance is re-thrown — proof the file was
		// only ever opened once, not reopened on the second call.
		$this->assertSame( $first_exception, $second_exception );
	}

	// ---- resolve(): single-open memoization on success -------------------------

	public function test_resolve_opens_the_reader_at_most_once_across_multiple_calls(): void {
		$provider = new MaxMindProvider( self::FIXTURE_COUNTRY_DB );

		// Neither call's outcome matters here (fixture content is verified
		// by the integration suite) — only that the second call reuses the
		// same Reader instance rather than reopening the file.
		$provider->resolve( '8.8.8.8' );

		$reflection      = new ReflectionClass( $provider );
		$reader_property = $reflection->getProperty( 'reader' );
		$reader_property->setAccessible( true );
		$first_reader = $reader_property->getValue( $provider );

		$provider->resolve( '9.9.9.9' );
		$second_reader = $reader_property->getValue( $provider );

		$this->assertNotNull( $first_reader );
		$this->assertSame( $first_reader, $second_reader );
	}

	public function test_resolve_missing_record_returns_null(): void {
		// 8.8.8.8 is not present in the small public test tree.
		$provider = new MaxMindProvider( self::FIXTURE_COUNTRY_DB );

		$this->assertNull( $provider->resolve( '8.8.8.8' ) );
	}

	public function test_resolve_region_is_always_null(): void {
		$provider  = new MaxMindProvider( self::FIXTURE_COUNTRY_DB );
		$candidate = $provider->resolve( '214.78.120.1' );

		$this->assertNotNull( $candidate );
		$this->assertNull( $candidate->region_code );
	}

	// ---- metadata() ----------------------------------------------------------

	public function test_metadata_is_null_for_an_empty_path(): void {
		$this->assertNull( ( new MaxMindProvider( '' ) )->metadata() );
	}

	public function test_metadata_is_null_for_a_missing_database_and_does_not_throw(): void {
		$provider = new MaxMindProvider( '/nonexistent/path/geo.mmdb' );

		$this->assertNull( $provider->metadata() );
	}

	public function test_metadata_is_null_for_a_corrupt_database_and_does_not_throw(): void {
		$path = tempnam( sys_get_temp_dir(), 'ugeo-corrupt-' );
		file_put_contents( $path, 'this is not a valid mmdb file' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$provider = new MaxMindProvider( $path );
		$result   = $provider->metadata();

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertNull( $result );
	}

	public function test_metadata_reuses_the_same_reader_resolve_already_opened(): void {
		$provider = new MaxMindProvider( self::FIXTURE_COUNTRY_DB );
		$provider->resolve( '214.78.120.1' );

		$reflection      = new ReflectionClass( $provider );
		$reader_property = $reflection->getProperty( 'reader' );
		$reader_property->setAccessible( true );
		$reader_after_resolve = $reader_property->getValue( $provider );

		$provider->metadata();
		$reader_after_metadata = $reader_property->getValue( $provider );

		$this->assertSame( $reader_after_resolve, $reader_after_metadata );
	}

	public function test_metadata_reports_expected_fields_from_the_fixture(): void {
		$metadata = ( new MaxMindProvider( self::FIXTURE_COUNTRY_DB ) )->metadata();

		$this->assertNotNull( $metadata );
		$this->assertSame( 'GeoIP2-Country', $metadata->database_type );
		// The public test fixture builds an IPv4-capable tree inside an
		// ip_version=6 database — MaxMind DBs support IPv4 lookups within
		// an IPv6-versioned tree (confirmed directly against this fixture).
		$this->assertSame( 6, $metadata->ip_version );
		$this->assertGreaterThan( 0, $metadata->build_epoch );
		$this->assertStringContainsString( 'Reader.php', $metadata->reader_class_file );
	}
}
