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
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\Resolver\ContextResolver;
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

		return new DiagnosticsService( $resolver, $ip_resolver, $request, $trusted_proxies, array() );
	}

	private function screen( ?DiagnosticsService $diagnostics = null ): AdminScreen {
		return new AdminScreen( $diagnostics ?? $this->diagnostics(), ServerRequestFactory::make() );
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
}
