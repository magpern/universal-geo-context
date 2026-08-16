<?php
/**
 * Integration tests for the M14 cache-safe visitor context REST route.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration\Rest;

use ReflectionClass;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Plugin;
use UniversalGeo\Settings;
use UniversalGeo\Simulation\SimulationCookie;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proves the REST v1 contract, its independence from Plugin as a service
 * locator, and — the authoritative empirical answer to the M14 planning
 * question — the interaction between WordPress's real REST cookie
 * authentication, X-WP-Nonce, and Plugin::context()'s per-request
 * memoization (see docs/PLAN-v1.9.0.md "Section 0" and
 * docs/adr/0012-cache-safe-visitor-context.md).
 *
 * Two WordPress-core mechanics this class deliberately exercises for real,
 * rather than assuming from documentation:
 *
 * 1. Authentication is simulated via a genuine wp_generate_auth_cookie()
 *    value placed in $_COOKIE, not wp_set_current_user() — the latter
 *    bypasses wp_validate_auth_cookie() entirely (it never fires the
 *    auth_cookie_valid action core's own rest_cookie_collect_status()
 *    listens for), so it cannot exercise the real REST nonce-checking code
 *    path (rest_cookie_check_errors(), wp-includes/rest-api.php).
 * 2. Requests are served via WP_REST_Server::check_authentication() +
 *    dispatch(), mirroring serve_request()'s real order exactly — a bare
 *    dispatch() call alone NEVER invokes check_authentication(), so it
 *    would silently skip WordPress's own REST nonce logic entirely and
 *    prove nothing about it. This was confirmed directly against
 *    tests/tmp/wp-includes/rest-api/class-wp-rest-server.php during
 *    implementation.
 *
 * @internal
 */
