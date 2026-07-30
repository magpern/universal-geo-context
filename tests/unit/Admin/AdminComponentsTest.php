<?php
/**
 * Unit tests for decomposed admin components (M7).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use UniversalGeo\Admin\AdminNotices;
use UniversalGeo\Admin\AdminPageSlugs;
use UniversalGeo\Admin\DetectionPage;
use UniversalGeo\Admin\FirstRunNotice;
use UniversalGeo\Admin\Menu;
use UniversalGeo\Admin\OverviewPage;
use UniversalGeo\Admin\ProvidersPage;
use UniversalGeo\Admin\ReportRenderer;
use UniversalGeo\Admin\SettingsPage;
use UniversalGeo\Admin\TrustedProxiesPage;
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
 * Covers hook wiring, page contracts, shared helpers, and capability gates.
 */
final class AdminComponentsTest extends TestCase {

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
			sys_get_temp_dir() . '/ugeo-admin-unit-test-unused',
			'',
			'',
			true,
			new FakeHttpTransport(),
			new ArchiveExtractor(),
			new UpdateLock()
		);
	}

	private function settings_page(): SettingsPage {
		$database_manager = $this->unused_database_manager();

		return new SettingsPage(
			new UpdateScheduler( $database_manager ),
			$database_manager,
			new AdminNotices()
		);
	}

	private function trusted_proxies_page( ?DiagnosticsService $diagnostics = null ): TrustedProxiesPage {
		return new TrustedProxiesPage(
			$diagnostics ?? $this->diagnostics(),
			ServerRequestFactory::make(),
			new ReportRenderer( $diagnostics ?? $this->diagnostics() ),
			new AdminNotices()
		);
	}

	private function menu(): Menu {
		$diagnostics      = $this->diagnostics();
		$database_manager = $this->unused_database_manager();
		$notices          = new AdminNotices();
		$renderer         = new ReportRenderer( $diagnostics );
		$resolver         = new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) ),
			array(),
			new GeoCache( false, 900, 'sig' )
		);

		return new Menu(
			new OverviewPage( $diagnostics, $resolver, $renderer, $notices ),
			new DetectionPage(),
			new ProvidersPage(),
			new TrustedProxiesPage( $diagnostics, ServerRequestFactory::make(), $renderer, $notices ),
			new \UniversalGeo\Admin\DiagnosticsPage( $diagnostics, $renderer ),
			new SettingsPage( new UpdateScheduler( $database_manager ), $database_manager, $notices )
		);
	}

	private function invoke_private( object $target, string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( $target, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $target, $args );
	}

	public function test_page_slugs_are_stable(): void {
		$this->assertSame( 'universal-geo-context', AdminPageSlugs::OVERVIEW );
		$this->assertSame( 'universal-geo-context-detection', AdminPageSlugs::DETECTION );
		$this->assertSame( 'universal-geo-context-providers', AdminPageSlugs::PROVIDERS );
		$this->assertSame( 'universal-geo-context-trusted-proxies', AdminPageSlugs::TRUSTED_PROXIES );
		$this->assertSame( 'universal-geo-context-diagnostics', AdminPageSlugs::DIAGNOSTICS );
		$this->assertSame( 'universal-geo-context-settings', AdminPageSlugs::SETTINGS );
	}

	public function test_menu_register_wires_handlers(): void {
		$this->menu()->register();
		$this->assertArrayHasKey( 'admin_menu', $GLOBALS['universal_geo_test_actions'] );
		$this->assertArrayHasKey( 'admin_init', $GLOBALS['universal_geo_test_actions'] );
		$this->assertArrayHasKey( 'admin_post_universal_geo_save_settings', $GLOBALS['universal_geo_test_actions'] );
		$this->assertArrayHasKey( 'admin_post_universal_geo_refresh_providers', $GLOBALS['universal_geo_test_actions'] );
	}

	public function test_notice_redirect_url_uses_admin_php(): void {
		$notices = new AdminNotices();
		$url     = $notices->notice_redirect_url( AdminPageSlugs::SETTINGS, 'saved', 'success' );

		$this->assertStringContainsString( 'admin.php', $url );
		$this->assertStringContainsString( 'page=' . AdminPageSlugs::SETTINGS, $url );
	}

	public function test_parse_trusted_proxies_textarea(): void {
		$page   = $this->trusted_proxies_page();
		$result = $this->invoke_private( $page, 'parse_trusted_proxies_textarea', array( "172.18.0.0/16\n10.0.0.0/8" ) );
		$this->assertSame( array( '172.18.0.0/16', '10.0.0.0/8' ), $result );
	}

	public function test_maxmind_path_is_valid_accepts_file_under_wp_content_dir(): void {
		$path = WP_CONTENT_DIR . '/valid.mmdb';
		file_put_contents( $path, 'fixture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = $this->invoke_private( $this->settings_page(), 'maxmind_path_is_valid', array( $path ) );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertTrue( $result );
	}

	public function test_submitted_credential_blank_keeps_previous(): void {
		$_POST  = array( 'maxmind_account_id' => '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = $this->invoke_private(
			$this->settings_page(),
			'submitted_credential',
			array( 'maxmind_account_id', 'previous-value', 'maxmind_clear_credentials' )
		);
		$_POST  = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->assertSame( 'previous-value', $result );
	}

	public function test_first_run_notice_when_misconfigured(): void {
		$diagnostics = $this->diagnostics( '172.18.0.5', array(), false, array( 'X-Real-IP' => '198.51.100.2' ) );
		$notice      = new FirstRunNotice( $diagnostics );
		$this->assertTrue( $this->invoke_private( $notice, 'should_show_first_run_notice' ) );
	}

	public function test_worst_site_health_status_critical_wins(): void {
		$diagnostics = $this->diagnostics( '172.18.0.5', array(), false, array( 'X-Real-IP' => '198.51.100.2' ) );
		$this->assertSame( 'critical', $diagnostics->worst_site_health_status() );
	}

	public function test_detection_page_active_tab(): void {
		unset( $_GET['tab'] );
		$page = new DetectionPage();
		$this->assertSame( 'live', $this->invoke_private( $page, 'active_tab' ) );

		$_GET['tab'] = 'simulation';
		$this->assertSame( 'simulation', $this->invoke_private( $page, 'active_tab' ) );
		unset( $_GET['tab'] );
	}

	public function test_overview_page_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( SettingsPage::class ) )->isFinal() );
	}

	public function test_overview_renders_refresh_now_when_provider_health_is_empty(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		\UniversalGeo\Plugin::instance()->init();

		$diagnostics = $this->diagnostics();
		$resolver    = new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) ),
			array(),
			new GeoCache( false, 900, 'sig' )
		);
		$page        = new OverviewPage( $diagnostics, $resolver, new ReportRenderer( $diagnostics ), new AdminNotices() );

		ob_start();
		$page->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="action" value="universal_geo_refresh_providers"', $html );
		$this->assertStringContainsString( 'Refresh now', $html );
	}
}
