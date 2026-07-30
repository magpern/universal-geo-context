<?php
/**
 * Unit tests for UniversalGeo\MaxMind\DatabaseManager.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\MaxMind;

use PharData;
use PHPUnit\Framework\TestCase;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\Providers\Remote\DownloadResult;
use UniversalGeo\Providers\Remote\RedirectResult;
use UniversalGeo\Providers\Remote\TransportException;
use UniversalGeo\Providers\Remote\TransportResponse;
use UniversalGeo\Tests\Support\FakeHttpTransport;

/**
 * Runs the full download -> extract -> validate -> install -> rollback ->
 * cleanup sequence against a real tmp-dir filesystem and FakeHttpTransport
 * (never a real network call), including the credential-isolation proof
 * test: Authorization is present on the redirect-check call and absent on
 * the download call.
 */
final class DatabaseManagerTest extends TestCase {

	private const FIXTURE_COUNTRY_DB = __DIR__ . '/../../fixtures/GeoIP2-Country-Test.mmdb';

	/**
	 * Fixed "now" safely after the fixture's own real buildEpoch
	 * (1770245369), so build-epoch-plausibility checks are deterministic
	 * regardless of wall-clock time.
	 */
	private const FIXED_NOW = 2000000000;

	/**
	 * @var string
	 */
	private string $managed_dir;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['universal_geo_test_options'] = array();

