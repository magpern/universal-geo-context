<?php
/**
 * Integration tests for country simulation (M8).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration\Simulation;

use UniversalGeo\Admin\AdminNotices;
use UniversalGeo\Tests\Support\AdminUxFactory;
use UniversalGeo\Admin\AdminPageSlugs;
use UniversalGeo\Admin\DefinitionListRenderer;
use UniversalGeo\Admin\DetectionInspectorRenderer;
use UniversalGeo\Admin\DetectionPage;
use UniversalGeo\Admin\ReportRenderer;
use UniversalGeo\Admin\SimulationAdminBar;
use UniversalGeo\Admin\TimelineRenderer;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Explanation\DetectionInspectorService;
use UniversalGeo\Explanation\ExplanationFormatter;
use UniversalGeo\Explanation\ProviderExplanationBuilder;
use UniversalGeo\Explanation\ResolutionTimelineBuilder;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Simulation\CountryCatalog;
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationController;
use UniversalGeo\Simulation\SimulationCookie;
use UniversalGeo\Simulation\SimulationState;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Settings;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use UniversalGeo\MaxMind\UpdateScheduler;
use WP_UnitTestCase;

/**
 * POST handlers, page rendering, and admin-bar wiring for simulation.
 */
final class SimulationIntegrationTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		update_option( Settings::OPTION_NAME, Settings::defaults() );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		unset( $_COOKIE[ SimulationCookie::NAME ] );
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		unset( $_POST, $_REQUEST, $_GET['tab'] );
		unset( $_COOKIE[ SimulationCookie::NAME ] );
		parent::tearDown();
	}

	private function cookie(): SimulationCookie {
		return new SimulationCookie();
	}

	private function state( ?SimulationCookie $cookie = null ): SimulationState {
		return new SimulationState( $cookie ?? $this->cookie(), new SimulationAuthorization() );
	}

	private function controller( ?SimulationCookie $cookie = null ): SimulationController {
		$cookie = $cookie ?? $this->cookie();

		return new SimulationController( $cookie, $this->state( $cookie ), new AdminNotices() );
	}

	private function detection_page( ?SimulationCookie $cookie = null ): DetectionPage {
		$request     = ServerRequest::capture( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$trusted     = new TrustedProxies( array(), false );
		$resolver    = new ContextResolver(
			new ClientIpResolver( $request, $trusted ),
			array(),
			new GeoCache( false, 900, 'sig' )
		);
		$settings    = get_option( Settings::OPTION_NAME, Settings::defaults() );
		$diagnostics = new DiagnosticsService(
			$resolver,
			new ClientIpResolver( $request, $trusted ),
			$request,
			$trusted,
			is_array( $settings ) ? $settings : Settings::defaults(),
			new ProviderHealthStore(),
			new MaxMindProvider( '' ),
			new CircuitBreaker(),
			'none',
			new DatabaseManager( sys_get_temp_dir(), '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ),
			'none'
		, new GeoCache( false, 900, 'sig' ), new UpdateScheduler( new DatabaseManager( sys_get_temp_dir() . '/ugeo-m12-unused', '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() ) ), new SimulationState( new SimulationCookie(), new SimulationAuthorization() ));
		$inspector   = new DetectionInspectorService(
			$resolver,
			new ClientIpResolver( $request, $trusted ),
			new GeoCache( false, 900, 'sig' ),
			$diagnostics,
			$this->state( $cookie ?? $this->cookie() ),
			new ProviderExplanationBuilder( $resolver ),
			new ResolutionTimelineBuilder()
		);
		$definition  = new DefinitionListRenderer( $diagnostics );
		$renderer    = new DetectionInspectorRenderer(
			new ReportRenderer( $definition ),
			new ExplanationFormatter(),
			$diagnostics,
			AdminUxFactory::components(),
			new TimelineRenderer( AdminUxFactory::components(), new ExplanationFormatter() )
		);

		$cookie = $cookie ?? $this->cookie();

		return new DetectionPage(
			$resolver,
			$this->state( $cookie ),
			new CountryCatalog(),
			$this->controller( $cookie ),
			$inspector,
			$renderer,
			AdminUxFactory::header(),
			AdminUxFactory::actions(),
			AdminUxFactory::components(),
			new ReportRenderer( $definition )
		);
	}

	/**
	 * Traps wp_safe_redirect and returns the target URL.
	 *
	 * @param callable $action Handler to invoke.
	 *
	 * @return string Redirect location.
	 */
	private function trap_redirect( callable $action ): string {
		$location = '';

		add_filter(
			'wp_redirect',
			static function ( $url ) use ( &$location ) {
				$location = (string) $url;
				throw new \RuntimeException( 'redirect-trap' );
			}
		);

		try {
			$action();
			$this->fail( 'Expected the redirect trap to interrupt execution.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect-trap', $e->getMessage() );
		}

		remove_all_filters( 'wp_redirect' );

		return $location;
	}

	public function test_start_simulation_writes_cookie_and_redirects(): void {
		$nonce                = wp_create_nonce( 'universal_geo_simulation' );
		$_POST                = array(
			'simulation_country' => 'DE',
			'_wpnonce'           => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$location = $this->trap_redirect(
			function (): void {
				$this->controller()->handle_start();
			}
		);

		$this->assertSame( 'DE', $this->cookie()->read() );
		$this->assertStringContainsString( 'page=' . AdminPageSlugs::DETECTION, $location );
		$this->assertStringContainsString( 'tab=simulation', $location );
		$this->assertStringContainsString( 'universal_geo_msg=simulation_started', $location );
	}

	public function test_change_simulation_updates_cookie(): void {
		$this->cookie()->write( 'DE' );

		$nonce                = wp_create_nonce( 'universal_geo_simulation' );
		$_POST                = array(
			'simulation_country' => 'FR',
			'_wpnonce'           => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$location = $this->trap_redirect(
			function (): void {
				$this->controller()->handle_change();
			}
		);

		$this->assertSame( 'FR', $this->cookie()->read() );
		$this->assertStringContainsString( 'universal_geo_msg=simulation_changed', $location );
	}

	public function test_stop_simulation_clears_cookie(): void {
		$this->cookie()->write( 'DE' );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'universal_geo_simulation_stop' );

		$location = $this->trap_redirect(
			function (): void {
				$this->controller()->handle_stop();
			}
		);

		$this->assertNull( $this->cookie()->read() );
		$this->assertStringContainsString( 'universal_geo_msg=simulation_stopped', $location );
	}

	public function test_invalid_country_is_rejected(): void {
		$nonce                = wp_create_nonce( 'universal_geo_simulation' );
		$_POST                = array(
			'simulation_country' => 'ZZ',
			'_wpnonce'           => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$location = $this->trap_redirect(
			function (): void {
				$this->controller()->handle_start();
			}
		);

		$this->assertNull( $this->cookie()->read() );
		$this->assertStringContainsString( 'universal_geo_msg=simulation_invalid_country', $location );
	}

	public function test_start_requires_manage_options(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$nonce                = wp_create_nonce( 'universal_geo_simulation' );
		$_POST                = array(
			'simulation_country' => 'DE',
			'_wpnonce'           => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$this->expectException( \WPDieException::class );

		$this->controller()->handle_start();
	}

	public function test_simulation_tab_renders_start_form_when_inactive(): void {
		$_GET['tab'] = 'simulation';
		\UniversalGeo\Plugin::instance()->init();

		ob_start();
		$this->detection_page()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Simulation', $html );
		$this->assertStringContainsString( 'name="simulation_country"', $html );
		$this->assertStringContainsString( 'universal_geo_simulation_start', $html );
		$this->assertStringContainsString( 'Detection', $html );
	}

	public function test_simulation_tab_renders_change_and_stop_when_active(): void {
		$this->cookie()->write( 'DE' );
		$_GET['tab'] = 'simulation';
		\UniversalGeo\Plugin::instance()->init();

		ob_start();
		$this->detection_page()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'universal_geo_simulation_change', $html );
		$this->assertStringContainsString( 'universal_geo_simulation_stop', $html );
		$this->assertStringContainsString( 'simulation', $html );
	}

	public function test_admin_bar_indicator_registers_when_simulation_active(): void {
		$this->cookie()->write( 'DE' );

		require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';

		$bar = new \WP_Admin_Bar();
		( new SimulationAdminBar( $this->state(), new CountryCatalog() ) )->register();

		do_action( 'admin_bar_menu', $bar ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own hook, fired to simulate admin-bar rendering.

		$node = $bar->get_node( 'universal-geo-simulation' );

		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'Geo Simulation:', (string) $node->title );
		$this->assertStringContainsString( 'Germany', (string) $node->title );
		$this->assertStringContainsString( 'tab=simulation', (string) $node->href );
	}

	public function test_admin_bar_indicator_hidden_when_inactive(): void {
		require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';

		$bar = new \WP_Admin_Bar();
		( new SimulationAdminBar( $this->state(), new CountryCatalog() ) )->register();

		do_action( 'admin_bar_menu', $bar ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own hook, fired to simulate admin-bar rendering.

		$this->assertNull( $bar->get_node( 'universal-geo-simulation' ) );
	}
}
