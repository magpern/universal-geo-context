<?php
/**
 * Universal Geo Context plugin composition root.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo;

use UniversalGeo\Admin\AdminScreen;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Providers\CloudflareHeaderProvider;
use UniversalGeo\Providers\DefaultCountryProvider;
use UniversalGeo\Providers\WooCommerceProvider;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Resolver\GeoValidator;

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
	 * or WP-CLI request path.
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

		if ( $this->should_register_admin() ) {
			$diagnostics = new DiagnosticsService(
				$graph['resolver'],
				$graph['client_ip_resolver'],
				$graph['server_request'],
				$graph['trusted_proxies'],
				$graph['settings']
			);
			$diagnostics->register();

			( new AdminScreen( $diagnostics, $graph['server_request'] ) )->register();
		}
	}

	/**
	 * Revision 3 §4.5's exact admin registration condition: admin screens
	 * register only on a real wp-admin request, never on AJAX, cron, or
	 * WP-CLI (WP_CLI is undefined until M5's Cli\Command.php exists, but the
	 * condition is written now so it needs no revisiting then).
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
				'Called before Universal Geo Context has booted. Call at or after the plugins_loaded priority 20.',
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
				'The universal_geo_context filter must return a VisitorContext with a valid country code.',
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
	 * maxmind, woocommerce, remote, default. MaxMind and remote don't exist
	 * until M3/M4, so the array built here is cloudflare, woocommerce,
	 * default — already in PROVIDER_ORDER's relative order.
	 *
	 * @return array{resolver: ContextResolver, client_ip_resolver: ClientIpResolver, server_request: ServerRequest, trusted_proxies: TrustedProxies, settings: array<string, mixed>}
	 */
	private function build_graph(): array {
		$settings = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) );

		$server_request  = ServerRequest::capture( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$trusted_proxies = new TrustedProxies( $settings['trusted_proxies'], $settings['trust_cloudflare'] );

		$client_ip_resolver = new ClientIpResolver( $server_request, $trusted_proxies );

		$default_country = $this->filtered_default_country( $settings['default_country'] );

		$providers = $this->filtered_providers(
			array(
				new CloudflareHeaderProvider( $server_request, $client_ip_resolver ),
				new WooCommerceProvider(),
				new DefaultCountryProvider( $default_country ),
			)
		);

		$config_sig = hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'default_country'  => $default_country,
					'trusted_proxies'  => $settings['trusted_proxies'],
					'trust_cloudflare' => $settings['trust_cloudflare'],
				)
			)
		);

		$cache = new GeoCache( $settings['derived_cache_enabled'], $settings['derived_cache_ttl'], $config_sig );

		$resolver = new ContextResolver(
			$client_ip_resolver,
			$providers,
			$cache,
			static function ( string $provider_id, string $reason ): void {
				/**
				 * Fires when a provider's resolve() throws.
				 *
				 * @since 0.2.0
				 *
				 * @param string $provider_id The failing provider's get_id().
				 * @param string $reason      The exception's class and message. Never a client IP.
				 */
				do_action( 'universal_geo_provider_failed', $provider_id, $reason );
			}
		);

		return array(
			'resolver'           => $resolver,
			'client_ip_resolver' => $client_ip_resolver,
			'server_request'     => $server_request,
			'trusted_proxies'    => $trusted_proxies,
			'settings'           => $settings,
		);
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
				'The universal_geo_providers filter must only return GeoProviderInterface instances; a non-conforming element was dropped.',
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
