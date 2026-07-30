<?php
/**
 * Unit tests for UniversalGeo\MaxMind\ArchiveExtractor.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\MaxMind;

use PharData;
use PHPUnit\Framework\TestCase;
use UniversalGeo\MaxMind\ArchiveException;
use UniversalGeo\MaxMind\ArchiveExtractor;

/**
 * Builds real .tar.gz fixtures on the fly via PharData itself (never
 * committing binary fixtures for this) so every test exercises the actual
 * PharData code path, not a mock of it.
 */
final class ArchiveExtractorTest extends TestCase {

	/**
	 * @var string[]
	 */
	private array $scratch_files = array();

	protected function tearDown(): void {
		foreach ( $this->scratch_files as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file );
			}
		}

		$this->scratch_files = array();

		parent::tearDown();
	}

	/**
	 * Builds a .tar.gz containing the given entries and returns its path.
	 *
	 * Deliberately does not use PharData::compress(Phar::GZ): PHP's phar
	 * extension caches archive manifests per-filename in-process, and
	 * compress()-then-reopen-the-.gz-in-the-same-process is a known trigger
	 * for that cache serving stale/empty data — confirmed while writing this
	 * test (a real download's archive is always a genuinely fresh file
	 * ArchiveExtractor has never opened before, so production is unaffected;
	 * this is purely a same-process test-fixture-construction hazard).
	 * gzencode()-ing the raw tar bytes directly produces a byte-identical
	 * gzip-of-tar file without ever registering the .gz path with Phar
	 * during the build step, sidestepping the cache entirely.
	 *
	 * @param array<string, string> $entries Map of in-archive path => file contents.
	 */
	private function build_archive( array $entries ): string {
		$base = sys_get_temp_dir() . '/ugeo-archive-test-' . uniqid( '', true );
		$tar  = $base . '.tar';
		$gz   = $tar . '.gz';

		$phar = new PharData( $tar );

		foreach ( $entries as $path => $contents ) {
			$phar->addFromString( $path, $contents );
		}

		unset( $phar );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		$tar_bytes = file_get_contents( $tar );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $gz, gzencode( (string) $tar_bytes, 9 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $tar );

		$this->scratch_files[] = $gz;

		return $gz;
	}

	private function destination_path(): string {
		$path = sys_get_temp_dir() . '/ugeo-archive-dest-' . uniqid( '', true ) . '.mmdb';

		$this->scratch_files[] = $path;

		return $path;
	}

	public function test_extracts_the_matching_entry_regardless_of_wrapper_directory(): void {
		$archive     = $this->build_archive( array( 'GeoLite2-Country_20260101/GeoLite2-Country.mmdb' => 'fake-mmdb-bytes' ) );
		$destination = $this->destination_path();

		( new ArchiveExtractor() )->extract_country_database( $archive, $destination );

		$this->assertSame( 'fake-mmdb-bytes', file_get_contents( $destination ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
	}

	public function test_ignores_sibling_files_in_the_archive(): void {
		$archive     = $this->build_archive(
			array(
				'GeoLite2-Country_20260101/GeoLite2-Country.mmdb' => 'the-real-database',
				'GeoLite2-Country_20260101/COPYRIGHT.txt' => 'copyright text',
				'GeoLite2-Country_20260101/LICENSE.txt'   => 'license text',
			)
		);
		$destination = $this->destination_path();

		( new ArchiveExtractor() )->extract_country_database( $archive, $destination );

		$this->assertSame( 'the-real-database', file_get_contents( $destination ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
	}

	public function test_throws_when_the_database_is_absent(): void {
		$archive     = $this->build_archive( array( 'GeoLite2-Country_20260101/COPYRIGHT.txt' => 'copyright text' ) );
		$destination = $this->destination_path();

		$this->expectException( ArchiveException::class );
		$this->expectExceptionMessage( 'did not contain the expected database file' );

		( new ArchiveExtractor() )->extract_country_database( $archive, $destination );
	}

	public function test_throws_when_more_than_one_entry_matches(): void {
		$archive     = $this->build_archive(
			array(
				'dir-a/GeoLite2-Country.mmdb' => 'candidate-one',
				'dir-b/GeoLite2-Country.mmdb' => 'candidate-two',
			)
		);
		$destination = $this->destination_path();

		$this->expectException( ArchiveException::class );
		$this->expectExceptionMessage( 'more than one matching' );

		( new ArchiveExtractor() )->extract_country_database( $archive, $destination );
	}

	public function test_throws_on_a_nonexistent_archive_path(): void {
		$destination = $this->destination_path();

		$this->expectException( ArchiveException::class );

		( new ArchiveExtractor() )->extract_country_database( sys_get_temp_dir() . '/does-not-exist-' . uniqid( '', true ) . '.tar.gz', $destination );
	}

	public function test_throws_on_a_malformed_archive(): void {
		$path = sys_get_temp_dir() . '/ugeo-malformed-' . uniqid( '', true ) . '.tar.gz';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, 'this is not a valid gzip/tar archive' );
		$this->scratch_files[] = $path;

		$destination = $this->destination_path();

		$this->expectException( ArchiveException::class );
		$this->expectExceptionMessage( 'could not be read' );

		( new ArchiveExtractor() )->extract_country_database( $path, $destination );
	}

	public function test_throws_when_the_matched_entry_exceeds_the_byte_cap(): void {
		$archive     = $this->build_archive( array( 'GeoLite2-Country_20260101/GeoLite2-Country.mmdb' => str_repeat( 'x', 100 ) ) );
		$destination = $this->destination_path();

		$this->expectException( ArchiveException::class );
		$this->expectExceptionMessage( 'exceeded the maximum allowed size' );

		( new ArchiveExtractor( 10 ) )->extract_country_database( $archive, $destination );
	}

	public function test_does_not_leave_a_partial_file_when_the_byte_cap_is_exceeded(): void {
		$archive     = $this->build_archive( array( 'GeoLite2-Country_20260101/GeoLite2-Country.mmdb' => str_repeat( 'x', 100 ) ) );
		$destination = $this->destination_path();

		try {
			( new ArchiveExtractor( 10 ) )->extract_country_database( $archive, $destination );
			$this->fail( 'Expected an ArchiveException.' );
		} catch ( ArchiveException $e ) {
			$this->assertSame( 'The archive exceeded the maximum allowed size.', $e->getMessage() );
		}

		$this->assertFileDoesNotExist( $destination );
	}

	public function test_an_entry_exactly_at_the_cap_is_accepted(): void {
		$archive     = $this->build_archive( array( 'GeoLite2-Country_20260101/GeoLite2-Country.mmdb' => str_repeat( 'x', 10 ) ) );
		$destination = $this->destination_path();

		( new ArchiveExtractor( 10 ) )->extract_country_database( $archive, $destination );

		$this->assertSame( str_repeat( 'x', 10 ), file_get_contents( $destination ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
	}

	/**
	 * PharData::addFromString() itself refuses to create an entry whose path
	 * contains a ".." segment ("phar error: invalid path ... contains upper
	 * directory reference") — corroborating evidence, at the archive-writer
	 * layer, for the same traversal-safety property ArchiveExtractor's own
	 * design relies on: matching is by exact basename only, and
	 * stream_copy() only ever writes to the caller-chosen $destination,
	 * never to any path derived from an archive entry's name. A hostile,
	 * hand-crafted tar bypassing PharData's own writer could in principle
	 * still carry such an entry name, but ArchiveExtractor's basename-only
	 * matching (already exercised by the wrapper-directory and
	 * sibling-files tests above) never reads that name as anything other
	 * than a candidate to check the basename of.
	 */
	public function test_phar_data_itself_refuses_to_create_a_traversal_entry(): void {
		$this->expectException( \BadMethodCallException::class );

		$this->build_archive( array( '../../../etc/GeoLite2-Country.mmdb' => 'irrelevant' ) );
	}
}
