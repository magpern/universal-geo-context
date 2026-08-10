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
use UniversalGeo\Admin\AdminPageRegistry;
use UniversalGeo\Admin\AdminPageSlugs;
use UniversalGeo\Admin\AdminProbeFreshFlag;
use UniversalGeo\Admin\DefinitionListRenderer;
use UniversalGeo\Admin\DetectionInspectorRenderer;
use UniversalGeo\Admin\DetectionPage;
use UniversalGeo\Admin\FirstRunNotice;
use UniversalGeo\Admin\Menu;
use UniversalGeo\Admin\OverviewPage;
use UniversalGeo\Admin\ProvidersPage;
use UniversalGeo\Admin\ReportRenderer;
use UniversalGeo\Admin\SettingsPage;
use UniversalGeo\Admin\TimelineRenderer;
use UniversalGeo\Admin\TrustedProxiesPage;
use UniversalGeo\Simulation\CountryCatalog;
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationController;
use UniversalGeo\Simulation\SimulationCookie;
use UniversalGeo\Simulation\SimulationState;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Explanation\DetectionInspectorService;
use UniversalGeo\Explanation\ExplanationFormatter;
use UniversalGeo\Explanation\ProviderExplanationBuilder;
use UniversalGeo\Explanation\ResolutionTimelineBuilder;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Tests\Support\AdminUxFactory;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use UniversalGeo\Tests\Support\ServerRequestFactory;
use UniversalGeo\Tests\Unit\Doubles\TrackingGeoProvider;
use UniversalGeo\Model\GeoCandidate;

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
			'none',
			new GeoCache( false, 900, 'sig' ),
			new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-m12-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ),
			new SimulationState( new SimulationCookie(), new SimulationAuthorization() )
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
			new AdminNotices(),
			AdminUxFactory::header(),
			AdminUxFactory::actions(),
			AdminUxFactory::components(),
			new ReportRenderer( new DefinitionListRenderer( $this->diagnostics() ) )
		);
	}

	private function trusted_proxies_page( ?DiagnosticsService $diagnostics = null ): TrustedProxiesPage {
		$diagnostics = $diagnostics ?? $this->diagnostics();

		return new TrustedProxiesPage(
			$diagnostics,
			ServerRequestFactory::make(),
			new ReportRenderer( new DefinitionListRenderer( $diagnostics ) ),
			new AdminNotices(),
			AdminUxFactory::header(),
			AdminUxFactory::actions(),
			AdminUxFactory::components()
		);
	}

	private function inspector_service( ContextResolver $resolver, DiagnosticsService $diagnostics ): DetectionInspectorService {
		$ip_resolver = new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) );

		return new DetectionInspectorService(
			$resolver,
			$ip_resolver,
			new GeoCache( false, 900, 'sig' ),
			$diagnostics,
			new SimulationState( new SimulationCookie(), new SimulationAuthorization() ),
			new ProviderExplanationBuilder( $resolver ),
			new ResolutionTimelineBuilder()
		);
	}

	private function detection_page( ?ContextResolver $resolver = null ): DetectionPage {
		$resolver    = $resolver ?? new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) ),
			array(),
			new GeoCache( false, 900, 'sig' )
		);
		$cookie      = new SimulationCookie();
		$state       = new SimulationState( $cookie, new SimulationAuthorization() );
		$diagnostics = $this->diagnostics();
		$inspector   = $this->inspector_service( $resolver, $diagnostics );
		$diagnostics = $this->diagnostics();
		$definition  = new DefinitionListRenderer( $diagnostics );
		$renderer    = new DetectionInspectorRenderer(
			new ReportRenderer( $definition ),
			new ExplanationFormatter(),
			$diagnostics,
			AdminUxFactory::components(),
			new TimelineRenderer( AdminUxFactory::components(), new ExplanationFormatter() )
		);

		return new DetectionPage(
			$resolver,
			$state,
			new CountryCatalog(),
			new SimulationController( $cookie, $state, new AdminNotices() ),
			$inspector,
			$renderer,
			AdminUxFactory::header(),
			AdminUxFactory::actions(),
			AdminUxFactory::components(),
			new ReportRenderer( $definition )
		);
	}

	private function menu(): Menu {
		$diagnostics      = $this->diagnostics();
		$database_manager = $this->unused_database_manager();
		$notices          = new AdminNotices();
		$definition       = new DefinitionListRenderer( $diagnostics );
		$renderer         = new ReportRenderer( $definition );
		$resolver         = new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) ),
			array(),
			new GeoCache( false, 900, 'sig' )
		);
		$state            = new SimulationState( new SimulationCookie(), new SimulationAuthorization() );

		return new Menu(
			new OverviewPage( $diagnostics, $resolver, $renderer, $notices, AdminUxFactory::header(), AdminUxFactory::quick_actions(), AdminUxFactory::actions(), AdminUxFactory::components(), $state, AdminUxFactory::operational_status() ),
			$this->detection_page( $resolver ),
			new ProvidersPage( $this->inspector_service( $resolver, $diagnostics ), $renderer, AdminUxFactory::header(), AdminUxFactory::actions(), AdminUxFactory::components() ),
			new TrustedProxiesPage( $diagnostics, ServerRequestFactory::make(), $renderer, $notices, AdminUxFactory::header(), AdminUxFactory::actions(), AdminUxFactory::components() ),
			new \UniversalGeo\Admin\DiagnosticsPage( $diagnostics, $renderer, AdminUxFactory::header(), AdminUxFactory::actions(), AdminUxFactory::components(), AdminUxFactory::operational_status() ),
			new SettingsPage( new UpdateScheduler( $database_manager ), $database_manager, $notices, AdminUxFactory::header(), AdminUxFactory::actions(), AdminUxFactory::components(), $renderer )
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
		$this->assertArrayHasKey( 'admin_post_universal_geo_save_settings', $GLOBALS['universal_geo_test_actions'] );
		$this->assertArrayHasKey( 'admin_post_universal_geo_refresh_providers', $GLOBALS['universal_geo_test_actions'] );
		$this->assertArrayHasKey( 'admin_post_universal_geo_simulation_start', $GLOBALS['universal_geo_test_actions'] );
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
		$page = $this->detection_page();
		$this->assertSame( 'live', $this->invoke_private( $page, 'active_tab' ) );

		$_GET['tab'] = 'simulation';
		$this->assertSame( 'simulation', $this->invoke_private( $page, 'active_tab' ) );
		unset( $_GET['tab'] );
	}

	public function test_detection_page_renders_inspector_sections(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		\UniversalGeo\Plugin::instance()->init();

		ob_start();
		$this->detection_page()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Resolution timeline', $html );
		$this->assertStringContainsString( 'Provider results', $html );
		$this->assertStringContainsString( 'universal_geo_refresh_providers', $html );
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
		$page        = new OverviewPage(
			$diagnostics,
			$resolver,
			new ReportRenderer( new DefinitionListRenderer( $diagnostics ) ),
			new AdminNotices(),
			AdminUxFactory::header(),
			AdminUxFactory::quick_actions(),
			AdminUxFactory::actions(),
			AdminUxFactory::components(),
			new SimulationState( new SimulationCookie(), new SimulationAuthorization() ),
			AdminUxFactory::operational_status()
		);

		ob_start();
		$page->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="action" value="universal_geo_refresh_providers"', $html );
		$this->assertStringContainsString( 'Refresh Providers', $html );
	}

	public function test_probe_fresh_flag_requires_prg_message_and_counts(): void {
		$_GET = array(
			'universal_geo_probe_fresh' => '1',
		);
		$this->assertNull( AdminProbeFreshFlag::summary() );

		$_GET = array(
			'universal_geo_probe_fresh' => '1',
			'universal_geo_msg'         => 'providers_refreshed',
			'universal_geo_probe_ok'    => '2',
			'universal_geo_probe_total' => '5',
		);
		$this->assertSame(
			array(
				'ok_count' => 2,
				'total'    => 5,
			),
			AdminProbeFreshFlag::summary()
		);
		unset( $_GET );
	}

	public function test_refresh_handler_runs_exactly_one_probe(): void {
		$provider = new TrackingGeoProvider( 'a', true, new GeoCandidate( 'SE', null ) );
		$resolver = new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) ),
			array( $provider ),
			new GeoCache( false, 900, 'sig' )
		);
		$page     = new OverviewPage(
			$this->diagnostics(),
			$resolver,
			new ReportRenderer( new DefinitionListRenderer( $this->diagnostics() ) ),
			new AdminNotices(),
			AdminUxFactory::header(),
			AdminUxFactory::quick_actions(),
			AdminUxFactory::actions(),
			AdminUxFactory::components(),
			new SimulationState( new SimulationCookie(), new SimulationAuthorization() ),
			AdminUxFactory::operational_status()
		);

		$_POST = array(
			'_wpnonce' => 'test',
			'action'   => 'universal_geo_refresh_providers',
		);

		try {
			$page->handle_refresh_providers();
			$this->fail( 'Expected redirect from refresh handler.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'redirect', $exception->getMessage() );
		}

		$this->assertSame( 1, $provider->resolve_calls );
		unset( $_POST );
	}

	public function test_admin_navigation_order_places_settings_second(): void {
		$slugs = array_map(
			static fn( array $item ): string => $item['slug'],
			AdminPageRegistry::navigation_items()
		);

		$this->assertSame(
			array(
				AdminPageSlugs::OVERVIEW,
				AdminPageSlugs::SETTINGS,
				AdminPageSlugs::DETECTION,
				AdminPageSlugs::PROVIDERS,
				AdminPageSlugs::TRUSTED_PROXIES,
				AdminPageSlugs::DIAGNOSTICS,
			),
			$slugs
		);
	}

	public function test_overview_renders_shared_navigation_and_quick_actions(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		\UniversalGeo\Plugin::instance()->init();

		$diagnostics = $this->diagnostics();
		$resolver    = new ContextResolver(
			new ClientIpResolver( ServerRequestFactory::make(), new TrustedProxies( array(), false ) ),
			array(),
			new GeoCache( false, 900, 'sig' )
		);
		$page        = new OverviewPage(
			$diagnostics,
			$resolver,
			new ReportRenderer( new DefinitionListRenderer( $diagnostics ) ),
			new AdminNotices(),
			AdminUxFactory::header(),
			AdminUxFactory::quick_actions(),
			AdminUxFactory::actions(),
			AdminUxFactory::components(),
			new SimulationState( new SimulationCookie(), new SimulationAuthorization() ),
			AdminUxFactory::operational_status()
		);

		ob_start();
		$page->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'ugc-shell-nav', $html );
		$this->assertStringContainsString( 'ugc-shell-nav__item--active', $html );
		$this->assertStringContainsString( 'Quick Actions', $html );
		$this->assertStringContainsString( 'ugc-ui-statistics-grid', $html );
		$this->assertStringContainsString( 'Open Detection & Testing', $html );
		$this->assertStringContainsString( AdminPageRegistry::description( AdminPageSlugs::OVERVIEW ), $html );
	}

	public function test_settings_page_renders_navigation_with_active_tab(): void {
		ob_start();
		$this->settings_page()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'ugc-shell-nav__item--active', $html );
		$this->assertStringContainsString( 'page=universal-geo-context-settings', $html );
		$this->assertStringContainsString( 'data-ugc-sticky-root="settings"', $html );
		$this->assertStringContainsString( AdminPageRegistry::description( AdminPageSlugs::SETTINGS ), $html );
	}
}
