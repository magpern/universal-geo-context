<?php
/**
 * Integration tests for UniversalGeo\Admin\AdminScreen's four M6
 * managed-database admin_post_* actions.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration\Admin;

use UniversalGeo\Admin\AdminNotices;
use UniversalGeo\Tests\Support\AdminUxFactory;
use UniversalGeo\Admin\SettingsPage;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Providers\Remote\DownloadResult;
use UniversalGeo\Providers\Remote\RedirectResult;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Settings;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use WP_UnitTestCase;

/**
 * Covers capability/nonce checks and notice routing for
 * handle_maxmind_database_download()/validate()/remove()/restore() —
 * `handle_save_settings()`'s own sibling handlers, using the identical
 * redirect-trap technique `AdminScreenTest` already established.
 */
final class AdminScreenMaxMindDatabaseTest extends WP_UnitTestCase {

	private const COUNTRY_DB = __DIR__ . '/../../fixtures/GeoIP2-Country-Test.mmdb';

	protected function setUp(): void {
		parent::setUp();

		update_option( Settings::OPTION_NAME, Settings::defaults() );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		parent::tearDown();
	}

	private function managed_dir(): string {
		return sys_get_temp_dir() . '/ugeo-admin-maxmind-db-test-' . uniqid( '', true );
	}

	private function settings_page( DatabaseManager $database_manager ): SettingsPage {
		return new SettingsPage(
			new UpdateScheduler( $database_manager ),
			$database_manager,
			new AdminNotices(),
			AdminUxFactory::header(),
			AdminUxFactory::actions()
		);
	}