final class ContextControllerTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		// A prior test file's own Plugin::instance()->init() call, if it did
		// not reset the singleton in its own tearDown, otherwise leaves
		// Plugin::$instance already booted here — WP_UnitTestCase restores
		// hooks to baseline between tests (undoing any add_action(
		// 'rest_api_init', ...) an earlier test's init() call made), but the
		// plain-PHP-static Plugin singleton is not a hook and survives that
		// restoration. boot() would then be a silent no-op (already booted),
		// and ContextController would never be (re-)registered against the
		// now-hookless rest_api_init. Likewise, a prior file's own
		// rest_get_server() call (if any ran first) would leave
		// $wp_rest_server bound to a server that already fired rest_api_init
		// before this class's boot() ran — it never fires twice for one
		// server instance. Reset both here too, not only in tearDown(), so
		// this class is self-contained regardless of execution order
		// relative to other test files.
		$this->reset_plugin_singleton();
		$this->reset_wp_rest_globals();
	}

	protected function tearDown(): void {
		unset( $_COOKIE[ SimulationCookie::NAME ], $_COOKIE[ LOGGED_IN_COOKIE ], $_SERVER['HTTP_X_WP_NONCE'] );
		delete_option( ProviderHealthStore::OPTION_NAME );
		$this->reset_plugin_singleton();
		$this->reset_wp_rest_globals();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function reset_plugin_singleton(): void {
		$reflection = new ReflectionClass( Plugin::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * Resets the globals WordPress core's REST cookie-authentication
	 * mechanism uses, plus the current-user resolution cache, so each test
	 * gets both a fresh $wp_rest_server (bound to the current test's fresh
	 * Plugin instance, not a prior test's) and a fresh, unresolved current
	 * user (so a newly-set auth cookie is genuinely re-validated rather than
	 * reusing a cached WP_User object from an earlier test).
	 *
	 * @return void
	 */
	private function reset_wp_rest_globals(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core's own REST/user-resolution globals, not this plugin's; deliberately reset between tests, never read/written elsewhere in src/.
		$GLOBALS['wp_rest_server']      = null;
		$GLOBALS['wp_rest_auth_cookie'] = null;
		// phpcs:enable
		$GLOBALS['current_user'] = null;
	}

	/**
	 * Boots the real plugin (identical to a normal request's plugins_loaded
	 * path), which registers ContextController via the same unconditional
	 * block SimulationContextFilter/SimulationAdminBar use.
	 *
	 * @return void
	 */
	private function boot(): void {
		Plugin::instance()->init();
	}

	/**
	 * Places a genuine, core-generated logged-in auth cookie for $user_id,
	 * then clears the current-user resolution cache so the next call to
	 * is_user_logged_in()/wp_get_current_user() re-validates it for real —
	 * running wp_validate_auth_cookie() and firing the auth_cookie_valid
	 * action core's own REST bootstrap listens for.
	 *
	 * @param int $user_id User to authenticate as.
	 *
	 * @return void
	 */
	private function authenticate_via_real_cookie( int $user_id ): void {
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + DAY_IN_SECONDS, 'logged_in' );
		$GLOBALS['current_user']     = null;
	}

	/**
	 * Serves a request through the SAME two-step flow a real HTTP request
	 * goes through in WP_REST_Server::serve_request(): check_authentication()
	 * first, then dispatch() only if that did not produce a WP_Error.
	 *
	 * @param WP_REST_Request $request The request to serve.
	 *
	 * @return \WP_REST_Response
	 */
	private function serve( WP_REST_Request $request ): \WP_REST_Response {
		$server = rest_get_server();
		$result = $server->check_authentication();

		if ( ! is_wp_error( $result ) ) {
			$result = $server->dispatch( $request );
		}

		$result = rest_ensure_response( $result );

		if ( is_wp_error( $result ) ) {
			$result = $server->error_to_response( $result );
		}

		return $result;
	}

	private function dispatch(): \WP_REST_Response {
		return $this->serve( new WP_REST_Request( 'GET', '/universal-geo-context/v1/context' ) );
	}

	/**
	 * Check_authentication() -> rest_cookie_check_errors() reads the nonce
	 * from $_SERVER['HTTP_X_WP_NONCE']/$_REQUEST['_wpnonce'] directly, NOT
	 * from the WP_REST_Request object (check_authentication() takes no
	 * request parameter at all) — this is exactly how a real X-WP-Nonce
	 * HTTP header arrives by the time PHP sees it, so setting $_SERVER here
	 * is the faithful equivalent, not a test-only shortcut.
	 *
	 * @param int $user_id The user the nonce is issued for.
	 *
	 * @return \WP_REST_Response
	 */
	private function dispatch_with_nonce( int $user_id ): \WP_REST_Response {
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest', $user_id );

		try {
			return $this->serve( new WP_REST_Request( 'GET', '/universal-geo-context/v1/context' ) );
		} finally {
			unset( $_SERVER['HTTP_X_WP_NONCE'] );
		}
	}

	private function activate_simulation( string $country ): void {
		( new SimulationCookie() )->write( $country );
	}

	public function test_route_is_registered_get_only(): void {
		$this->boot();

		$routes = rest_get_server()->get_routes( 'universal-geo-context/v1' );

		$this->assertArrayHasKey( '/universal-geo-context/v1/context', $routes );

		$route_args = $routes['/universal-geo-context/v1/context'][0];
		$this->assertTrue( $route_args['methods']['GET'] ?? false );
		$this->assertArrayNotHasKey( 'POST', $route_args['methods'] ?? array() );
	}

	public function test_anonymous_request_returns_exact_two_key_contract(): void {
		$this->boot();

		$response = $this->dispatch();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'country_code', 'region_code' ), array_keys( $data ) );
		$this->assertNull( $data['country_code'] );
		$this->assertNull( $data['region_code'] );
	}

	public function test_response_never_contains_prohibited_fields(): void {
		$this->boot();

		$data = $this->dispatch()->get_data();

		foreach ( array( 'source', 'is_cached', 'confidence', 'schema_version', 'ip', 'proxy', 'credentials' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $data );
		}
	}

	public function test_response_carries_no_store(): void {
		$this->boot();

		$response = $this->dispatch();
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
	}

	public function test_route_registers_outside_admin_cli_gates(): void {
		// Neither is_admin() nor WP_CLI is true in this test process — if
		// ContextController were wrongly gated behind should_register_admin()
		// or should_register_cli(), the route would be missing here.
		$this->assertFalse( is_admin() );
		$this->assertFalse( defined( 'WP_CLI' ) && WP_CLI );

		$this->boot();

		$routes = rest_get_server()->get_routes( 'universal-geo-context/v1' );
		$this->assertArrayHasKey( '/universal-geo-context/v1/context', $routes );
	}

	/**
	 * Scenario A: authenticated admin (real cookie), active simulation,
	 * valid X-WP-Nonce.
	 */
	public function test_admin_with_valid_nonce_and_active_simulation_sees_simulated_country(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->authenticate_via_real_cookie( $admin_id );
		$this->activate_simulation( 'NO' );

		$this->boot();

		$data = $this->dispatch_with_nonce( $admin_id )->get_data();

		$this->assertSame( 'NO', $data['country_code'] );
		$this->assertNull( $data['region_code'] );
	}

	/**
	 * Scenario B: same authenticated/admin REST request (real cookie), but
	 * no nonce sent. WordPress core's rest_cookie_check_errors()
	 * (wp-includes/rest-api.php) anonymizes the request (wp_set_current_user(0))
	 * when a real logged-in cookie was used to resolve the current user and
	 * no nonce is present at all — so SimulationAuthorization's
	 * is_user_logged_in() check correctly sees an anonymous request, and
	 * simulation must NOT apply, PROVIDED Plugin::context() is not called
	 * any earlier in the request than this REST dispatch itself (see
	 * test_early_context_access_can_leak_simulation_without_nonce() below
	 * for the case where it is).
	 */
	public function test_admin_without_nonce_does_not_see_simulated_country(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->authenticate_via_real_cookie( $admin_id );
		$this->activate_simulation( 'NO' );

		$this->boot();

		$data = $this->dispatch()->get_data();

		$this->assertNull( $data['country_code'] );
	}

	/**
	 * Scenario C: anonymous visitor, simulation cookie somehow present
	 * (adversarial/edge case — e.g. a shared browser profile). Real context
	 * only; SimulationAuthorization re-checks capability every call.
	 */
	public function test_anonymous_request_with_simulation_cookie_present_sees_real_context(): void {
		$this->activate_simulation( 'NO' );
		// Deliberately no auth cookie: anonymous.

		$this->boot();

		$data = $this->dispatch()->get_data();

		$this->assertNull( $data['country_code'] );
	}

	/**
	 * Requirement A: Plugin::context()'s own PHP-consumer-facing behavior —
	 * request-level memoization and simulation-awareness — is byte-for-byte
	 * unchanged by the effective_context() correction. Both methods now
	 * share resolve_and_filter_context() internally, so this also guards
	 * against that extraction accidentally diverging the two algorithms.
	 */
	public function test_plugin_context_memoization_is_unchanged(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->authenticate_via_real_cookie( $admin_id );
		$this->activate_simulation( 'NO' );
		$this->boot();

		$first  = Plugin::instance()->context();
		$second = Plugin::instance()->context();

		$this->assertSame( 'NO', $first->country_code, 'Plugin::context() must still reflect an authorized, active simulation exactly as before M14.' );
		$this->assertSame( $first, $second, 'Plugin::context() must still return the IDENTICAL memoized instance on a second call in the same request — memoization itself is untouched.' );
	}

	/**
	 * Regression E (Section 0 correction) — the authoritative, empirical
	 * proof the leak is fixed.
	 *
	 * WordPress's REST nonce downgrade (wp_set_current_user(0) when no
	 * nonce is present) lives entirely inside rest_cookie_check_errors(),
	 * which itself only ever runs as a side effect of
	 * WP_REST_Server::check_authentication(). A raw call to
	 * is_user_logged_in()/Plugin::context() OUTSIDE of that flow — e.g. a
	 * third-party plugin calling universal_geo_get_context() earlier in the
	 * same request, before REST dispatch reaches check_authentication() —
	 * resolves the CURRENT USER correctly (a real, valid auth cookie really
	 * does mean they are logged in) but is NEVER subject to the REST-specific
	 * nonce downgrade, because that downgrade is not a property of
	 * is_user_logged_in() itself. Plugin::context()'s own memoization used
	 * to make that early, un-downgraded result "stick" for every later
	 * caller in the same request/process — including this REST route.
	 *
	 * ContextController now depends on Plugin::effective_context(), which
	 * never reads or writes that memo, so the early call above and this
	 * REST dispatch are independently evaluated — proven here.
	 */
	public function test_early_context_access_no_nonce_returns_real_context_not_leaked_simulation(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->authenticate_via_real_cookie( $admin_id );
		$this->activate_simulation( 'NO' );
		$this->boot();

		// Stands in for a third-party plugin calling universal_geo_get_context()
		// (or any code touching is_user_logged_in()) earlier in the same
		// request, before this route's own check_authentication() has run.
		$early = Plugin::instance()->context();
		$this->assertSame( 'NO', $early->country_code, 'Outside of REST\'s own nonce gate, a genuinely cookie-authenticated admin with active simulation legitimately sees the simulated country via Plugin::context() — this call is correct in isolation and must remain correct (requirement A).' );

		// The SAME Plugin instance (matching production's one-instance-per-
		// request reality) now serves a REST request with NO nonce. Before
		// the fix, Plugin::context()'s memo from the early call above leaked
		// through. effective_context() must not be affected by it.
		$data = $this->dispatch()->get_data();
		$this->assertNull(
			$data['country_code'],
			'An earlier Plugin::context() call in the same request must NOT be reflected by this REST route when the REST request itself has no valid nonce — effective_context() re-evaluates independently of Plugin::context()\'s memo.'
		);
	}

	/**
	 * Regression F: the mirror-image case — an early Plugin::context() call
	 * must not prevent effective_context() from correctly reflecting
	 * simulation either, when this specific REST dispatch DOES carry a
	 * valid nonce. Proves effective_context() is independently authorization-
	 * sensitive in both directions, not merely hardcoded to ignore the
	 * early call.
	 */
	public function test_early_context_access_with_valid_nonce_still_sees_simulated_country(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->authenticate_via_real_cookie( $admin_id );
		$this->activate_simulation( 'NO' );
		$this->boot();

		$early = Plugin::instance()->context();
		$this->assertSame( 'NO', $early->country_code );

		$data = $this->dispatch_with_nonce( $admin_id )->get_data();
		$this->assertSame(
			'NO',
			$data['country_code'],
			'A REST dispatch with a valid nonce must still correctly reflect active simulation, even after an earlier Plugin::context() call in the same request.'
		);
		$this->assertNull( $data['region_code'] );
	}

	/**
	 * Requirement G: effective_context() must not repeat expensive provider
	 * resolution. Enables the remote provider with a counted pre_http_request
	 * filter (the same technique DiagnosticsProbeExactOnceTest uses for an
	 * identical class of claim) and calls effective_context() three times —
	 * the outbound call count must be exactly one, proving
	 * ContextResolver::resolve()'s own memo is what effective_context()
	 * relies on, not a new cache of its own.
	 */
	public function test_effective_context_does_not_repeat_provider_resolution(): void {
		Plugin::activate();

		Settings::save(
			array(
				'remote_enabled'               => true,
				'remote_transfer_acknowledged' => true,
				'remote_account_id'            => 'test-account',
				'remote_license_key'           => 'test-license',
			)
		);
		GeoCache::bump_epoch();

		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"country":{"iso_code":"DE"}}',
				);
			},
			10,
			3
		);

		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		$this->boot();

		$first  = Plugin::instance()->effective_context();
		$second = Plugin::instance()->effective_context();
		$third  = Plugin::instance()->effective_context();

		$this->assertSame( 'DE', $first->country_code );
		$this->assertSame( 'DE', $second->country_code );
		$this->assertSame( 'DE', $third->country_code );
		$this->assertSame( 1, $call_count, 'effective_context() must reuse ContextResolver\'s own memo — three calls must not make three outbound provider requests.' );

		remove_all_filters( 'pre_http_request' );
		unset( $_SERVER['REMOTE_ADDR'] );
	}
}
