<?php
/**
 * Cache-safe visitor context: a minimal, read-only REST v1 surface.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Rest;

use UniversalGeo\Model\VisitorContext;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the current request's effective (simulation-aware) visitor context
 * as a small, independently-versioned JSON contract, for consumers that need
 * a correct answer after full-page/CDN-cached HTML has already loaded in the
 * browser (M14, ADR-0012).
 *
 * A thin serializer only: depends on a narrow callable — never on Plugin, and
 * never a service locator — so the exact same effective-context path the six
 * PHP functions and the simulation filter already use is reused unmodified,
 * with no second resolution implementation. The REST v1 body is a
 * deliberately frozen two-key contract (country_code, region_code) that is
 * NOT VisitorContext::to_array() and does not reference
 * VisitorContext::SCHEMA_VERSION — see docs/adr/0012-cache-safe-visitor-context.md.
 *
 * @internal
 * @final
 */
final class ContextController {

	/**
	 * REST namespace/route (Revision M14 §A).
	 */
	private const NAMESPACE = 'universal-geo-context/v1';
	private const ROUTE     = '/context';

	/**
	 * Stores the injected effective-context provider.
	 *
	 * @var callable(): VisitorContext
	 */
	private $context_provider;

	/**
	 * Stores the injected dependency.
	 *
	 * @param callable $context_provider fn(): VisitorContext — returns the current
	 *                                    request's effective (simulation-aware) context.
	 *                                    Supplied by the composition root only
	 *                                    (Plugin::init(), as array( $this, 'context' )) —
	 *                                    this class never imports or type-hints Plugin.
	 */
	public function __construct( callable $context_provider ) {
		$this->context_provider = $context_provider;
	}

	/**
	 * Registers the REST route.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Registers universal-geo-context/v1/context — public, GET-only.
	 *
	 * The permission_callback is deliberately '__return_true': the response
	 * contains no privileged data (the visitor's own resolved geography, the
	 * same two facts already implicit in geography-dependent HTML), so
	 * anonymous access is the intended default, not an oversight.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_context' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Route callback: resolves the effective context via the injected
	 * callable only, maps it to the frozen REST v1 contract, and marks the
	 * response as never cacheable by a shared HTTP cache.
	 *
	 * @param WP_REST_Request $request Unused: this route accepts no parameters.
	 *
	 * @return WP_REST_Response
	 */
	public function get_context( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$context  = ( $this->context_provider )();
		$response = new WP_REST_Response( $this->to_rest_contract( $context ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The REST v1 contract's single source of truth: exactly two keys,
	 * always present. Deliberately independent of VisitorContext::to_array()
	 * (which serves GeoCache's own round-trip, a different consumer with
	 * different needs) so the public network contract can evolve, or not
	 * evolve, on its own terms — see docs/adr/0012-cache-safe-visitor-context.md.
	 *
	 * @param VisitorContext $context The effective, already-resolved context.
	 *
	 * @return array{country_code: string|null, region_code: string|null}
	 */
	private function to_rest_contract( VisitorContext $context ): array {
		return array(
			'country_code' => $context->country_code,
			'region_code'  => $context->region_code,
		);
	}
}
