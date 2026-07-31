<?php
/**
 * Universal Geo Context plugin composition root.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo;

use UniversalGeo\Admin\AdminNotices;
use UniversalGeo\Admin\DetectionPage;
use UniversalGeo\Admin\DiagnosticsPage;
use UniversalGeo\Admin\FirstRunNotice;
use UniversalGeo\Admin\Menu;
use UniversalGeo\Admin\OverviewPage;
use UniversalGeo\Admin\ProvidersPage;
use UniversalGeo\Admin\ReportRenderer;
use UniversalGeo\Admin\RowLinks;
use UniversalGeo\Admin\SimulationAdminBar;
use UniversalGeo\Admin\SettingsPage;
use UniversalGeo\Admin\TrustedProxiesPage;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Cli\Command as CliCommand;
use UniversalGeo\Cli\DatabaseCommand;
use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Privacy\PrivacyPolicyContent;
use UniversalGeo\Providers\CloudflareHeaderProvider;
use UniversalGeo\Providers\DefaultCountryProvider;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Providers\Remote\ReferenceRemoteProvider;
use UniversalGeo\Providers\Remote\WordPressHttpTransport;
use UniversalGeo\Providers\WooCommerceProvider;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Resolver\GeoValidator;
use UniversalGeo\Settings;
use UniversalGeo\Simulation\CountryCatalog;
use UniversalGeo\Simulation\SimulationAuthorization;
use UniversalGeo\Simulation\SimulationContextFilter;
use UniversalGeo\Simulation\SimulationController;
use UniversalGeo\Simulation\SimulationCookie;
use UniversalGeo\Simulation\SimulationState;

/**
 * Plugin bootstrap and composition root.
 *
 * The only composition root in the entire plugin, and the sole entry
 * point the main bootstrap file calls into for both the activation
 * lifecycle (activate()) and the request lifecycle (instance()->init()).
 *
 * **Composition-root invariant (permanent, M2 architecture report §3).**
 * `Plugin` is the *exclusive* composition root for internal services: it
 * constructs and connects the object graph, registers WordPress lifecycle
 * hooks, exposes the public context() boundary, and performs simple
 * lifecycle coordination — and contains no business, security, diagnostics,
 * administration, provider, cache, or settings policy. No internal service
 * may instantiate a peer internal service; every dependency arrives by
 * constructor injection from here. New behavior needing more than wiring
 * belongs in an internal service, constructor-injected from this class —
 * never implemented inline in `Plugin` itself. Enforced structurally by
 * `tests/unit/Guards/CompositionRootTest.php`.
 *
 * **Object-lifetime invariant.** Every request-scoped internal service
 * (`ServerRequest`, `TrustedProxies`, `ClientIpResolver`, the provider
 * instances, `GeoCache`, `ContextResolver`, and — once M2 sub-step 2E adds
 * them — `DiagnosticsService`/`AdminScreen` on admin requests only) is
 * constructed at most once, here in init(), and reused for the rest of the
 * request. Construction is cheap (no I/O); what stays lazy is *resolution*:
 * `ContextResolver::resolve()` is never called here, only on the first
 * genuine call to context() ("zero cost for requests that never ask").
 * Internal lazy/memoized work inside an already-constructed service
 * (`ClientIpResolver::resolve()`/`cloudflare_header_trusted()`,
 * `ContextResolver::resolve()`, `GeoCache`'s own memoization) is unaffected
 * by this rule — only construction is once-per-request, not internal work.
 *
 * context() is also where the two hooks Revision 3 §14 assigns to the
 * resolved-context boundary fire: 'universal_geo_context' (filter) and
 * 'universal_geo_context_resolved' (action). They live here rather than
 * inside ContextResolver so that ContextResolver itself stays exactly what
 * it was built and tested as — framework-independent, calling no WordPress
 * function at all. This method fires them at most once per request
 * (mirroring ContextResolver's own "non-memoized resolution" wording): a
 * dedicated flag remembers the filtered result independently of
 * ContextResolver's own internal memo, since a filter can hand back a
 * *different* object than ContextResolver produced.
 *
 * @final
 */
