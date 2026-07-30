<?php
/**
 * Unit tests for UniversalGeo\Admin\AdminScreen.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use UniversalGeo\Admin\AdminScreen;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use UniversalGeo\Tests\Support\ServerRequestFactory;

/**
 * Covers register()'s hook wiring and every WordPress-independent (or
 * WordPress-light) private helper directly via reflection:
 * parse_trusted_proxies_textarea(), active_tab(), notice_message(),
 * notice_redirect_url(), and should_show_first_run_notice(). The rendering
 * methods (render_settings_tab(), render_diagnostics_tab(), the notice
 * output) and the admin_post handlers (which end in wp_safe_redirect() +
 * exit — not safely callable inside a PHPUnit process) are exercised only
 * via the live browser acceptance step (M2 sub-step 2F), per the project's
 * own documented WP-CLI/CLI-cannot-exercise-header-trust precedent.
 */
final class AdminScreenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['universal_geo_test_options']                = array();
		$GLOBALS['universal_geo_test_object_cache']           = array();
		$GLOBALS['universal_geo_test_object_cache_calls']     = array();
		$GLOBALS['universal_geo_test_using_ext_object_cache'] = true;
		$GLOBALS['universal_geo_test_filters']                = array();
		$GLOBALS['universal_geo_test_actions']                = array();
		$GLOBALS['universal_geo_test_current_user_can']       = true;
	}

	private function diagnostics(
		string $peer = '203.0.113.1',
		array $trusted_cidrs = array(),
		bool $trust_cloudflare = false,
		array $headers = array()
	): DiagnosticsService {
		$request         = ServerRequestFactory::make( $peer, $headers );
		$trusted_proxies = new TrustedProxies( $trusted_cidrs, $trust_cloudflare );
		$ip_resolver     = new ClientIpResolver( $request, $trusted_proxies );
		$resolver        = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );

		return new DiagnosticsService(
			$resolver,
			$ip_resolver,
			$request,
			$trusted_proxies,
			array(),
			new ProviderHealthStore(),
			new MaxMindProvider( '' ),
			new CircuitBreaker(),
			'none',
			$this->unused_database_manager(),
			'none'
		);
	}

	private function unused_database_manager(): DatabaseManager {
		return new DatabaseManager(
			sys_get_temp_dir() . '/ugeo-admin-screen-unit-test-unused',
			'',
			'',
			true,
			new FakeHttpTransport(),
			new ArchiveExtractor(),
			new UpdateLock()
		);
	}

	private function screen( ?DiagnosticsService $diagnostics = null ): AdminScreen {
		return new AdminScreen(
			$diagnostics ?? $this->diagnostics(),
			ServerRequestFactory::make(),
			new UpdateScheduler( $this->unused_database_manager() )
		);
	}

	private function invoke_private( object $target, string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( $target, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $target, $args );
	}

	// ---- Class shape --------------------------------------------------------

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( AdminScreen::class ) )->isFinal() );
	}

	// ---- register() ----------------------------------------------------------

	public function test_register_wires_admin_menu(): void {
		$this->screen()->register();
		$this->assertArrayHasKey( 'admin_menu', $GLOBALS['universal_geo_test_actions'] );
	}

	public function test_register_wires_the_save_settings_handler(): void {
		$this->screen()->register();
		$this->assertArrayHasKey( 'admin_post_universal_geo_save_settings', $GLOBALS['universal_geo_test_actions'] );
	}

	public function test_register_wires_the_trust_peer_handler(): void {
		$this->screen()->register();
		$this->assertArrayHasKey( 'admin_post_universal_geo_trust_peer', $GLOBALS['universal_geo_test_actions'] );
	}

	public function test_register_wires_the_enable_cf_preset_handler(): void {
		$this->screen()->register();
		$this->assertArrayHasKey( 'admin_post_universal_geo_enable_cf_preset', $GLOBALS['universal_geo_test_actions'] );
	}

	public function test_register_wires_the_dismiss_notice_handler(): void {
		$this->screen()->register();
		$this->assertArrayHasKey( 'admin_post_universal_geo_dismiss_notice', $GLOBALS['universal_geo_test_actions'] );
	}

	public function test_register_wires_two_admin_notices_callbacks(): void {
		$this->screen()->register();
		$this->assertCount( 2, $GLOBALS['universal_geo_test_actions']['admin_notices'] );
	}

	// ---- parse_trusted_proxies_textarea() ------------------------------------------

	public function test_parse_trusted_proxies_textarea_splits_on_newlines(): void {
		$result = $this->invoke_private(
			$this->screen(),
			'parse_trusted_proxies_textarea',
			array( "172.18.0.0/16\n10.0.0.0/8" )
		);

		$this->assertSame( array( '172.18.0.0/16', '10.0.0.0/8' ), $result );
	}

	public function test_parse_trusted_proxies_textarea_trims_whitespace(): void {
		$result = $this->invoke_private(
			$this->screen(),
			'parse_trusted_proxies_textarea',
			array( "  172.18.0.0/16  \r\n  10.0.0.0/8  " )
		);

		$this->assertSame( array( '172.18.0.0/16', '10.0.0.0/8' ), $result );
	}

	public function test_parse_trusted_proxies_textarea_drops_blank_lines(): void {
		$result = $this->invoke_private(
			$this->screen(),
			'parse_trusted_proxies_textarea',
			array( "172.18.0.0/16\n\n\n10.0.0.0/8\n" )
		);

		$this->assertSame( array( '172.18.0.0/16', '10.0.0.0/8' ), $result );
	}

	public function test_parse_trusted_proxies_textarea_empty_string_yields_empty_array(): void {
		$this->assertSame( array(), $this->invoke_private( $this->screen(), 'parse_trusted_proxies_textarea', array( '' ) ) );
	}

	// ---- active_tab() -------------------------------------------------------------

	public function test_active_tab_defaults_to_settings(): void {
		unset( $_GET['tab'] );
		$this->assertSame( 'settings', $this->invoke_private( $this->screen(), 'active_tab' ) );
	}

	public function test_active_tab_recognizes_diagnostics(): void {
		$_GET['tab'] = 'diagnostics';
		$this->assertSame( 'diagnostics', $this->invoke_private( $this->screen(), 'active_tab' ) );
		unset( $_GET['tab'] );
	}

	public function test_active_tab_falls_back_to_settings_for_unrecognized_value(): void {
		$_GET['tab'] = 'something-else';
		$this->assertSame( 'settings', $this->invoke_private( $this->screen(), 'active_tab' ) );
		unset( $_GET['tab'] );
	}

	// ---- notice_message() -----------------------------------------------------------

	public function test_notice_message_known_keys(): void {
		$screen = $this->screen();

		$this->assertNotSame( '', $this->invoke_private( $screen, 'notice_message', array( 'saved' ) ) );
		$this->assertNotSame( '', $this->invoke_private( $screen, 'notice_message', array( 'peer_trusted' ) ) );
		$this->assertNotSame( '', $this->invoke_private( $screen, 'notice_message', array( 'cf_preset_enabled' ) ) );
		$this->assertNotSame( '', $this->invoke_private( $screen, 'notice_message', array( 'default_country_rejected' ) ) );
	}

	public function test_notice_message_unknown_key_is_empty(): void {
		$this->assertSame( '', $this->invoke_private( $this->screen(), 'notice_message', array( 'not-a-real-key' ) ) );
	}

	// ---- notice_redirect_url() -------------------------------------------------------

	public function test_notice_redirect_url_contains_the_message_and_type(): void {
		$url = $this->invoke_private( $this->screen(), 'notice_redirect_url', array( 'saved', 'success' ) );

		$this->assertStringContainsString( 'universal_geo_msg=saved', $url );
		$this->assertStringContainsString( 'universal_geo_typ=success', $url );
		$this->assertStringContainsString( 'page=universal-geo-context', $url );
	}

	// ---- should_show_first_run_notice() ------------------------------------------------

	public function test_first_run_notice_shows_when_misconfigured(): void {
		$diagnostics = $this->diagnostics( '172.18.0.5', array(), false, array( 'X-Real-IP' => '198.51.100.2' ) );
		$screen      = $this->screen( $diagnostics );

		$this->assertTrue( $this->invoke_private( $screen, 'should_show_first_run_notice' ) );
	}

	public function test_first_run_notice_does_not_show_when_trusted_proxies_configured(): void {
		$diagnostics = $this->diagnostics( '172.18.0.5', array( '172.18.0.0/16' ), false, array( 'X-Real-IP' => '198.51.100.2' ) );
		$screen      = $this->screen( $diagnostics );

		$this->assertFalse( $this->invoke_private( $screen, 'should_show_first_run_notice' ) );
	}

	public function test_first_run_notice_does_not_show_without_forwarding_headers(): void {
		$diagnostics = $this->diagnostics( '172.18.0.5' );
		$screen      = $this->screen( $diagnostics );

		$this->assertFalse( $this->invoke_private( $screen, 'should_show_first_run_notice' ) );
	}

	// ---- maxmind_path_is_valid() (M3) ------------------------------------------

	public function test_maxmind_path_is_valid_accepts_a_readable_file_under_wp_content_dir(): void {
		$path = WP_CONTENT_DIR . '/valid.mmdb';
		file_put_contents( $path, 'fixture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = $this->invoke_private( $this->screen(), 'maxmind_path_is_valid', array( $path ) );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertTrue( $result );
	}

	public function test_maxmind_path_is_valid_rejects_a_missing_file(): void {
		$result = $this->invoke_private( $this->screen(), 'maxmind_path_is_valid', array( WP_CONTENT_DIR . '/does-not-exist.mmdb' ) );

		$this->assertFalse( $result );
	}

	public function test_maxmind_path_is_valid_rejects_a_directory(): void {
		$result = $this->invoke_private( $this->screen(), 'maxmind_path_is_valid', array( WP_CONTENT_DIR ) );

		$this->assertFalse( $result );
	}

	public function test_maxmind_path_is_valid_rejects_an_unreadable_file(): void {
		$path = WP_CONTENT_DIR . '/unreadable.mmdb';
		file_put_contents( $path, 'fixture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		chmod( $path, 0000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		// Running as root (common in CI/containers) bypasses the 0000
		// permission bit entirely — skip rather than assert a false negative.
		if ( is_readable( $path ) ) {
			chmod( $path, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			$this->markTestSkipped( 'Current user can read a 0000-permission file (likely running as root); cannot exercise this case.' );
		}

		$result = $this->invoke_private( $this->screen(), 'maxmind_path_is_valid', array( $path ) );

		chmod( $path, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertFalse( $result );
	}

	public function test_maxmind_path_is_valid_rejects_a_path_outside_wp_content_dir(): void {
		$path = sys_get_temp_dir() . '/universal-geo-context-outside-' . uniqid() . '.mmdb';
		file_put_contents( $path, 'fixture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = $this->invoke_private( $this->screen(), 'maxmind_path_is_valid', array( $path ) );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertFalse( $result );
	}

	public function test_maxmind_path_is_valid_rejects_traversal_that_resolves_outside_wp_content_dir(): void {
		$outside = sys_get_temp_dir() . '/universal-geo-context-traversal-target-' . uniqid() . '.mmdb';
		file_put_contents( $outside, 'fixture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$traversal = rtrim( WP_CONTENT_DIR, '/' ) . '/../' . basename( $outside );

		$result = $this->invoke_private( $this->screen(), 'maxmind_path_is_valid', array( $traversal ) );

		unlink( $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertFalse( $result );
	}

	public function test_maxmind_path_is_valid_accepts_a_file_reached_via_traversal_that_still_resolves_inside(): void {
		$sub_dir = WP_CONTENT_DIR . '/uploads';
		if ( ! is_dir( $sub_dir ) ) {
			mkdir( $sub_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		$path = WP_CONTENT_DIR . '/inside-via-traversal.mmdb';
		file_put_contents( $path, 'fixture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$traversal = $sub_dir . '/../inside-via-traversal.mmdb';

		$result = $this->invoke_private( $this->screen(), 'maxmind_path_is_valid', array( $traversal ) );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertTrue( $result );
	}

	// ---- remote_credentials_locked_by_constants() (M4) -------------------------

	public function test_remote_credentials_not_locked_when_no_constants_defined(): void {
		$this->assertFalse( $this->invoke_private( $this->screen(), 'remote_credentials_locked_by_constants' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_remote_credentials_locked_when_both_constants_defined(): void {
		define( 'UNIVERSAL_GEO_REMOTE_ACCOUNT_ID', 'constant-account' );
		define( 'UNIVERSAL_GEO_REMOTE_LICENSE_KEY', 'constant-license' );

		$this->assertTrue( $this->invoke_private( $this->screen(), 'remote_credentials_locked_by_constants' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_remote_credentials_not_locked_when_only_one_constant_defined(): void {
		define( 'UNIVERSAL_GEO_REMOTE_ACCOUNT_ID', 'constant-account' );

		$this->assertFalse( $this->invoke_private( $this->screen(), 'remote_credentials_locked_by_constants' ) );
	}

	// ---- submitted_credential() (M4 credential clearing behavior) ---------------

	public function test_submitted_credential_blank_submission_keeps_the_previous_value(): void {
		$_POST = array( 'remote_account_id' => '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = $this->invoke_private( $this->screen(), 'submitted_credential', array( 'remote_account_id', 'previous-value' ) );

		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->assertSame( 'previous-value', $result );
	}

	public function test_submitted_credential_omitted_field_keeps_the_previous_value(): void {
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = $this->invoke_private( $this->screen(), 'submitted_credential', array( 'remote_account_id', 'previous-value' ) );

		$this->assertSame( 'previous-value', $result );
	}

	public function test_submitted_credential_non_blank_submission_replaces_the_previous_value(): void {
		$_POST = array( 'remote_account_id' => 'new-value' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = $this->invoke_private( $this->screen(), 'submitted_credential', array( 'remote_account_id', 'previous-value' ) );

		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->assertSame( 'new-value', $result );
	}

	public function test_submitted_credential_clear_checkbox_blanks_regardless_of_typed_value(): void {
		$_POST = array( // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'remote_account_id'        => 'typed-value',
			'remote_clear_credentials' => '1',
		);

		$result = $this->invoke_private( $this->screen(), 'submitted_credential', array( 'remote_account_id', 'previous-value' ) );

		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->assertSame( '', $result );
	}
}
