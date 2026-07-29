<?php
/**
 * Unit tests for UniversalGeo\Providers\Remote\TransportResponse.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Providers\Remote;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Providers\Remote\TransportResponse;

/**
 * Covers construction — the model-level coverage the M4 report requires for
 * this value object.
 */
final class TransportResponseTest extends TestCase {

	public function test_construction_stores_the_status_code_and_body(): void {
		$response = new TransportResponse( 200, '{"country":{"iso_code":"US"}}' );

		$this->assertSame( 200, $response->status_code );
		$this->assertSame( '{"country":{"iso_code":"US"}}', $response->body );
	}

	public function test_a_404_status_code_is_representable(): void {
		$response = new TransportResponse( 404, '' );

		$this->assertSame( 404, $response->status_code );
		$this->assertSame( '', $response->body );
	}
}