final class Plugin {
	/**
	 * Plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether the plugin has been initialized.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * The resolver graph, built once by init(). Null only before boot.
	 *
	 * @var ContextResolver|null
	 */
	private ?ContextResolver $resolver = null;

	/**
	 * The filtered, hook-fired context for the current request. Distinct
	 * from ContextResolver's own memo: a filter may return a different
	 * instance than ContextResolver produced.
	 *
	 * @var VisitorContext|null
	 */
	private ?VisitorContext $public_context = null;

	/**
	 * Whether context() has already run the filter/action for this request.
	 *
	 * @var bool
	 */
	private bool $context_resolved = false;

	/**
	 * Get the plugin instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Runs on plugin activation.
	 *
	 * Registered via register_activation_hook() in the main bootstrap
	 * file. Ensures the settings option exists and is normalized.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Settings::install();
	}

	/**
	 * Initialize the plugin.
	 *
	 * Idempotent; safe to call multiple times. Builds the full M2 object
	 * graph; does not resolve anything. Admin services (`DiagnosticsService`,
	 * `AdminScreen`) are additionally constructed and registered under the
	 * Revision 3 §4.5 admin condition — never on the frontend, cron, AJAX,
	 * or WP-CLI request path. `DiagnosticsService` is instead paired with
	 * `Cli\Command` (M5) on the symmetric WP-CLI-only path — the two
	 * conditions are mutually exclusive within a single request, so
	 * `DiagnosticsService` is still constructed at most once.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$graph          = $this->build_graph();
		$this->resolver = $graph['resolver'];

		$simulation_cookie = new SimulationCookie();
		$simulation_state  = new SimulationState( $simulation_cookie, new SimulationAuthorization() );
		$country_catalog   = new CountryCatalog();

		( new SimulationContextFilter( $simulation_state ) )->register();
		( new SimulationAdminBar( $simulation_state, $country_catalog ) )->register();

		// Unconditional (M6): cron requests are neither is_admin() nor
		// WP-CLI, so cron registration cannot live inside either gated
		// branch below.
		$graph['update_scheduler']->register(
			$graph['settings']['maxmind_managed_auto_update_enabled'],
			$graph['settings']['maxmind_managed_auto_update_frequency']
		);

		$register_admin = $this->should_register_admin();
		$register_cli   = $this->should_register_cli();

		if ( $register_admin || $register_cli ) {
			$diagnostics = new DiagnosticsService(
				$graph['resolver'],
				$graph['client_ip_resolver'],
				$graph['server_request'],
				$graph['trusted_proxies'],
				$graph['settings'],
				$graph['provider_health_store'],
				$graph['maxmind_provider'],
				$graph['circuit_breaker'],
				$graph['remote_credential_source'],
				$graph['database_manager'],
				$graph['maxmind_path_source']
			);
		}

		if ( $register_admin ) {
			$diagnostics->register();

			$notices         = new AdminNotices();
			$report_renderer = new ReportRenderer( $diagnostics );

			$overview_page = new OverviewPage(
				$diagnostics,
				$graph['resolver'],
				$report_renderer,
				$notices
			);

			$detection_page = new DetectionPage(
				$graph['resolver'],
				$simulation_state,
				$country_catalog,
				new SimulationController( $simulation_cookie, $simulation_state, $notices )
			);
			$providers_page = new ProvidersPage();

			$trusted_proxies_page = new TrustedProxiesPage(
				$diagnostics,
				$graph['server_request'],
				$report_renderer,
				$notices
			);

			$diagnostics_page = new DiagnosticsPage( $diagnostics, $report_renderer );

			$settings_page = new SettingsPage(
				$graph['update_scheduler'],
				$graph['database_manager'],
				$notices
			);

			( new Menu(
				$overview_page,
				$detection_page,
				$providers_page,
				$trusted_proxies_page,
				$diagnostics_page,
				$settings_page
			) )->register();

			$notices->register();

			( new FirstRunNotice( $diagnostics ) )->register();
			( new RowLinks() )->register();

			$privacy_content = new PrivacyPolicyContent( $graph['settings']['remote_enabled'] );

			add_action(
				'admin_init',
				static function () use ( $privacy_content ): void {
					if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
						wp_add_privacy_policy_content(
							__( 'Universal Geo Context', 'universal-geo-context' ),
							$privacy_content->build()
						);
					}
				}
			);
		}

		if ( $register_cli ) {
			( new CliCommand( $graph['resolver'], $diagnostics ) )->register();
			( new DatabaseCommand( $graph['database_manager'] ) )->register();
		}
	}

	/**
	 * Revision 3 §4.5's exact admin registration condition: admin screens
	 * register only on a real wp-admin request, never on AJAX, cron, or
	 * WP-CLI.
	 *
	 * @return bool
	 */
	private function should_register_admin(): bool {
		return is_admin()
			&& ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			&& ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * The symmetric WP-CLI registration condition (M5) — the exact opposite
	 * leg of should_register_admin()'s own WP_CLI exclusion.
	 *
	 * @return bool
	 */
	private function should_register_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Returns the resolved visitor context for the current request.
	 *
	 * Memoized: the filter and action run at most once per request, on the
	 * first call. Every later call in the same request — including from
	 * the five convenience wrapper functions in src/api.php — returns the
	 * identical, already-filtered instance.
	 *
	 * Never throws, never fatals: called before init() (a programmer
	 * error — consumers must call at or after plugins_loaded priority 20)
	 * returns the unknown context and emits _doing_it_wrong(), exactly per
	 * Revision 3 §13.
	 *
	 * @return VisitorContext
	 */
	public function context(): VisitorContext {
		if ( null === $this->resolver ) {
			// UNIVERSAL_GEO_VERSION is a plugin-defined constant, not user
			// input; _doing_it_wrong() escapes it internally.
			_doing_it_wrong(
				'universal_geo_get_context',
				__( 'Called before Universal Geo Context has booted. Call at or after the plugins_loaded priority 20.', 'universal-geo-context' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong()'s message is never echoed as HTML (WP core's own trigger_error() path); WP core's own usage of this function passes plain __(), never esc_html__().
				UNIVERSAL_GEO_VERSION // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);

			return VisitorContext::unknown();
		}

		if ( $this->context_resolved ) {
			return $this->public_context;
		}

		$context = $this->resolver->resolve();

		/**
		 * Filters the resolved visitor context. Runs first and gets the
		 * last word on the value (Revision 3 §14); the returned value is
		 * re-validated below before use.
		 *
		 * @since 0.1.0
		 *
		 * @param VisitorContext $context The resolved context.
		 */
		$filtered = apply_filters( 'universal_geo_context', $context );

		if ( ! $this->is_valid_filtered_context( $filtered ) ) {
			_doing_it_wrong(
				'universal_geo_context',
				__( 'The universal_geo_context filter must return a VisitorContext with a valid country code.', 'universal-geo-context' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see the identical note on the first _doing_it_wrong() call above.
				UNIVERSAL_GEO_VERSION // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);

			$filtered = $context;
		}

		/**
		 * Fires after the visitor context has been resolved and filtered.
		 * Receives the already-filtered context and cannot change it
		 * (Revision 3 §14).
		 *
		 * @since 0.1.0
		 *
		 * @param VisitorContext $filtered The filtered, final context.
		 */
		do_action( 'universal_geo_context_resolved', $filtered );

		$this->public_context   = $filtered;
		$this->context_resolved = true;

		return $this->public_context;
	}

	/**
	 * Builds the full M2 object graph (M2 sub-step 2D/2E — the approved M2
	 * architecture report §5/§10). Every service listed in this class's own
	 * object-lifetime invariant is constructed exactly once, here. Returns
	 * every piece init() needs — not just the ContextResolver — so the
	 * admin-only DiagnosticsService/AdminScreen construction below can reuse
	 * the same ServerRequest, TrustedProxies, ClientIpResolver, and settings
	 * rather than rebuilding them.
	 *
	 * PROVIDER_ORDER (Revision 3 §4.4, reproduced by ContextResolver as a
	 * documentation-only constant it never reads at runtime): cloudflare,
	 * maxmind, woocommerce, remote, default — the array built here matches
	 * it exactly, as of M4's ReferenceRemoteProvider filling the 'remote'
	 * slot.
	 *
	 * @return array{resolver: ContextResolver, client_ip_resolver: ClientIpResolver, server_request: ServerRequest, provider_health_store: ProviderHealthStore, maxmind_provider: MaxMindProvider, trusted_proxies: TrustedProxies, settings: array<string, mixed>, remote_credential_source: string, remote_provider: ReferenceRemoteProvider, circuit_breaker: CircuitBreaker, database_manager: DatabaseManager, update_scheduler: UpdateScheduler, maxmind_path_source: string}
	 */
	private function build_graph(): array {
		$settings = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) );

		$server_request  = ServerRequest::capture( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$trusted_proxies = new TrustedProxies( $settings['trusted_proxies'], $settings['trust_cloudflare'] );

		$client_ip_resolver = new ClientIpResolver( $server_request, $trusted_proxies );

		$default_country    = $this->filtered_default_country( $settings['default_country'] );
		$remote_credentials = $this->resolved_maxmind_credentials( $settings );

		// Shared with ReferenceRemoteProvider (M6 decision): one transport
		// instance, the sole production caller of wp_safe_remote_get()
		// (PrivacyGuardTest rule 8), reused for both the remote lookup
		// provider and managed database downloads.
		$http_transport = new WordPressHttpTransport();

		$database_manager = new DatabaseManager(
			$this->managed_maxmind_dir(),
			$remote_credentials['account_id'],
			$remote_credentials['license_key'],
			$settings['maxmind_managed_retain_previous'],
			$http_transport,
			new ArchiveExtractor(),
			new UpdateLock()
		);

		$maxmind_path_result = $this->resolved_maxmind_db_path( $settings, $database_manager );
		$maxmind_db_path     = $maxmind_path_result['path'];
		$maxmind_path_source = $maxmind_path_result['source'];

		$maxmind_provider = new MaxMindProvider( $maxmind_db_path );
		$circuit_breaker  = new CircuitBreaker();
		$remote_provider  = new ReferenceRemoteProvider(
			$settings['remote_enabled'],
			$remote_credentials['account_id'],
			$remote_credentials['license_key'],
			$settings['remote_timeout'],
			$http_transport,
			$circuit_breaker
		);

		$update_scheduler = new UpdateScheduler( $database_manager );

		// PROVIDER_ORDER (frozen, M4): cloudflare, maxmind, woocommerce,
		// remote, default.
		$providers = $this->filtered_providers(
			array(
				new CloudflareHeaderProvider( $server_request, $client_ip_resolver ),
				$maxmind_provider,
				new WooCommerceProvider(),
				$remote_provider,
				new DefaultCountryProvider( $default_country ),
			)
		);

		$config_sig = hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'default_country'              => $default_country,
					'trusted_proxies'              => $settings['trusted_proxies'],
					'trust_cloudflare'             => $settings['trust_cloudflare'],
					'maxmind_db_path'              => $maxmind_db_path,
					'maxmind_managed_enabled'      => $settings['maxmind_managed_enabled'],
					'remote_enabled'               => $settings['remote_enabled'],
					'remote_transfer_acknowledged' => $settings['remote_transfer_acknowledged'],
					'remote_credential_source'     => $remote_credentials['source'],
				)
			)
		);

		$cache                 = new GeoCache( $settings['derived_cache_enabled'], $settings['derived_cache_ttl'], $config_sig );
		$provider_health_store = new ProviderHealthStore();

		$resolver = new ContextResolver(
			$client_ip_resolver,
			$providers,
			$cache,
			static function ( string $provider_id, string $reason ) use ( $provider_health_store ): void {
				/**
				 * Fires when a provider's resolve() throws.
				 *
				 * @since 0.2.0
				 *
				 * @param string $provider_id The failing provider's get_id().
				 * @param string $reason      The exception's class and message. Never a client IP.
				 */
				do_action( 'universal_geo_provider_failed', $provider_id, $reason );

				$provider_health_store->record( $provider_id, $reason );
			}
		);

		return array(
			'resolver'                 => $resolver,
			'client_ip_resolver'       => $client_ip_resolver,
			'server_request'           => $server_request,
			'provider_health_store'    => $provider_health_store,
			'maxmind_provider'         => $maxmind_provider,
			'trusted_proxies'          => $trusted_proxies,
			'settings'                 => $settings,
			'remote_credential_source' => $remote_credentials['source'],
			'remote_provider'          => $remote_provider,
			'circuit_breaker'          => $circuit_breaker,
			'database_manager'         => $database_manager,
			'update_scheduler'         => $update_scheduler,
			'maxmind_path_source'      => $maxmind_path_source,
		);
	}

