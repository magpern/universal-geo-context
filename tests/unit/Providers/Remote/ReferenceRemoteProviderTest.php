<?php
/**
 * Unit tests for UniversalGeo\Providers\Remote\ReferenceRemoteProvider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Providers\Remote;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Providers\Remote\ReferenceRemoteProvider;
use UniversalGeo\Providers\Remote\TransportException;
use UniversalGeo\Providers\Remote\TransportResponse;
use UniversalGeo\Model\ResolvedClientIp;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use UniversalGeo\Tests\Unit\Doubles\FakeClientIpResolver;

/**
 * Covers the frozen M4 failure matrix: disabled/misconfigured availability,
 * the non-public-IP self-guard, the circuit-breaker gate, every response
 * classification (404 miss, 200 success, unexpected status, malformed
 * JSON, missing country field), transport-exception propagation, and the
 * "at most one outbound attempt per resolve() call, degrade cleanly"
 * contract — all against FakeHttpTransport, never real network I/O (the
 * disabled-state outbound-HTTP trap this suite and the composition-root
 * guard both defend).
 */
final class ReferenceRemoteProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['universal_geo_test_options'] = array();
	}

	private function provider(
		FakeHttpTransport $transport,
		bool $enabled = true,
		string $account_id = 'acct',
		string $license_key = 'key',
		?CircuitBreaker $circuit_breaker = null
	): ReferenceRemoteProvider {
		return new ReferenceRemoteProvider(
			$enabled,
			$account_id,
			$license_key,
			$transport,
			$circuit_breaker ?? new CircuitBreaker()
		);
	}

	// ---- get_id() ---------------------------------------------------------------

	public function test_get_id_returns_remote(): void {
		$this->assertSame( 'remote', $this->provider( new FakeHttpTransport() )->get_id() );
	}

	// ---- is_available() ----------------------------------------------------------

	public function test_is_available_false_when_disabled(): void {
		$provider = $this->provider( new FakeHttpTransport(), false );
		$this->assertFalse( $provider->is_available() );
	}

	public function test_is_available_false_when_account_id_missing(): void {
		$provider = $this->provider( new FakeHttpTransport(), true, '', 'key' );
		$this->assertFalse( $provider->is_available() );
	}

	public function test_is_available_false_when_license_key_missing(): void {
		$provider = $this->provider( new FakeHttpTransport(), true, 'acct', '' );
		$this->assertFalse( $provider->is_available() );
	}

	public function test_is_available_true_when_enabled_with_both_credentials(): void {
		$provider = $this->provider( new FakeHttpTransport(), true, 'acct', 'key' );
		$this->assertTrue( $provider->is_available() );
	}

	// ---- Non-public IP self-guard ------------------------------------------------

	public function test_resolve_returns_null_for_a_private_ip_without_calling_transport(): void {
		$transport = new FakeHttpTransport();
		$provider  = $this->provider( $transport );

		$this->assertNull( $provider->resolve( '10.0.0.5' ) );
		$this->assertSame( 0, $transport->call_count() );
	}

	public function test_resolve_returns_null_for_loopback_without_calling_transport(): void {
		$transport = new FakeHttpTransport();
		$provider  = $this->provider( $transport );

		$this->assertNull( $provider->resolve( '127.0.0.1' ) );
		$this->assertSame( 0, $transport->call_count() );
	}

	// ---- The disabled-state / circuit-breaker outbound-HTTP trap ----------------

	public function test_disabled_provider_never_calls_the_transport(): void {
		// A disabled provider is never even asked to resolve() in real
		// composition (Plugin gates is_available() before the resolver
		// loop calls resolve()) — this test proves resolve() itself is
		// also incapable of an outbound call while misconfigured, as a
		// second, independent layer of defense.
		$transport = new FakeHttpTransport();
		$provider  = $this->provider( $transport, false );

		$provider->resolve( '8.8.8.8' );

		$this->assertSame( 0, $transport->call_count() );
	}

	public function test_resolve_makes_no_call_when_the_circuit_breaker_denies_the_attempt(): void {
		$transport       = new FakeHttpTransport();
		$circuit_breaker = new CircuitBreaker( static fn (): int => 1000000000 );
		$circuit_breaker->report_failure();
		$circuit_breaker->report_failure();
		$circuit_breaker->report_failure(); // Opens the circuit.

		$provider = $this->provider( $transport, true, 'acct', 'key', $circuit_breaker );

		$this->assertNull( $provider->resolve( '8.8.8.8' ) );
		$this->assertSame( 0, $transport->call_count() );
	}

	// ---- Request construction ----------------------------------------------------

	public function test_resolve_sends_a_basic_auth_header_built_from_the_credentials(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 200, '{"country":{"iso_code":"US"}}' ) );

		$this->provider( $transport, true, 'my-account', 'my-license' )->resolve( '8.8.8.8' );

		$expected = 'Basic ' . base64_encode( 'my-account:my-license' );
		$this->assertSame( $expected, $transport->calls[0]['headers']['Authorization'] );
	}

	public function test_resolve_builds_the_hardcoded_endpoint_with_the_ip_appended(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 200, '{"country":{"iso_code":"US"}}' ) );

		$this->provider( $transport )->resolve( '8.8.8.8' );

		$this->assertSame( 'https://geolite.info/geoip/v2.1/country/8.8.8.8', $transport->calls[0]['url'] );
	}

	public function test_resolve_makes_exactly_one_transport_call(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 200, '{"country":{"iso_code":"US"}}' ) );

		$this->provider( $transport )->resolve( '8.8.8.8' );

		$this->assertSame( 1, $transport->call_count() );
	}

	// ---- Response classification: healthy 404 miss -------------------------------

	public function test_resolve_returns_null_on_a_healthy_404(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 404, '' ) );

		$this->assertNull( $this->provider( $transport )->resolve( '8.8.8.8' ) );
	}

	public function test_a_404_reports_success_to_the_circuit_breaker(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 404, '' ) );
		$circuit_breaker = new CircuitBreaker();
		$circuit_breaker->report_failure();
		$circuit_breaker->report_failure(); // Two failures accumulated.

		$this->provider( $transport, true, 'acct', 'key', $circuit_breaker )->resolve( '8.8.8.8' );

		// A healthy 404 must reset the streak — proven by a third failure
		// no longer being enough to open the circuit.
		$circuit_breaker->report_failure();
		$this->assertSame( 'closed', $circuit_breaker->state()['state'] );
	}

	// ---- Response classification: success ----------------------------------------

	public function test_resolve_returns_a_geo_candidate_on_a_valid_response(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 200, '{"country":{"iso_code":"US"}}' ) );

		$candidate = $this->provider( $transport )->resolve( '8.8.8.8' );

		$this->assertSame( 'US', $candidate->country_code );
		$this->assertNull( $candidate->region_code );
	}

	public function test_success_reports_success_to_the_circuit_breaker(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 200, '{"country":{"iso_code":"US"}}' ) );
		$circuit_breaker = new CircuitBreaker();
		$circuit_breaker->report_failure();
		$circuit_breaker->report_failure();

		$this->provider( $transport, true, 'acct', 'key', $circuit_breaker )->resolve( '8.8.8.8' );

		$circuit_breaker->report_failure();
		$this->assertSame( 'closed', $circuit_breaker->state()['state'] );
	}

	// ---- Response classification: unexpected status ------------------------------

	public function test_an_unexpected_status_throws(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 500, 'Internal Server Error' ) );

		$this->expectException( RuntimeException::class );
		$this->provider( $transport )->resolve( '8.8.8.8' );
	}

	public function test_an_unexpected_status_reports_failure_to_the_circuit_breaker(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 500, '' ) );
		$circuit_breaker = new CircuitBreaker( static fn (): int => 1000000000 );

		try {
			$this->provider( $transport, true, 'acct', 'key', $circuit_breaker )->resolve( '8.8.8.8' );
		} catch ( RuntimeException $e ) {
			unset( $e ); // Expected; assertion is on circuit-breaker state below.
		}

		$this->assertSame( 1, $circuit_breaker->state()['failure_count'] );
	}

	// ---- Response classification: malformed body ---------------------------------

	public function test_malformed_json_throws(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 200, 'not-json{' ) );

		$this->expectException( RuntimeException::class );
		$this->provider( $transport )->resolve( '8.8.8.8' );
	}

	public function test_missing_country_field_throws(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 200, '{"something_else":true}' ) );

		$this->expectException( RuntimeException::class );
		$this->provider( $transport )->resolve( '8.8.8.8' );
	}

	public function test_non_string_iso_code_throws(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 200, '{"country":{"iso_code":123}}' ) );

		$this->expectException( RuntimeException::class );
		$this->provider( $transport )->resolve( '8.8.8.8' );
	}

	public function test_malformed_response_reports_failure_to_the_circuit_breaker(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 200, 'not-json{' ) );
		$circuit_breaker = new CircuitBreaker();

		try {
			$this->provider( $transport, true, 'acct', 'key', $circuit_breaker )->resolve( '8.8.8.8' );
		} catch ( RuntimeException $e ) {
			unset( $e ); // Expected; assertion is on circuit-breaker state below.
		}

		$this->assertSame( 1, $circuit_breaker->state()['failure_count'] );
	}

	// ---- Transport-exception propagation ------------------------------------------

	public function test_transport_exception_propagates(): void {
		$transport = new FakeHttpTransport();
		$transport->will_throw( TransportException::scrubbed( 'Connection timed out', '' ) );

		$this->expectException( TransportException::class );
		$this->provider( $transport )->resolve( '8.8.8.8' );
	}

	public function test_transport_exception_reports_failure_to_the_circuit_breaker(): void {
		$transport = new FakeHttpTransport();
		$transport->will_throw( TransportException::scrubbed( 'Connection timed out', '' ) );
		$circuit_breaker = new CircuitBreaker();

		try {
			$this->provider( $transport, true, 'acct', 'key', $circuit_breaker )->resolve( '8.8.8.8' );
		} catch ( TransportException $e ) {
			unset( $e ); // Expected; assertion is on circuit-breaker state below.
		}

		$this->assertSame( 1, $circuit_breaker->state()['failure_count'] );
	}

	// ---- Full failure matrix: three consecutive failures open the circuit -------

	public function test_three_consecutive_resolve_failures_open_the_circuit_breaker(): void {
		$transport = new FakeHttpTransport();
		$transport->will_throw( TransportException::scrubbed( 'fail 1', '' ) );
		$transport->will_throw( TransportException::scrubbed( 'fail 2', '' ) );
		$transport->will_throw( TransportException::scrubbed( 'fail 3', '' ) );

		$circuit_breaker = new CircuitBreaker( static fn (): int => 1000000000 );
		$provider        = $this->provider( $transport, true, 'acct', 'key', $circuit_breaker );

		foreach ( range( 1, 3 ) as $attempt ) {
			try {
				$provider->resolve( '8.8.8.8' );
			} catch ( TransportException $e ) {
				unset( $e ); // Expected; assertion is on circuit-breaker state below.
			}
		}

		$this->assertSame( 'open', $circuit_breaker->state()['state'] );

		// A fourth resolve() call now makes no outbound attempt at all.
		$provider->resolve( '8.8.8.8' );
		$this->assertSame( 3, $transport->call_count() );
	}

	// ---- End-to-end via the real (frozen, unmodified) ContextResolver -----------

	public function test_end_to_end_via_context_resolver_reports_source_remote_and_confidence_085(): void {
		$transport = new FakeHttpTransport();
		$transport->will_return( new TransportResponse( 200, '{"country":{"iso_code":"US"}}' ) );

		$provider = $this->provider( $transport );
		$resolver = new ContextResolver(
			new FakeClientIpResolver( new ResolvedClientIp( '8.8.8.8', 'REMOTE_ADDR', false, true ) ),
			array( $provider ),
			new GeoCache( false, 900, 'sig' )
		);

		$context = $resolver->resolve();

		$this->assertSame( 'US', $context->country_code );
		$this->assertSame( 'remote', $context->source );
		$this->assertSame( 0.85, $context->confidence );
	}
}