	/**
	 * Triggers $action on $screen (a bound method reference to one of the
	 * four handlers) and captures the redirect location, mirroring
	 * AdminScreenTest::submit()'s own redirect-trap technique.
	 */
	private function trigger( SettingsPage $page, string $nonce_action, callable $handler ): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $nonce_action ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		$captured = '';
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = $location;
				throw new \RuntimeException( 'redirect-trap' );
			}
		);

		try {
			$handler( $page );
			$this->fail( 'Expected the redirect trap to interrupt execution.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect-trap', $e->getMessage() );
		}

		remove_all_filters( 'wp_redirect' );
		unset( $_REQUEST['_wpnonce'] );

		return $captured;
	}

	// ---- Capability checks -----------------------------------------------------

	public function test_download_requires_manage_options(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'universal_geo_maxmind_database_download' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		$this->expectException( \WPDieException::class );

		$this->settings_page( $this->unused_database_manager() )->handle_maxmind_database_download();
	}

	public function test_remove_requires_manage_options(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'universal_geo_maxmind_database_remove' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		$this->expectException( \WPDieException::class );

		$this->settings_page( $this->unused_database_manager() )->handle_maxmind_database_remove();
	}

	public function test_restore_requires_manage_options(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'universal_geo_maxmind_database_restore' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		$this->expectException( \WPDieException::class );

		$this->settings_page( $this->unused_database_manager() )->handle_maxmind_database_restore();
	}

	public function test_validate_requires_manage_options(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'universal_geo_maxmind_database_validate' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		$this->expectException( \WPDieException::class );

		$this->settings_page( $this->unused_database_manager() )->handle_maxmind_database_validate();
	}

	/**
	 * Queues a matching `.sha256` sidecar redirect-check + download pair
	 * (M6J) after an already-queued successful archive fetch, so the flow
	 * proceeds past this new gate exactly as before.
	 */
	private function queue_matching_checksum( FakeHttpTransport $transport, string $archive_bytes ): void {
		$body = hash( 'sha256', $archive_bytes ) . '  GeoLite2-Country_20260101.tar.gz';

		$transport->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x.sha256', 302 ) );
		$transport->will_return_download( new DownloadResult( 200, strlen( $body ) ), $body );
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

	// ---- Happy-path outcomes -----------------------------------------------------

	public function test_download_installs_and_redirects_with_a_success_notice(): void {
		$transport = new FakeHttpTransport();
		$archive   = $this->build_valid_archive();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		$archive_bytes = (string) file_get_contents( $archive );
		$transport->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x', 302 ) );
		$transport->will_return_download( new DownloadResult( 200, strlen( $archive_bytes ) ), $archive_bytes );
		$this->queue_matching_checksum( $transport, $archive_bytes );

		$database_manager = new DatabaseManager( $this->managed_dir(), 'account', 'license', true, $transport, new ArchiveExtractor(), new UpdateLock() );

		$location = $this->trigger(
			$this->settings_page( $database_manager ),
			'universal_geo_maxmind_database_download',
			static function ( SettingsPage $page ): void {
				$page->handle_maxmind_database_download();
			}
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive );

		$this->assertStringContainsString( 'universal_geo_msg=maxmind_download_ok', $location );
		$this->assertStringContainsString( 'universal_geo_typ=success', $location );
		$this->assertNotSame( '', $database_manager->installed_path() );
	}

	public function test_download_with_no_credentials_redirects_with_a_failure_notice(): void {
		$database_manager = $this->unused_database_manager();

		$location = $this->trigger(
			$this->settings_page( $database_manager ),
			'universal_geo_maxmind_database_download',
			static function ( SettingsPage $page ): void {
				$page->handle_maxmind_database_download();
			}
		);

		$this->assertStringContainsString( 'universal_geo_msg=maxmind_download_failed', $location );
		$this->assertStringContainsString( 'universal_geo_typ=warning', $location );
	}

	public function test_validate_with_nothing_installed_redirects_with_a_failure_notice(): void {
		$database_manager = $this->unused_database_manager();

		$location = $this->trigger(
			$this->settings_page( $database_manager ),
			'universal_geo_maxmind_database_validate',
			static function ( SettingsPage $page ): void {
				$page->handle_maxmind_database_validate();
			}
		);

		$this->assertStringContainsString( 'universal_geo_msg=maxmind_validate_failed', $location );
	}

	public function test_remove_after_a_successful_download_redirects_with_a_success_notice(): void {
		$transport = new FakeHttpTransport();
		$archive   = $this->build_valid_archive();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		$archive_bytes = (string) file_get_contents( $archive );
		$transport->will_return_redirect( new RedirectResult( true, 'https://abc.r2.cloudflarestorage.com/x', 302 ) );
		$transport->will_return_download( new DownloadResult( 200, strlen( $archive_bytes ) ), $archive_bytes );
		$this->queue_matching_checksum( $transport, $archive_bytes );

		$database_manager = new DatabaseManager( $this->managed_dir(), 'account', 'license', true, $transport, new ArchiveExtractor(), new UpdateLock() );
		$database_manager->download_now( 'admin' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $archive );

		$location = $this->trigger(
			$this->settings_page( $database_manager ),
			'universal_geo_maxmind_database_remove',
			static function ( SettingsPage $page ): void {
				$page->handle_maxmind_database_remove();
			}
		);

		$this->assertStringContainsString( 'universal_geo_msg=maxmind_remove_ok', $location );
		$this->assertSame( '', $database_manager->installed_path() );
	}

	public function test_restore_with_no_previous_version_redirects_with_a_failure_notice(): void {
		$database_manager = $this->unused_database_manager();

		$location = $this->trigger(
			$this->settings_page( $database_manager ),
			'universal_geo_maxmind_database_restore',
			static function ( SettingsPage $page ): void {
				$page->handle_maxmind_database_restore();
			}
		);

		$this->assertStringContainsString( 'universal_geo_msg=maxmind_restore_failed', $location );
	}

	/**
	 * Builds a valid GeoLite2 Country archive the same way
	 * ArchiveExtractorTest/DatabaseManagerTest do — gzencode()-ing raw tar
	 * bytes directly, never PharData::compress(), to avoid a same-process
	 * Phar manifest-caching quirk confirmed while writing those tests.
	 */
	private function build_valid_archive(): string {
		$base = sys_get_temp_dir() . '/ugeo-admin-maxmind-archive-' . uniqid( '', true );
		$tar  = $base . '.tar';
		$gz   = $tar . '.gz';

		$phar = new \PharData( $tar );
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
}