	/**
	 * The managed GeoLite2 database directory
	 * ({uploads}/universal-geo-context/maxmind/), M6's own fixed, plugin-
	 * computed location — never admin-configurable, so unlike
	 * maxmind_db_path/the WooCommerce-derived candidate, this is not a
	 * containment-checked user input; it is a formula over wp_upload_dir()
	 * alone. Delegates to `DatabaseManager::managed_directory()` — the
	 * single formula both this class and `uninstall.php` use, so the two
	 * can never compute two different paths for the same thing.
	 *
	 * @return string
	 */
	private function managed_maxmind_dir(): string {
		return DatabaseManager::managed_directory();
	}

	/**
	 * Applies the universal_geo_default_country filter, re-sanitized to the
	 * same shape Settings::sanitize() itself enforces (uppercase, empty or
	 * exactly two ASCII letters) — real ISO 3166-1 membership is not
	 * checked here; that remains GeoValidator's job inside the resolver
	 * loop (M1's already-documented, unchanged validation boundary). A
	 * non-string or wrong-shape result is discarded and the original,
	 * pre-filter settings value is kept.
	 *
	 * @param string $default_country The sanitized settings value.
	 *
	 * @return string
	 */
	private function filtered_default_country( string $default_country ): string {
		/**
		 * Filters the fallback country used when every provider misses.
		 *
		 * @since 0.2.0
		 *
		 * @param string $default_country The configured default country ('' or an uppercase two-letter shape).
		 */
		$filtered = apply_filters( 'universal_geo_default_country', $default_country );

		if ( ! is_string( $filtered ) ) {
			return $default_country;
		}

		$filtered = strtoupper( trim( $filtered ) );

		if ( '' === $filtered ) {
			return '';
		}

		return 1 === preg_match( '~^[A-Z]{2}$~', $filtered ) ? $filtered : $default_country;
	}

