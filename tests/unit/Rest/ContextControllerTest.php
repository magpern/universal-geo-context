<?php
/**
 * Unit tests for the M14 REST v1 response-shaping logic.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Rest\ContextController;
use WP_REST_Request;

/**
 * Proves the frozen REST v1 contract entirely without a WordPress
 * bootstrap — enabled by ContextController's callable-based DI (§B): a
 * bare fixture closure stands in for Plugin::effective_context(), so no
 * WordPress, no Plugin, and no resolver/provider machinery is needed to
 * exercise get_context()'s own mapping logic.
 *
 * @internal
 */
final class ContextControllerTest extends TestCase {

	public function test_known_context_maps_to_exact_two_keys(): void {
		$controller = new ContextController(
			static fn(): VisitorContext => new VisitorContext( 'SE', 'AB', 'maxmind', 0.9, false )
		);

		$response = $controller->get_context( new WP_REST_Request() );
		$data     = $response->get_data();

		$this->assertSame( array( 'country_code', 'region_code' ), array_keys( $data ) );
		$this->assertSame( 'SE', $data['country_code'] );
		$this->assertSame( 'AB', $data['region_code'] );
	}

	public function test_unknown_context_maps_to_two_nulls(): void {
		$controller = new ContextController(
			static fn(): VisitorContext => VisitorContext::unknown()
		);

		$data = $controller->get_context( new WP_REST_Request() )->get_data();

		$this->assertSame( array( 'country_code', 'region_code' ), array_keys( $data ) );
		$this->assertNull( $data['country_code'] );
		$this->assertNull( $data['region_code'] );
	}

	public function test_simulated_context_region_passes_through_as_null(): void {
		$controller = new ContextController(
			static fn(): VisitorContext => new VisitorContext( 'NO', null, 'simulation', 1.0, false )
		);

		$data = $controller->get_context( new WP_REST_Request() )->get_data();

		$this->assertSame( 'NO', $data['country_code'] );
		$this->assertNull( $data['region_code'] );
	}

	public function test_response_never_contains_a_third_key(): void {
		$controller = new ContextController(
			static fn(): VisitorContext => new VisitorContext( 'SE', 'AB', 'maxmind', 0.9, true )
		);

		$data = $controller->get_context( new WP_REST_Request() )->get_data();

		$this->assertCount( 2, $data );
	}

	public function test_response_always_carries_no_store_regardless_of_context(): void {
		$known_controller   = new ContextController(
			static fn(): VisitorContext => new VisitorContext( 'SE', null, 'default', 0.1, false )
		);
		$unknown_controller = new ContextController(
			static fn(): VisitorContext => VisitorContext::unknown()
		);

		foreach ( array( $known_controller, $unknown_controller ) as $controller ) {
			$headers = $controller->get_context( new WP_REST_Request() )->get_headers();
			$this->assertArrayHasKey( 'Cache-Control', $headers );
			$this->assertSame( 'no-store', $headers['Cache-Control'] );
		}
	}

	public function test_context_provider_is_invoked_exactly_once_per_call(): void {
		$calls      = 0;
		$controller = new ContextController(
			static function () use ( &$calls ): VisitorContext {
				++$calls;

				return VisitorContext::unknown();
			}
		);

		$controller->get_context( new WP_REST_Request() );
		$controller->get_context( new WP_REST_Request() );

		$this->assertSame( 2, $calls, 'get_context() must call the injected provider itself every time — no caching of its own inside ContextController.' );
	}
}
