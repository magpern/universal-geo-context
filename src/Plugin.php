<?php
/**
 * Universal Geo Context plugin composition root.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo;

use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Http\RemoteAddrOnlyResolver;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Providers\DefaultCountryProvider;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Resolver\GeoValidator;

/**
 * Plugin bootstrap and composition root.
 *
 * The only composition root in the entire plugin, and the sole entry
 * point the main bootstrap file calls into for both the activation
 * lifecycle (activate()) and the request lifecycle (instance()->init()).
 *
 * Per Revision 3 §5, init() eagerly *constructs* the full M1 object graph
 * (RemoteAddrOnlyResolver, the provider array, GeoCache, ContextResolver) —
 * that construction is cheap (no I/O). What stays lazy is *resolution*:
 * ContextResolver::resolve() is never called here, only on the first
 * genuine call to context() ("zero cost for requests that never ask").
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
	 * The M1 resolver graph, built once by init(). Null only before boot.
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
	 * Idempotent; safe to call multiple times. Builds the M1 object graph;
	 * does not resolve anything.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->resolver = $this->build_resolver();
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
	 * Builds the M1 resolver graph.
	 *
	 * Settings' current, frozen two-key schema does not yet expose
	 * 'derived_cache_enabled' or 'derived_cache_ttl' as administrator-
	 * configurable — Revision 3 §11's documented defaults (true, 900) are
	 * used verbatim until a future Settings-schema expansion adds them;
	 * that expansion is out of this step's scope.
	 *
	 * config_sig hashes the one currently-existing setting that affects
	 * resolution (default_country) — the smallest deterministic signature
	 * satisfying Revision 3 §9's "hashes the settings that affect
	 * resolution", since no other resolution-affecting setting exists yet.
	 *
	 * @return ContextResolver
	 */
	private function build_resolver(): ContextResolver {
		$settings = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) );

		$providers = array(
			new DefaultCountryProvider( $settings['default_country'] ),
		);

		$config_sig = hash( 'sha256', $settings['default_country'] );
		$cache      = new GeoCache( true, 900, $config_sig );

		return new ContextResolver( new RemoteAddrOnlyResolver(), $providers, $cache );
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