		$this->managed_dir = sys_get_temp_dir() . '/ugeo-dbmgr-test-' . uniqid( '', true );
	}

	protected function tearDown(): void {
		$this->remove_directory( $this->managed_dir );
		parent::tearDown();
	}

	private function remove_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$entries = scandir( $dir );
		$entries = false === $entries ? array() : $entries;

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}

	/**
	 * Builds a valid GeoLite2-Country archive containing the real committed
	 * .mmdb test fixture. Uses the same gzencode()-based construction
	 * ArchiveExtractorTest uses, for the same reason: avoids a same-process
	 * PharData compress()-then-reread cache-staleness quirk.
	 */
	private function build_valid_archive(): string {
		$base = sys_get_temp_dir() . '/ugeo-dbmgr-archive-' . uniqid( '', true );
		$tar  = $base . '.tar';
		$gz   = $tar . '.gz';

		$phar = new PharData( $tar );
		$phar->addFile( self::FIXTURE_COUNTRY_DB, 'GeoLite2-Country_20260101/GeoLite2-Country.mmdb' );
		unset( $phar );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		$tar_bytes = file_get_contents( $tar );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $gz, gzencode( (string) $tar_bytes, 9 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $tar );

		return $gz;
	}

	private function manager(
		FakeHttpTransport $transport,
		string $account_id = 'test-account',
		string $license_key = 'test-license',
		bool $retain_previous = true,
		?UpdateLock $lock = null
	): DatabaseManager {
		return new DatabaseManager(
			$this->managed_dir,
			$account_id,
			$license_key,
			$retain_previous,
			$transport,
			new ArchiveExtractor(),
			$lock ?? new UpdateLock( static fn (): int => self::FIXED_NOW ),
			static fn (): int => self::FIXED_NOW
		);
	}

	/**
	 * Queues a fully successful redirect-check + download pair against the
	 * given transport, using $archive's bytes for the download response.
	 */
	private function queue_successful_download( FakeHttpTransport $transport, string $archive_path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		$archive_bytes = (string) file_get_contents( $archive_path );

		$transport->will_return_redirect(
			new RedirectResult( true, 'https://abc123.r2.cloudflarestorage.com/signed?sig=xyz', 302 )
		);
		$transport->will_return_download(
			new DownloadResult( 200, strlen( $archive_bytes ) ),
			$archive_bytes
		);
	}

	// ---- Credential / lock short-circuits --------------------------------------

	public function test_download_fails_with_credentials_missing_when_account_id_is_blank(): void {
		$transport = new FakeHttpTransport();
		$manager   = $this->manager( $transport, '', 'license' );

		$result = $manager->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'credentials_missing', $result->code );
		$this->assertSame( 0, $transport->call_count() );
		$this->assertSame( array(), $transport->redirect_calls );
	}

	public function test_download_fails_with_credentials_missing_when_license_key_is_blank(): void {
		$transport = new FakeHttpTransport();
		$manager   = $this->manager( $transport, 'account', '' );

		$result = $manager->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'credentials_missing', $result->code );
	}

	public function test_download_fails_with_already_running_when_the_lock_is_held(): void {
		$lock = new UpdateLock( static fn (): int => self::FIXED_NOW );
		$lock->acquire( 'someone-else' );

		$transport = new FakeHttpTransport();
		$manager   = $this->manager( $transport, 'account', 'license', true, $lock );

		$result = $manager->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'already_running', $result->code );
		$this->assertSame( 0, $transport->call_count() );
		$this->assertSame( array(), $transport->redirect_calls );
	}

	public function test_download_releases_the_lock_after_completion(): void {
		$lock      = new UpdateLock( static fn (): int => self::FIXED_NOW );
		$transport = new FakeHttpTransport();
		$archive   = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive );

		$manager = $this->manager( $transport, 'account', 'license', true, $lock );
		$manager->download_now( 'admin' );

		$this->assertFalse( $lock->state()['locked'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive );
	}

	// ---- Successful full cycle --------------------------------------------------

	public function test_successful_download_installs_the_database(): void {
		$transport = new FakeHttpTransport();
		$archive   = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive );

		$manager = $this->manager( $transport );
		$result  = $manager->download_now( 'admin' );

		$this->assertTrue( $result->success );
		$this->assertSame( 'ok', $result->code );
		$this->assertSame( $this->managed_dir . '/GeoLite2-Country.mmdb', $manager->installed_path() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive );
	}

	public function test_successful_download_bumps_the_cache_epoch(): void {
		update_option( 'universal_geo_cache_epoch', 5 );

		$transport = new FakeHttpTransport();
		$archive   = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive );

		$this->manager( $transport )->download_now( 'admin' );

		$this->assertSame( 6, get_option( 'universal_geo_cache_epoch', 1 ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive );
	}

	public function test_successful_download_persists_success_state(): void {
		$transport = new FakeHttpTransport();
		$archive   = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive );

		$manager = $this->manager( $transport );
		$manager->download_now( 'admin' );

		$status = $manager->status();
		$this->assertSame( self::FIXED_NOW, $status['last_attempt_at'] );
		$this->assertSame( self::FIXED_NOW, $status['last_success_at'] );
		$this->assertSame( 'ok', $status['last_result_code'] );
		$this->assertSame( 1770245369, $status['installed_build_epoch'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive );
	}

	public function test_a_second_download_with_the_same_build_epoch_is_reported_as_already_current(): void {
		$transport = new FakeHttpTransport();
		$archive1  = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive1 );

		$manager = $this->manager( $transport );
		$manager->download_now( 'admin' );

		$archive2 = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive2 );

		$result = $manager->download_now( 'admin' );

		$this->assertTrue( $result->success );
		$this->assertSame( 'already_current', $result->code );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive1 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive2 );
	}

	public function test_already_current_does_not_bump_the_cache_epoch_a_second_time(): void {
		$transport = new FakeHttpTransport();
		$archive1  = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive1 );

		$manager = $this->manager( $transport );
		$manager->download_now( 'admin' );

		$epoch_after_first = get_option( 'universal_geo_cache_epoch', 1 );

		$archive2 = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive2 );
		$manager->download_now( 'admin' );

		$this->assertSame( $epoch_after_first, get_option( 'universal_geo_cache_epoch', 1 ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive1 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive2 );
	}

	public function test_installed_path_is_empty_before_any_successful_download(): void {
		$manager = $this->manager( new FakeHttpTransport() );

		$this->assertSame( '', $manager->installed_path() );
	}

	// ---- HTTP-status classification (first hop, non-redirect) ------------------

	public function test_a_non_redirect_401_response_is_classified_as_an_http_error(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( false, null, 401 ) );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'http_error', $result->code );
		$this->assertStringContainsString( '401', $result->message );
		// Only the redirect-check call was made — no download attempt on a
		// non-redirect terminal status.
		$this->assertSame( 0, $transport->call_count() );
		$this->assertSame( array(), $transport->download_calls );
	}

	public function test_a_non_redirect_429_response_is_classified_as_an_http_error(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( false, null, 429 ) );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'http_error', $result->code );
		$this->assertStringContainsString( '429', $result->message );
	}

	public function test_a_transport_exception_on_the_redirect_check_is_classified_as_download_failed(): void {
		$transport = new FakeHttpTransport();
		$transport->will_throw_on_redirect_check( TransportException::scrubbed( 'DNS failure', 'https://example.com' ) );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'download_failed', $result->code );
	}

	// ---- Redirect validation failure --------------------------------------------

	public function test_a_redirect_to_an_unlisted_host_is_rejected_as_unsafe(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( true, 'https://evil.example.com/x', 302 ) );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'unsafe_redirect', $result->code );
		// No download call is ever made against an unvalidated target.
		$this->assertSame( array(), $transport->download_calls );
	}

	public function test_the_unsafe_redirect_message_never_contains_the_raw_url(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( true, 'https://evil.example.com/secret?token=abc123', 302 ) );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertStringNotContainsString( 'evil.example.com', $result->message );
		$this->assertStringNotContainsString( 'secret', $result->message );
		$this->assertStringNotContainsString( 'abc123', $result->message );
	}

	public function test_a_redirect_with_http_instead_of_https_is_rejected(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( true, 'http://r2.cloudflarestorage.com/x', 302 ) );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'unsafe_redirect', $result->code );
	}

	// ---- Second-hop failure classification --------------------------------------

	public function test_a_second_hop_redirect_is_classified_as_unexpected_redirect_with_no_third_hop(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x', 302 ) );
		$transport->will_return_download( new DownloadResult( 302, 0 ) );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'unexpected_redirect', $result->code );
		$this->assertSame( 1, count( $transport->download_calls ) );
	}

	public function test_a_second_hop_non_200_status_is_classified_as_download_failed(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x', 302 ) );
		$transport->will_return_download( new DownloadResult( 500, 0 ) );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'download_failed', $result->code );
	}

	public function test_a_transport_exception_on_the_download_call_is_classified_as_download_failed(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x', 302 ) );
		$transport->will_throw_on_download( TransportException::scrubbed( 'connection reset', 'https://abc.r2.cloudflarestorage.com/x' ) );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'download_failed', $result->code );
	}

	// ---- Credential isolation proof test (the whole reason 6A exists) ----------

	public function test_authorization_header_is_sent_only_on_the_redirect_check_never_on_the_download(): void {
		$transport = new FakeHttpTransport();
		$archive   = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive );

		$this->manager( $transport, 'my-account-id', 'my-license-key' )->download_now( 'admin' );

		$this->assertCount( 1, $transport->redirect_calls );
		$this->assertArrayHasKey( 'Authorization', $transport->redirect_calls[0]['headers'] );
		$this->assertStringContainsString( 'Basic ', $transport->redirect_calls[0]['headers']['Authorization'] );

		$this->assertCount( 1, $transport->download_calls );
		$this->assertSame( array(), $transport->download_calls[0]['headers'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive );
	}

	public function test_no_result_message_ever_contains_the_configured_credentials(): void {
		$scenarios = array(
			static function ( FakeHttpTransport $t ): void {
				$t->will_return_redirect( new RedirectResult( false, null, 401 ) );
			},
			static function ( FakeHttpTransport $t ): void {
				$t->will_return_redirect( new RedirectResult( true, 'https://evil.example.com/x', 302 ) );
			},
			static function ( FakeHttpTransport $t ): void {
				$t->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x', 302 ) );
				$t->will_return_download( new DownloadResult( 500, 0 ) );
			},
		);

		foreach ( $scenarios as $configure ) {
			$transport = new FakeHttpTransport();
			$configure( $transport );

			$result = $this->manager( $transport, 'super-secret-account', 'super-secret-license' )->download_now( 'admin' );

			$this->assertStringNotContainsString( 'super-secret-account', $result->message );
			$this->assertStringNotContainsString( 'super-secret-license', $result->message );
		}
	}

	// ---- Archive / validation failure classification -----------------------------

	public function test_a_malformed_archive_is_classified_as_archive_invalid(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x', 302 ) );
		$transport->will_return_download( new DownloadResult( 200, 20 ), 'not-a-real-archive!!' );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'archive_invalid', $result->code );
	}

	public function test_an_archive_missing_the_database_is_classified_as_archive_invalid(): void {
		$base = sys_get_temp_dir() . '/ugeo-dbmgr-empty-' . uniqid( '', true );
		$tar  = $base . '.tar';
		$phar = new PharData( $tar );
		$phar->addFromString( 'GeoLite2-Country_20260101/COPYRIGHT.txt', 'copyright' );
		unset( $phar );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		$tar_bytes = (string) file_get_contents( $tar );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $tar );
		$archive_bytes = gzencode( $tar_bytes, 9 );

		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x', 302 ) );
		$transport->will_return_download( new DownloadResult( 200, strlen( $archive_bytes ) ), $archive_bytes );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'archive_invalid', $result->code );
	}

	public function test_a_database_with_the_wrong_edition_is_classified_as_validation_failed(): void {
		// A structurally-valid MaxMind database, but City rather than Country.
		$city_fixture = __DIR__ . '/../../fixtures/GeoIP2-City-Test.mmdb';

		$base = sys_get_temp_dir() . '/ugeo-dbmgr-wrongedition-' . uniqid( '', true );
		$tar  = $base . '.tar';
		$phar = new PharData( $tar );
		$phar->addFile( $city_fixture, 'GeoLite2-Country_20260101/GeoLite2-Country.mmdb' );
		unset( $phar );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		$tar_bytes = (string) file_get_contents( $tar );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $tar );
		$archive_bytes = gzencode( $tar_bytes, 9 );

		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x', 302 ) );
		$transport->will_return_download( new DownloadResult( 200, strlen( $archive_bytes ) ), $archive_bytes );

		$result = $this->manager( $transport )->download_now( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'validation_failed', $result->code );
	}

	public function test_validation_failure_does_not_install_or_bump_the_cache_epoch(): void {
		update_option( 'universal_geo_cache_epoch', 3 );

		$transport = new FakeHttpTransport();
		$transport->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x', 302 ) );
		$transport->will_return_download( new DownloadResult( 200, 20 ), 'not-a-real-archive!!' );

		$manager = $this->manager( $transport );
		$manager->download_now( 'admin' );

		$this->assertSame( '', $manager->installed_path() );
		$this->assertSame( 3, get_option( 'universal_geo_cache_epoch', 1 ) );
	}

	// ---- Directory-not-writable ---------------------------------------------------

	public function test_a_non_writable_managed_directory_is_reported_before_any_network_call(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $this->managed_dir, 0755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		chmod( $this->managed_dir, 0500 );

		// Running as root inside CI/containers ignores permission bits
		// entirely, making this scenario unconstructible there — skip
		// rather than assert a false positive.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( is_writable( $this->managed_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			chmod( $this->managed_dir, 0755 );
			$this->markTestSkipped( 'Running as a user for whom permission bits do not restrict writes (e.g. root).' );
		}

		$transport = new FakeHttpTransport();
		$result    = $this->manager( $transport )->download_now( 'admin' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- restore for tearDown()'s own cleanup.
		chmod( $this->managed_dir, 0755 );

		$this->assertFalse( $result->success );
		$this->assertSame( 'directory_not_writable', $result->code );
		$this->assertSame( 0, $transport->call_count() );
	}

	// ---- remove_managed_database() ------------------------------------------------

	public function test_remove_fails_with_not_installed_when_nothing_is_present(): void {
		$result = $this->manager( new FakeHttpTransport() )->remove_managed_database( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'not_installed', $result->code );
	}

	public function test_remove_deletes_an_installed_database(): void {
		$transport = new FakeHttpTransport();
		$archive   = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive );

		$manager = $this->manager( $transport );
		$manager->download_now( 'admin' );
		$this->assertNotSame( '', $manager->installed_path() );

		$result = $manager->remove_managed_database( 'admin' );

		$this->assertTrue( $result->success );
		$this->assertSame( '', $manager->installed_path() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive );
	}

	public function test_remove_fails_with_already_running_when_the_lock_is_held(): void {
		$lock = new UpdateLock( static fn (): int => self::FIXED_NOW );
		$lock->acquire( 'someone-else' );

		$result = $this->manager( new FakeHttpTransport(), 'a', 'b', true, $lock )->remove_managed_database( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'already_running', $result->code );
	}

	// ---- restore_previous() --------------------------------------------------------

	public function test_restore_fails_with_no_previous_version_when_none_exists(): void {
		$result = $this->manager( new FakeHttpTransport() )->restore_previous( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'no_previous_version', $result->code );
	}

	public function test_restore_promotes_a_valid_previous_generation_to_active(): void {
		$transport = new FakeHttpTransport();
		$archive   = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive );

		$manager = $this->manager( $transport );
		$manager->download_now( 'admin' );
		$this->assertNotSame( '', $manager->installed_path() );

		// Plants a .previous file directly — restore_previous() only
		// requires it to exist and be structurally valid, not that a
		// second download produced it.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		copy( self::FIXTURE_COUNTRY_DB, $this->managed_dir . '/GeoLite2-Country.mmdb.previous' );

		update_option( 'universal_geo_cache_epoch', 10 );

		$result = $manager->restore_previous( 'admin' );

		$this->assertTrue( $result->success );
		$this->assertSame( 'ok', $result->code );
		$this->assertNotSame( '', $manager->installed_path() );
		$this->assertFileDoesNotExist( $this->managed_dir . '/GeoLite2-Country.mmdb.previous' );
		$this->assertSame( 11, get_option( 'universal_geo_cache_epoch', 1 ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive );
	}

	public function test_restore_fails_with_already_running_when_the_lock_is_held(): void {
		$lock = new UpdateLock( static fn (): int => self::FIXED_NOW );
		$lock->acquire( 'someone-else' );

		$result = $this->manager( new FakeHttpTransport(), 'a', 'b', true, $lock )->restore_previous( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'already_running', $result->code );
	}

	public function test_restore_rejects_a_corrupt_previous_file(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $this->managed_dir, 0755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->managed_dir . '/GeoLite2-Country.mmdb.previous', 'not-a-real-database' );

		$result = $this->manager( new FakeHttpTransport() )->restore_previous( 'admin' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'validation_failed', $result->code );
	}

	// ---- validate_installed() -------------------------------------------------------

	public function test_validate_installed_fails_with_not_installed_when_nothing_is_present(): void {
		$result = $this->manager( new FakeHttpTransport() )->validate_installed();

		$this->assertFalse( $result->success );
		$this->assertSame( 'not_installed', $result->code );
	}

	public function test_validate_installed_passes_for_a_freshly_installed_database(): void {
		$transport = new FakeHttpTransport();
		$archive   = $this->build_valid_archive();
		$this->queue_successful_download( $transport, $archive );

		$manager = $this->manager( $transport );
		$manager->download_now( 'admin' );

		$result = $manager->validate_installed();

		$this->assertTrue( $result->success );
		$this->assertSame( 'ok', $result->code );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive );
	}

	public function test_validate_installed_fails_for_a_corrupted_active_file(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $this->managed_dir, 0755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->managed_dir . '/GeoLite2-Country.mmdb', 'not-a-real-database' );

		$result = $this->manager( new FakeHttpTransport() )->validate_installed();

		$this->assertFalse( $result->success );
		$this->assertSame( 'validation_failed', $result->code );
	}

	public function test_validate_installed_does_not_require_the_lock(): void {
		$lock = new UpdateLock( static fn (): int => self::FIXED_NOW );
		$lock->acquire( 'someone-else' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $this->managed_dir, 0755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->managed_dir . '/GeoLite2-Country.mmdb', 'not-a-real-database' );

		$result = $this->manager( new FakeHttpTransport(), 'a', 'b', true, $lock )->validate_installed();

		// A failure here must be 'validation_failed' (the file itself is
		// corrupt), never 'already_running' — proving the lock was never
		// consulted.
		$this->assertSame( 'validation_failed', $result->code );
	}
}
