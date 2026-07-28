<?php
/**
 * Test fixture builder for UniversalGeo\Http\ServerRequest.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Support;

use UniversalGeo\Http\ServerRequest;

/**
 * Builds a ServerRequest from readable test inputs (a bare REMOTE_ADDR plus
 * canonical header names) instead of a raw $_SERVER-shaped array — the seam
 * Revision 3 §16 names explicitly ("ServerRequest is built from an array —
 * pass a fixture"). No mocking framework.
 */
final class ServerRequestFactory {

	/**
	 * @param string|null           $remote_addr REMOTE_ADDR to set, or null to omit it entirely.
	 * @param array<string, string> $headers     Canonical header name => value, e.g. ['X-Forwarded-For' => '1.2.3.4, 5.6.7.8'].
	 *
	 * @return ServerRequest
	 */
	public static function make( ?string $remote_addr = '203.0.113.1', array $headers = array() ): ServerRequest {
		$server = array();

		if ( null !== $remote_addr ) {
			$server['REMOTE_ADDR'] = $remote_addr;
		}

		foreach ( $headers as $name => $value ) {
			$server[ 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) ) ] = $value;
		}

		return ServerRequest::capture( $server );
	}
}