	/**
	 * Resolves the single effective MaxMind database path (M3 architecture
	 * report §6 3C; precedence extended in M6 decision #3), in strict order:
	 *
	 * 1. `UNIVERSAL_GEO_MAXMIND_DB` — a wp-config.php constant. Wins
	 *    outright when defined as a non-empty string; may point outside
	 *    WP_CONTENT_DIR (the operator's own escape hatch); the filter is
	 *    never consulted in this case.
	 * 2. The sanitized `maxmind_db_path` setting, if non-empty and contained
	 *    under WP_CONTENT_DIR — an admin-typed path that fails containment
	 *    is treated as absent (M3's original behavior, unchanged: it does
	 *    NOT fall through to a lower tier).
	 * 3. **New (M6)**: `DatabaseManager::installed_path()`, when
	 *    `maxmind_managed_enabled` is true — a cheap existence/readability
	 *    check only, never a `Reader` open. Not containment-checked: this is
	 *    a fixed, plugin-computed path under `{uploads}/`, never admin-typed
	 *    input, so there is no user-controlled string to validate here (the
	 *    same reasoning `managed_maxmind_dir()`'s own docblock states).
	 * 4. The WooCommerce auto-detected candidate, contained-checked exactly
	 *    as before.
	 * 5. `universal_geo_maxmind_db_path` — a code-level filter, uncontained,
	 *    hardened exactly like filtered_default_country()/filtered_providers().
	 *    Always consulted (except when the constant already won), even when
	 *    an earlier tier produced a path — a filter can override any
	 *    resolved candidate, exactly as before M6.
	 *
	 * The result feeds MaxMindProvider's constructor, config_sig, and (the
	 * source half) DiagnosticsService.
	 *
	 * @param array<string, mixed> $settings         The sanitized settings array.
	 * @param DatabaseManager      $database_manager Supplies the managed-path candidate (tier 3).
	 *
	 * @return array{path: string, source: string} `source` is one of 'constant', 'settings', 'managed', 'woocommerce', 'filter', or 'none'.
	 */
	private function resolved_maxmind_db_path( array $settings, DatabaseManager $database_manager ): array {
		if ( defined( 'UNIVERSAL_GEO_MAXMIND_DB' ) && is_string( UNIVERSAL_GEO_MAXMIND_DB ) && '' !== UNIVERSAL_GEO_MAXMIND_DB ) {
			return array(
				'path'   => UNIVERSAL_GEO_MAXMIND_DB,
				'source' => 'constant',
			);
		}

		$path   = '';
		$source = 'none';

		if ( '' !== $settings['maxmind_db_path'] ) {
			if ( $this->is_contained_under_wp_content( $settings['maxmind_db_path'] ) ) {
				$path   = $settings['maxmind_db_path'];
				$source = 'settings';
			}
			// An admin-typed path present but failing containment stays
			// absent — the M3 behavior this milestone preserves exactly,
			// never silently falling through to a lower tier.
		} elseif ( $settings['maxmind_managed_enabled'] && '' !== $database_manager->installed_path() ) {
			$path   = $database_manager->installed_path();
			$source = 'managed';
		} else {
			$wc_path = $this->wc_auto_detected_maxmind_path();

			if ( '' !== $wc_path && $this->is_contained_under_wp_content( $wc_path ) ) {
				$path   = $wc_path;
				$source = 'woocommerce';
			}
		}

		$filtered = $this->filtered_maxmind_db_path( $path );

		if ( '' === $filtered ) {
			$source = 'none';
		} elseif ( $filtered !== $path ) {
			$source = 'filter';
		}

		return array(
			'path'   => $filtered,
			'source' => $source,
		);
	}

