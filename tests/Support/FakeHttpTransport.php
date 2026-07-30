<?php
/**
 * Hand-rolled HttpTransport test double (Revision 3 §16: no mocking framework).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Support;

use RuntimeException;
use UniversalGeo\Providers\Remote\DownloadResult;
use UniversalGeo\Providers\Remote\HttpTransport;
use UniversalGeo\Providers\Remote\RedirectResult;
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

	// ---- M6: get_redirect_location() ---------------------------------------

	/**
	 * @var array<int, array{type: string, value: RedirectResult|TransportException}>
	 */
	private array $redirect_queue = array();

	/**
	 * @var array<int, array{url: string, headers: array<string, string>, timeout_seconds: int}>
	 */
	public array $redirect_calls = array();

	/**
	 * Queues a result to return on the next get_redirect_location() call.
	 *
	 * @param RedirectResult $result The result to return.
	 *
	 * @return self
	 */
	public function will_return_redirect( RedirectResult $result ): self {
		$this->redirect_queue[] = array(
			'type'  => 'result',
			'value' => $result,
		);

		return $this;
	}

	/**
	 * Queues an exception to throw on the next get_redirect_location() call.
	 *
	 * @param TransportException $exception The exception to throw.
	 *
	 * @return self
	 */
	public function will_throw_on_redirect_check( TransportException $exception ): self {
		$this->redirect_queue[] = array(
			'type'  => 'exception',
			'value' => $exception,
		);

		return $this;
	}

	/**
	 * Records the call — including its headers, the credential-isolation
	 * proof test's whole reason for existing — and returns/throws the next
	 * queued outcome.
	 *
	 * @param string                $url             The complete request URL.
	 * @param array<string, string> $headers         Request headers, keyed by header name.
	 * @param int                   $timeout_seconds The request timeout, in seconds.
	 *
	 * @return RedirectResult
	 *
	 * @throws RuntimeException When no outcome is queued.
	 */
	public function get_redirect_location( string $url, array $headers, int $timeout_seconds ): RedirectResult {
		$this->redirect_calls[] = array(
			'url'             => $url,
			'headers'         => $headers,
			'timeout_seconds' => $timeout_seconds,
		);

		if ( array() === $this->redirect_queue ) {
			throw new RuntimeException( 'FakeHttpTransport: no canned result or exception was queued for get_redirect_location().' );
		}

		$next = array_shift( $this->redirect_queue );

		if ( 'exception' === $next['type'] ) {
			throw $next['value'];
		}

		return $next['value'];
	}

	// ---- M6: download() ------------------------------------------------------

	/**
	 * @var array<int, array{type: string, value: DownloadResult|TransportException, contents: string}>
	 */
	private array $download_queue = array();

	/**
	 * @var array<int, array{url: string, destination: string, headers: array<string, string>, timeout_seconds: int, max_bytes: int}>
	 */
	public array $download_calls = array();

	/**
	 * Queues a result to return on the next download() call. When $contents
	 * is non-empty, it is written to the call's $destination path — letting
	 * tests exercise DatabaseManager's extraction/validation steps against
	 * real fixture bytes without a real HTTP request.
	 *
	 * @param DownloadResult $result   The result to return.
	 * @param string         $contents Bytes to write to the destination path, or '' to write nothing.
	 *
	 * @return self
	 */
	public function will_return_download( DownloadResult $result, string $contents = '' ): self {
		$this->download_queue[] = array(
			'type'     => 'result',
			'value'    => $result,
			'contents' => $contents,
		);

		return $this;
	}

	/**
	 * Queues an exception to throw on the next download() call.
	 *
	 * @param TransportException $exception The exception to throw.
	 *
	 * @return self
	 */
	public function will_throw_on_download( TransportException $exception ): self {
		$this->download_queue[] = array(
			'type'     => 'exception',
			'value'    => $exception,
			'contents' => '',
		);

		return $this;
	}

	/**
	 * Records the call — including its (expected-empty) headers, the other
	 * half of the credential-isolation proof test — and returns/throws the
	 * next queued outcome.
	 *
	 * @param string                $url             The complete, already-validated request URL.
	 * @param string                $destination     The absolute filesystem path to stream the response body to.
	 * @param array<string, string> $headers         Request headers, keyed by header name.
	 * @param int                   $timeout_seconds The request timeout, in seconds.
	 * @param int                   $max_bytes       Caps the response body size.
	 *
	 * @return DownloadResult
	 *
	 * @throws RuntimeException When no outcome is queued.
	 */
	public function download( string $url, string $destination, array $headers, int $timeout_seconds, int $max_bytes ): DownloadResult {
		$this->download_calls[] = array(
			'url'             => $url,
			'destination'     => $destination,
			'headers'         => $headers,
			'timeout_seconds' => $timeout_seconds,
			'max_bytes'       => $max_bytes,
		);

		if ( array() === $this->download_queue ) {
			throw new RuntimeException( 'FakeHttpTransport: no canned result or exception was queued for download().' );
		}

		$next = array_shift( $this->download_queue );

		if ( 'exception' === $next['type'] ) {
			throw $next['value'];
		}

		if ( '' !== $next['contents'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test double writing fixture bytes to a tests/tmp path, not production code.
			file_put_contents( $destination, $next['contents'] );
		}

		return $next['value'];
	}
}
