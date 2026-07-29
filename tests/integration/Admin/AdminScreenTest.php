<?php
/**
 * Integration tests for UniversalGeo\Admin\AdminScreen::handle_save_settings().
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration\Admin;

use UniversalGeo\Admin\AdminScreen;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Settings;
use WP_UnitTestCase;

/**
 * Covers the maxmind_db_path save-handler behavior the M3 architecture
 * report §6 3B requires: filesystem validation only at save time (never in
 * Settings::sanitize()), acceptance stores the submitted path verbatim,
 * rejection retains the previously stored value and shows an error notice.
 *
 * The method under test, `handle_save_settings()`, ends in
 * `wp_safe_redirect()` + `exit` — not directly callable inside a PHPUnit
 * process at all (the unit suite's own AdminScreenTest docblock notes
 * this). The standard WP core testing technique applies here: trap
 * `wp_redirect()` with a throwing filter so execution unwinds before the
 * fatal exit.
 */
final class AdminScreenTest extends WP_UnitTestCase {

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

	private function screen(): AdminScreen {
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
			'none'
		);

		return new AdminScreen( $diagnostics, $request );
	}

	/**
	 * Submits the save form and captures the redirect location rather than
	 * letting wp_safe_redirect() run to exit().
	 */
	private function submit( array $post ): string {
		$_POST                = $post;
		$_POST['_wpnonce']    = wp_create_nonce( 'universal_geo_save_settings' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		$captured = '';
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = $location;
				throw new \RuntimeException( 'redirect-trap' );
			}
		);

		try {
			$this->screen()->handle_save_settings();
			$this->fail( 'Expected the redirect trap to interrupt execution.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect-trap', $e->getMessage() );
		}

		remove_all_filters( 'wp_redirect' );
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return $captured;
	}

	// ---- Acceptance -------------------------------------------------------------

	public function test_accepted_path_is_stored_verbatim_not_realpathed(): void {
		$sub_dir = WP_CONTENT_DIR . '/ugeo-test-uploads';
		if ( ! is_dir( $sub_dir ) ) {
			mkdir( $sub_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
		$path = $sub_dir . '/../ugeo-test-uploads/geo.mmdb';
		copy( self::COUNTRY_DB, $sub_dir . '/geo.mmdb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$location = $this->submit( array( 'maxmind_db_path' => $path ) );

		$stored = get_option( Settings::OPTION_NAME );

		unlink( $sub_dir . '/geo.mmdb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		rmdir( $sub_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir

		// Stored verbatim (with the traversal segment), not realpath()'d.
		$this->assertSame( $path, $stored['maxmind_db_path'] );
		$this->assertStringContainsString( 'universal_geo_msg=saved', $location );
	}

	// ---- Rejection: prior-value retention ----------------------------------------

	public function test_rejected_path_retains_the_previous_value_and_shows_an_error(): void {
		$sub_dir = WP_CONTENT_DIR . '/ugeo-test-uploads-2';
		if ( ! is_dir( $sub_dir ) ) {
			mkdir( $sub_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
		copy( self::COUNTRY_DB, $sub_dir . '/geo.mmdb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		$previous_valid_path = $sub_dir . '/geo.mmdb';

		update_option(
			Settings::OPTION_NAME,
			Settings::sanitize( array( 'maxmind_db_path' => $previous_valid_path ) )
		);

		// Submit a syntactically valid absolute path that does not exist.
		$location = $this->submit( array( 'maxmind_db_path' => WP_CONTENT_DIR . '/does-not-exist.mmdb' ) );

		$stored = get_option( Settings::OPTION_NAME );

		unlink( $sub_dir . '/geo.mmdb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		rmdir( $sub_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir

		$this->assertSame( $previous_valid_path, $stored['maxmind_db_path'] );
		$this->assertStringContainsString( 'universal_geo_msg=maxmind_path_rejected', $location );
	}

	public function test_containment_violation_is_rejected(): void {
		$outside = sys_get_temp_dir() . '/ugeo-outside-content-dir-' . uniqid() . '.mmdb';
		copy( self::COUNTRY_DB, $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$location = $this->submit( array( 'maxmind_db_path' => $outside ) );

		$stored = get_option( Settings::OPTION_NAME );
		unlink( $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( '', $stored['maxmind_db_path'] );
		$this->assertStringContainsString( 'universal_geo_msg=maxmind_path_rejected', $location );
	}

	public function test_directory_rather_than_file_is_rejected(): void {
		$location = $this->submit( array( 'maxmind_db_path' => rtrim( WP_CONTENT_DIR, '/' ) ) );

		$stored = get_option( Settings::OPTION_NAME );

		$this->assertSame( '', $stored['maxmind_db_path'] );
		$this->assertStringContainsString( 'universal_geo_msg=maxmind_path_rejected', $location );
	}

	// ---- Other settings still save on rejection ------------------------------------

	public function test_other_settings_still_save_when_the_maxmind_path_is_rejected(): void {
		$this->submit(
			array(
				'default_country' => 'se',
				'maxmind_db_path' => WP_CONTENT_DIR . '/does-not-exist.mmdb',
			)
		);

		$stored = get_option( Settings::OPTION_NAME );

		$this->assertSame( 'SE', $stored['default_country'] );
	}

	// ---- Epoch bump ---------------------------------------------------------------

	public function test_accepted_save_bumps_the_cache_epoch(): void {
		$before = get_option( 'universal_geo_cache_epoch', 1 );

		$this->submit( array( 'default_country' => 'de' ) );

		$after = get_option( 'universal_geo_cache_epoch', 1 );

		$this->assertGreaterThan( $before, $after );
	}

	// ---- M4: remote_enabled requires the transfer acknowledgement -------------------

	public function test_remote_enabled_without_acknowledgement_is_rejected_with_a_dedicated_warning(): void {
		$location = $this->submit( array( 'remote_enabled' => '1' ) );

		$stored = get_option( Settings::OPTION_NAME );

		$this->assertFalse( $stored['remote_enabled'] );
		$this->assertStringContainsString( 'universal_geo_msg=remote_enable_requires_acknowledgement', $location );
	}

	public function test_remote_enabled_with_acknowledgement_in_the_same_submission_is_accepted(): void {
		$location = $this->submit(
			array(
				'remote_enabled'               => '1',
				'remote_transfer_acknowledged' => '1',
			)
		);

		$stored = get_option( Settings::OPTION_NAME );

		$this->assertTrue( $stored['remote_enabled'] );
		$this->assertStringContainsString( 'universal_geo_msg=saved', $location );
	}

	public function test_unchecking_acknowledgement_while_leaving_enabled_checked_disables_it_with_a_warning(): void {
		update_option(
			Settings::OPTION_NAME,
			Settings::sanitize(
				array(
					'remote_enabled'               => true,
					'remote_transfer_acknowledged' => true,
				)
			)
		);

		// remote_transfer_acknowledged omitted this time (an unchecked checkbox).
		$location = $this->submit( array( 'remote_enabled' => '1' ) );

		$stored = get_option( Settings::OPTION_NAME );

		$this->assertFalse( $stored['remote_enabled'] );
		$this->assertStringContainsString( 'universal_geo_msg=remote_enable_requires_acknowledgement', $location );
	}

	// ---- M4: credential clearing behavior ----------------------------------------

	public function test_blank_credential_submission_keeps_the_previously_stored_value(): void {
		update_option(
			Settings::OPTION_NAME,
			Settings::sanitize(
				array(
					'remote_account_id'  => 'existing-account',
					'remote_license_key' => 'existing-license',
				)
			)
		);

		// Both credential fields omitted, as a real <input type="password">
		// left blank (or rendered disabled) would submit.
		$this->submit( array( 'default_country' => 'de' ) );

		$stored = get_option( Settings::OPTION_NAME );

		$this->assertSame( 'existing-account', $stored['remote_account_id'] );
		$this->assertSame( 'existing-license', $stored['remote_license_key'] );
	}

	public function test_non_blank_credential_submission_replaces_the_stored_value(): void {
		update_option(
			Settings::OPTION_NAME,
			Settings::sanitize( array( 'remote_account_id' => 'old-account' ) )
		);

		$this->submit( array( 'remote_account_id' => 'new-account' ) );

		$stored = get_option( Settings::OPTION_NAME );

		$this->assertSame( 'new-account', $stored['remote_account_id'] );
	}

	public function test_clear_credentials_checkbox_blanks_both_fields_regardless_of_what_is_also_typed(): void {
		update_option(
			Settings::OPTION_NAME,
			Settings::sanitize(
				array(
					'remote_account_id'  => 'existing-account',
					'remote_license_key' => 'existing-license',
				)
			)
		);

		$this->submit(
			array(
				'remote_account_id'        => 'typed-but-ignored',
				'remote_clear_credentials' => '1',
			)
		);

		$stored = get_option( Settings::OPTION_NAME );

		$this->assertSame( '', $stored['remote_account_id'] );
		$this->assertSame( '', $stored['remote_license_key'] );
	}

	// ---- M4: AdminScreen::uninstall() --------------------------------------------

	public function test_uninstall_deletes_the_first_run_notice_meta_for_every_user(): void {
		$user_a = self::factory()->user->create();
		$user_b = self::factory()->user->create();

		update_user_meta( $user_a, 'universal_geo_first_run_notice_dismissed', 1 );
		update_user_meta( $user_b, 'universal_geo_first_run_notice_dismissed', 1 );

		AdminScreen::uninstall();

		$this->assertSame( '', get_user_meta( $user_a, 'universal_geo_first_run_notice_dismissed', true ) );
		$this->assertSame( '', get_user_meta( $user_b, 'universal_geo_first_run_notice_dismissed', true ) );
	}
}