	/**
	 * Derives WooCommerce's own MaxMind database candidate path from its
	 * stored settings option and the uploads directory — option-derived,
	 * without depending on any WooCommerce class (WooCommerce may not even
	 * be installed). Mirrors `WC_Integration_MaxMind_Database_Service::get_database_path()`'s
	 * own, unfiltered formula: `{uploads}/woocommerce_uploads/{prefix-}GeoLite2-Country.mmdb`.
	 *
	 * @return string The candidate path, or '' when it cannot be derived.
	 */
	private function wc_auto_detected_maxmind_path(): string {
		$wc_settings = get_option( 'woocommerce_maxmind_geolocation_settings', array() );
		$prefix      = is_array( $wc_settings ) && ! empty( $wc_settings['database_prefix'] ) && is_string( $wc_settings['database_prefix'] )
			? $wc_settings['database_prefix']
			: '';

		$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : null;
		$base       = is_array( $upload_dir ) ? ( $upload_dir['basedir'] ?? null ) : null;

		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}

		$path = rtrim( $base, '/' ) . '/woocommerce_uploads/';

		if ( '' !== $prefix ) {
			$path .= $prefix . '-';
		}

		return $path . 'GeoLite2-Country.mmdb';
	}

	/**
	 * Whether $path resolves (via realpath()) to somewhere under
	 * WP_CONTENT_DIR — the containment check applied to every option-derived
	 * MaxMind path source, mirroring AdminScreen::maxmind_path_is_valid()'s
	 * own containment logic (the constant and the filter are exempt by
	 * design; only settings/WooCommerce-derived candidates pass through
	 * here).
	 *
	 * @param string $path A non-empty candidate path.
	 *
	 * @return bool
	 */
	private function is_contained_under_wp_content( string $path ): bool {
		$resolved = realpath( $path );

		if ( false === $resolved ) {
			return false;
		}

		$content_dir = defined( 'WP_CONTENT_DIR' ) ? realpath( WP_CONTENT_DIR ) : false;

		if ( false === $content_dir ) {
			return false;
		}

		return str_starts_with( $resolved, rtrim( $content_dir, '/' ) . '/' );
	}

	/**
	 * Applies the universal_geo_maxmind_db_path filter — a code-level
	 * override, uncontained, hardened exactly like filtered_default_country():
	 * a non-string result is discarded with _doing_it_wrong(), and the
	 * pre-filter path is kept. Never called when the UNIVERSAL_GEO_MAXMIND_DB
	 * constant already won (resolved_maxmind_db_path() returns before
	 * reaching this method in that case).
	 *
	 * @param string $path The path resolved from settings/WooCommerce auto-detection (possibly '').
	 *
	 * @return string
	 */
	private function filtered_maxmind_db_path( string $path ): string {
		/**
		 * Filters the effective MaxMind database path.
		 *
		 * @since 0.3.0
		 *
		 * @param string $path The resolved path ('' when none is configured or the constant wasn't set).
		 */
		$filtered = apply_filters( 'universal_geo_maxmind_db_path', $path );

		if ( ! is_string( $filtered ) ) {
			_doing_it_wrong(
				'universal_geo_maxmind_db_path',
				__( 'The universal_geo_maxmind_db_path filter must return a string.', 'universal-geo-context' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see the identical note on the first _doing_it_wrong() call above.
				UNIVERSAL_GEO_VERSION // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);

			return $path;
		}

		return $filtered;
	}

	/**
	 * Resolves the effective, shared MaxMind credential pair and its source
	 * (M4 frozen decision, generalized in M6 decision #2/#4): one canonical
	 * account id/license key, consumed by both `ReferenceRemoteProvider` and
	 * (from M6E onward) `MaxMind\DatabaseManager` — a MaxMind account has one
	 * credential pair regardless of which MaxMind product it authenticates
	 * against. The one place in the codebase this precedence is decided,
	 * checked in order:
	 *
	 * 1. `UNIVERSAL_GEO_MAXMIND_ACCOUNT_ID` / `UNIVERSAL_GEO_MAXMIND_LICENSE_KEY`
	 *    — the canonical wp-config.php constant pair. Wins outright when
	 *    *both* are defined as non-empty strings.
	 * 2. `UNIVERSAL_GEO_REMOTE_ACCOUNT_ID` / `UNIVERSAL_GEO_REMOTE_LICENSE_KEY`
	 *    — the legacy M4 constant pair, still honored as a deprecated
	 *    fallback (`docs/COMPATIBILITY.md`), when *both* are defined as
	 *    non-empty strings.
	 * 3. The sanitized `maxmind_account_id`/`maxmind_license_key` settings
	 *    pair — already migrated from any legacy `remote_account_id`/
	 *    `remote_license_key` settings values by `Settings::sanitize()`
	 *    itself, so no separate settings-level legacy fallback is needed
	 *    here.
	 * 4. None.
	 *
	 * At every level, a single configured credential without its pair is
	 * treated identically to having neither — never one constant/setting
	 * paired with a value from a different level (the frozen M4 "requires
	 * both credentials" rule, unchanged). `DiagnosticsService` receives only
	 * the resulting source scalar — never re-deriving this precedence
	 * itself, per the M4 report's frozen decision that credential source is
	 * resolved exactly once, here.
	 *
	 * @param array<string, mixed> $settings The sanitized settings array.
	 *
	 * @return array{account_id: string, license_key: string, source: string} `source` is one of 'constants', 'legacy_constants', 'settings', or 'none'.
	 */
	private function resolved_maxmind_credentials( array $settings ): array {
		if (
			defined( 'UNIVERSAL_GEO_MAXMIND_ACCOUNT_ID' ) && is_string( UNIVERSAL_GEO_MAXMIND_ACCOUNT_ID ) && '' !== UNIVERSAL_GEO_MAXMIND_ACCOUNT_ID
			&& defined( 'UNIVERSAL_GEO_MAXMIND_LICENSE_KEY' ) && is_string( UNIVERSAL_GEO_MAXMIND_LICENSE_KEY ) && '' !== UNIVERSAL_GEO_MAXMIND_LICENSE_KEY
		) {
			return array(
				'account_id'  => UNIVERSAL_GEO_MAXMIND_ACCOUNT_ID,
				'license_key' => UNIVERSAL_GEO_MAXMIND_LICENSE_KEY,
				'source'      => 'constants',
			);
		}

		if (
			defined( 'UNIVERSAL_GEO_REMOTE_ACCOUNT_ID' ) && is_string( UNIVERSAL_GEO_REMOTE_ACCOUNT_ID ) && '' !== UNIVERSAL_GEO_REMOTE_ACCOUNT_ID
			&& defined( 'UNIVERSAL_GEO_REMOTE_LICENSE_KEY' ) && is_string( UNIVERSAL_GEO_REMOTE_LICENSE_KEY ) && '' !== UNIVERSAL_GEO_REMOTE_LICENSE_KEY
		) {
			return array(
				'account_id'  => UNIVERSAL_GEO_REMOTE_ACCOUNT_ID,
				'license_key' => UNIVERSAL_GEO_REMOTE_LICENSE_KEY,
				'source'      => 'legacy_constants',
			);
		}

		if ( '' !== $settings['maxmind_account_id'] && '' !== $settings['maxmind_license_key'] ) {
			return array(
				'account_id'  => $settings['maxmind_account_id'],
				'license_key' => $settings['maxmind_license_key'],
				'source'      => 'settings',
			);
		}

		return array(
			'account_id'  => '',
			'license_key' => '',
			'source'      => 'none',
		);
	}

	/**
	 * Applies the universal_geo_providers filter. The filtered array's
	 * order IS resolution order (Revision 3 §4.4) — this is the
	 * advanced-customization surface that replaced a provider_order
	 * setting. Every element is re-validated: a filter must never be able
	 * to fatal a page view by handing ContextResolver's throwing
	 * constructor something that isn't a GeoProviderInterface, so
	 * non-conforming elements are dropped here, with _doing_it_wrong(),
	 * before ContextResolver is ever constructed. A non-array result is
	 * discarded wholesale and the original providers are kept.
	 *
	 * @param GeoProviderInterface[] $providers Providers in resolution order, before filtering.
	 *
	 * @return GeoProviderInterface[]
	 */
	private function filtered_providers( array $providers ): array {
		/**
		 * Filters the ordered provider list.
		 *
		 * @since 0.2.0
		 *
		 * @param GeoProviderInterface[] $providers Providers in resolution order.
		 */
		$filtered = apply_filters( 'universal_geo_providers', $providers );

		if ( ! is_array( $filtered ) ) {
			return $providers;
		}

		$valid = array();

		foreach ( $filtered as $provider ) {
			if ( $provider instanceof GeoProviderInterface ) {
				$valid[] = $provider;
				continue;
			}

			_doing_it_wrong(
				'universal_geo_providers',
				__( 'The universal_geo_providers filter must only return GeoProviderInterface instances; a non-conforming element was dropped.', 'universal-geo-context' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see the identical note on the first _doing_it_wrong() call above.
				UNIVERSAL_GEO_VERSION // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		return $valid;
	}

	/**
	 * Revision 3 §14's exact re-validation rule: a non-object, wrong
	 * class, or invalid country causes the filtered value to be discarded.
	 *
	 * VisitorContext's own constructor only checks structural shape
	 * (^[A-Z]{2}$), not real ISO membership, so a filter could otherwise
	 * hand back a structurally-valid-but-not-a-real-country context (e.g.
	 * 'XX') undetected without this check.
	 *
	 * @param mixed $filtered The filter's return value.
	 *
	 * @return bool
	 */
	private function is_valid_filtered_context( mixed $filtered ): bool {
		if ( ! $filtered instanceof VisitorContext ) {
			return false;
		}

		return null === $filtered->country_code || null !== GeoValidator::country( $filtered->country_code );
	}
}
