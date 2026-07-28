<?php
/**
 * Unit tests for UniversalGeo\Http\ServerRequest.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Tests\Support\ServerRequestFactory;

/**
 * Covers the M2 trust-boundary snapshot: header lookup (case-insensitive,
 * HTTP_ mapping), remote_addr(), present_forwarding_headers(), and drift().
 * No trust judgement, normalization, or validation belongs here — those are
 * ClientIpResolver's and IpUtils' jobs.
 */
final class ServerRequestTest extends TestCase {

	// ---- capture() / construction --------------------------------------------

	public function test_capture_returns_a_server_request(): void {
		$this->assertInstanceOf( ServerRequest::class, ServerRequest::capture( array() ) );
	}

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( ServerRequest::class ) )->isFinal() );
	}

	public function test_constructor_is_private(): void {
		$constructor = ( new ReflectionClass( ServerRequest::class ) )->getConstructor();
		$this->assertTrue( $constructor->isPrivate() );
	}

	// ---- remote_addr() --------------------------------------------------------

	public function test_remote_addr_reads_the_captured_value(): void {
		$request = ServerRequest::capture( array( 'REMOTE_ADDR' => '203.0.113.5' ) );
		$this->assertSame( '203.0.113.5', $request->remote_addr() );
	}

	public function test_remote_addr_is_null_when_absent(): void {
		$request = ServerRequest::capture( array() );
		$this->assertNull( $request->remote_addr() );
	}

	public function test_remote_addr_is_null_when_non_string(): void {
		$request = ServerRequest::capture( array( 'REMOTE_ADDR' => array( 'not-a-string' ) ) );
		$this->assertNull( $request->remote_addr() );
	}

	// ---- header() ---------------------------------------------------------------

	public function test_header_reads_x_forwarded_for(): void {
		$request = ServerRequest::capture( array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 203.0.113.1' ) );
		$this->assertSame( '198.51.100.1, 203.0.113.1', $request->header( 'X-Forwarded-For' ) );
	}

	public function test_header_reads_cf_connecting_ip(): void {
		$request = ServerRequest::capture( array( 'HTTP_CF_CONNECTING_IP' => '198.51.100.9' ) );
		$this->assertSame( '198.51.100.9', $request->header( 'CF-Connecting-IP' ) );
	}

	public function test_header_reads_x_real_ip(): void {
		$request = ServerRequest::capture( array( 'HTTP_X_REAL_IP' => '198.51.100.2' ) );
		$this->assertSame( '198.51.100.2', $request->header( 'X-Real-IP' ) );
	}

	public function test_header_lookup_is_case_insensitive_to_the_argument(): void {
		$request = ServerRequest::capture( array( 'HTTP_X_REAL_IP' => '198.51.100.2' ) );
		$this->assertSame( '198.51.100.2', $request->header( 'x-real-ip' ) );
	}

	public function test_header_returns_null_when_absent(): void {
		$request = ServerRequest::capture( array() );
		$this->assertNull( $request->header( 'X-Forwarded-For' ) );
	}

	public function test_header_returns_null_for_non_string_value(): void {
		$request = ServerRequest::capture( array( 'HTTP_X_REAL_IP' => array( 'nope' ) ) );
		$this->assertNull( $request->header( 'X-Real-IP' ) );
	}

	public function test_header_returns_null_for_empty_string(): void {
		$request = ServerRequest::capture( array( 'HTTP_X_REAL_IP' => '' ) );
		$this->assertNull( $request->header( 'X-Real-IP' ) );
	}

	public function test_header_returns_null_for_whitespace_only(): void {
		$request = ServerRequest::capture( array( 'HTTP_X_REAL_IP' => '   ' ) );
		$this->assertNull( $request->header( 'X-Real-IP' ) );
	}

	// ---- present_forwarding_headers() -------------------------------------------

	public function test_present_forwarding_headers_lists_only_headers_actually_set(): void {
		$request = ServerRequestFactory::make(
			'203.0.113.1',
			array(
				'X-Forwarded-For' => '198.51.100.1',
				'X-Real-IP'       => '198.51.100.2',
			)
		);

		$this->assertSame( array( 'X-Forwarded-For', 'X-Real-IP' ), $request->present_forwarding_headers() );
	}

	public function test_present_forwarding_headers_is_empty_when_none_present(): void {
		$request = ServerRequestFactory::make( '203.0.113.1' );
		$this->assertSame( array(), $request->present_forwarding_headers() );
	}

	public function test_present_forwarding_headers_includes_never_trusted_names(): void {
		// Forwarded / True-Client-IP / Client-IP are never read for trust
		// (Revision 3 §7) but must still be visible in diagnostics presence
		// reporting when they arrive.
		$request = ServerRequestFactory::make(
			'203.0.113.1',
			array(
				'Forwarded'      => 'for=198.51.100.1',
				'True-Client-IP' => '198.51.100.1',
				'Client-IP'      => '198.51.100.1',
			)
		);

		$this->assertSame(
			array( 'Forwarded', 'True-Client-IP', 'Client-IP' ),
			$request->present_forwarding_headers()
		);
	}

	// ---- drift() ------------------------------------------------------------------

	public function test_drift_is_empty_for_identical_snapshots(): void {
		$request = ServerRequestFactory::make( '203.0.113.1', array( 'X-Real-IP' => '198.51.100.2' ) );
		$live    = ServerRequestFactory::make( '203.0.113.1', array( 'X-Real-IP' => '198.51.100.2' ) );

		$this->assertSame( array(), $request->drift( $live ) );
	}

	public function test_drift_detects_remote_addr_change(): void {
		$request = ServerRequestFactory::make( '203.0.113.1' );
		$live    = ServerRequestFactory::make( '198.51.100.9' );

		$this->assertSame( array( 'REMOTE_ADDR' ), $request->drift( $live ) );
	}

	public function test_drift_detects_a_forwarding_header_change(): void {
		$request = ServerRequestFactory::make( '203.0.113.1', array( 'X-Real-IP' => '198.51.100.2' ) );
		$live    = ServerRequestFactory::make( '203.0.113.1', array( 'X-Real-IP' => '198.51.100.3' ) );

		$this->assertSame( array( 'X-Real-IP' ), $request->drift( $live ) );
	}

	public function test_drift_detects_a_header_appearing(): void {
		$request = ServerRequestFactory::make( '203.0.113.1' );
		$live    = ServerRequestFactory::make( '203.0.113.1', array( 'X-Forwarded-For' => '198.51.100.1' ) );

		$this->assertSame( array( 'X-Forwarded-For' ), $request->drift( $live ) );
	}

	public function test_drift_lists_multiple_changed_fields(): void {
		$request = ServerRequestFactory::make( '203.0.113.1', array( 'X-Real-IP' => '198.51.100.2' ) );
		$live    = ServerRequestFactory::make( '198.51.100.9', array( 'X-Real-IP' => '198.51.100.3' ) );

		$this->assertSame( array( 'REMOTE_ADDR', 'X-Real-IP' ), $request->drift( $live ) );
	}

	public function test_drift_never_contains_a_value_only_field_names(): void {
		$request = ServerRequestFactory::make( '203.0.113.1' );
		$live    = ServerRequestFactory::make( '198.51.100.9' );

		foreach ( $request->drift( $live ) as $field ) {
			$this->assertNotSame( '198.51.100.9', $field );
		}
	}
}
