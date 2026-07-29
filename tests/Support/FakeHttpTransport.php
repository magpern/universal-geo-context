<?php
/**
 * Hand-rolled HttpTransport test double (Revision 3 §16: no mocking framework).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Support;

use RuntimeException;
use UniversalGeo\Providers\Remote\HttpTransport;
use UniversalGeo\Providers\Remote\TransportException;
use UniversalGeo\Providers\Remote\TransportResponse;

/**
 * A queue of canned outcomes (a `TransportResponse` to return or a
 * `TransportException` to throw), consumed in order, one per `get()` call.
 * Every call is recorded (url, headers, timeout) so tests can assert both
 * the outcome the provider produced and exactly what request it made —
 * including the all-important "how many times was this called" count the
 * M4 cache-interaction and circuit-breaker tests depend on.
 *
 * @internal
 */
final class FakeHttpTransport implements HttpTransport {

	/**
	 * @var array<int, array{type: string, value: TransportResponse|TransportException}>
	 */
	private array $queue = array();

	/**
	 * @var array<int, array{url: string, headers: array<string, string>, timeout_seconds: int}>
	 */
	public array $calls = array();

	/**
	 * Queues a response to return on the next get() call.
	 *
	 * @param TransportResponse $response The response to return.
	 *
	 * @return self
	 */
	public function will_return( TransportResponse $response ): self {
		$this->queue[] = array(
			'type'  => 'response',
			'value' => $response,
		);

		return $this;
	}

	/**
	 * Queues an exception to throw on the next get() call.
	 *
	 * @param TransportException $exception The exception to throw.
	 *
	 * @return self
	 */
	public function will_throw( TransportException $exception ): self {
		$this->queue[] = array(
			'type'  => 'exception',
			'value' => $exception,
		);

		return $this;
	}

	/**
	 * Records the call and returns/throws the next queued outcome. When the
	 * next queued outcome is an exception (queued via will_throw()), the
	 * queued TransportException instance itself is thrown.
	 *
	 * @param string                $url             The complete request URL.
	 * @param array<string, string> $headers         Request headers, keyed by header name.
	 * @param int                   $timeout_seconds The request timeout, in seconds.
	 *
	 * @return TransportResponse
	 *
	 * @throws RuntimeException When no outcome is queued — a test-authoring error, not a production path.
	 */
	public function get( string $url, array $headers, int $timeout_seconds ): TransportResponse {
		$this->calls[] = array(
			'url'             => $url,
			'headers'         => $headers,
			'timeout_seconds' => $timeout_seconds,
		);

		if ( array() === $this->queue ) {
			throw new RuntimeException( 'FakeHttpTransport: no canned response or exception was queued for this call.' );
		}

		$next = array_shift( $this->queue );

		if ( 'exception' === $next['type'] ) {
			throw $next['value'];
		}

		return $next['value'];
	}

	/**
	 * The number of get() calls made so far — the single most important
	 * assertion the M4 cache-interaction tests need ("the transport call
	 * count remains unchanged").
	 *
	 * @return int
	 */
	public function call_count(): int {
		return count( $this->calls );
	}
}
