<?php
/**
 * Integration tests for UniversalGeo\Cli\DatabaseCommand.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration\Cli;

use PharData;
use UniversalGeo\Cli\DatabaseCommand;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\Providers\Remote\DownloadResult;
use UniversalGeo\Providers\Remote\RedirectResult;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use WP_UnitTestCase;

/**
 * Covers the WP-CLI-facing wrapper methods against a real WordPress
 * environment — the piece the unit suite cannot reach. Deliberately
 * smoke-level and success-path-only: WP_CLI::error()/confirm()/halt() exit
 * the process outside WP-CLI's own capture_exit test mode (the identical
 * limitation Cli\CommandTest's own docblock documents), so no test here
 * ever triggers a failure outcome from download()/validate()/remove()/
 * restore() — that coverage lives in DatabaseManagerTest (the actual
 * classification logic) and the unit suite's own
 * DatabaseCommandTest::result_payload() tests. remove()/restore() are
 * always called with --yes, since WP_CLI::confirm() without it would
 * otherwise prompt interactively.
 */
final class DatabaseCommandTest extends WP_UnitTestCase {

	private const COUNTRY_DB = __DIR__ . '/../../fixtures/GeoIP2-Country-Test.mmdb';

	private function managed_dir(): string {
		return sys_get_temp_dir() . '/ugeo-cli-database-command-integration-' . uniqid( '', true );
	}

	private function command( DatabaseManager $database_manager ): DatabaseCommand {
		return new DatabaseCommand( $database_manager );
	}

	private function unused_database_manager(): DatabaseManager {
		return new DatabaseManager(
			$this->managed_dir(),
			'',
			'',
			true,
			new FakeHttpTransport(),
			new ArchiveExtractor(),
			new UpdateLock()
		);
	}

	/**
	 * Same gzencode()-based construction ArchiveExtractorTest/
	 * DatabaseManagerTest use, to avoid a same-process PharData
	 * manifest-caching quirk confirmed while writing those tests.
	 */
	private function build_valid_archive(): string {
		$base = sys_get_temp_dir() . '/ugeo-cli-database-archive-' . uniqid( '', true );
		$tar  = $base . '.tar';
		$gz   = $tar . '.gz';

		$phar = new PharData( $tar );
		$phar->addFile( self::COUNTRY_DB, 'GeoLite2-Country_20260101/GeoLite2-Country.mmdb' );
		unset( $phar );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		$tar_bytes = file_get_contents( $tar );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $gz, gzencode( (string) $tar_bytes, 9 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $tar );

		return $gz;
	}

	/**
	 * A DatabaseManager whose transport is pre-queued for one successful
	 * download.
	 */
	private function database_manager_with_a_queued_successful_download(): DatabaseManager {
		$transport = new FakeHttpTransport();
		$archive   = $this->build_valid_archive();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		$archive_bytes = (string) file_get_contents( $archive );
		$transport->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x', 302 ) );
		$transport->will_return_download( new DownloadResult( 200, strlen( $archive_bytes ) ), $archive_bytes );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive );

		return new DatabaseManager( $this->managed_dir(), 'account', 'license', true, $transport, new ArchiveExtractor(), new UpdateLock() );
	}

	public function test_register_is_a_safe_no_op_without_the_wp_cli_constant(): void {
		$this->command( $this->unused_database_manager() )->register();

		// WP_CLI::add_command() bails out immediately when the WP_CLI
		// constant is undefined (its own guard) — proving this call
		// completes without throwing.
		$this->addToAssertionCount( 1 );
	}

	public function test_status_completes_with_nothing_installed(): void {
		$this->command( $this->unused_database_manager() )->status( array(), array( 'format' => 'json' ) );

		$this->addToAssertionCount( 1 );
	}

	public function test_download_succeeds_and_installs(): void {
		$database_manager = $this->database_manager_with_a_queued_successful_download();

		$this->command( $database_manager )->download( array(), array( 'format' => 'json' ) );

		$this->assertNotSame( '', $database_manager->installed_path() );
	}

	public function test_status_after_a_successful_download_reports_installed(): void {
		$database_manager = $this->database_manager_with_a_queued_successful_download();
		$database_manager->download_now( 'admin' );

		$payload = $this->command( $database_manager )->status_payload();

		$this->assertSame( 'yes', $payload['installed'] );
	}

	public function test_validate_succeeds_after_a_successful_download(): void {
		$database_manager = $this->database_manager_with_a_queued_successful_download();
		$database_manager->download_now( 'admin' );

		$this->command( $database_manager )->validate( array(), array( 'format' => 'json' ) );

		$this->addToAssertionCount( 1 );
	}

	public function test_remove_with_yes_succeeds_after_a_successful_download(): void {
		$database_manager = $this->database_manager_with_a_queued_successful_download();
		$database_manager->download_now( 'admin' );

		$this->command( $database_manager )->remove( array(), array( 'yes' => true ) );

		$this->assertSame( '', $database_manager->installed_path() );
	}

	public function test_restore_with_yes_succeeds_when_a_previous_version_exists(): void {
		$managed_dir = $this->managed_dir();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $managed_dir, 0755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		copy( self::COUNTRY_DB, $managed_dir . '/GeoLite2-Country.mmdb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		copy( self::COUNTRY_DB, $managed_dir . '/GeoLite2-Country.mmdb.previous' );

		$database_manager = new DatabaseManager( $managed_dir, '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() );

		$this->command( $database_manager )->restore( array(), array( 'yes' => true ) );

		$this->assertFileDoesNotExist( $managed_dir . '/GeoLite2-Country.mmdb.previous' );
	}
}
