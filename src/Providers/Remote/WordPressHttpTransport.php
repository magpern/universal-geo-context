<?php
/**
 * The production HttpTransport implementation, backed by wp_safe_remote_get().
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers\Remote;

/**
 * The only production file in the entire plugin permitted to call
 * `wp_safe_remote_get()` (`PrivacyGuardTest` rule 8, M4) — every other
 * remote-HTTP primitive (`wp_remote_get`, `wp_remote_post`,
 * `wp_remote_request`, `curl_init`) remains forbidden everywhere, including
 * in this file, by `PrivacyGuardTest` rule 6 unchanged. Transport-only:
 * builds the WordPress HTTP args, executes the request, and converts the
 * result into either a `TransportResponse` or a scrubbed
 * `TransportException` — it never builds the request URL, never adds
 * authentication, never classifies status codes, and never parses the body.
 * Those remain `ReferenceRemoteProvider`'s job, per the frozen "provider
 * classifies, transport only transports" split.
 *
 * @internal
 * @final
 */
final class WordPressHttpTransport implements HttpTransport {

	/**
	 * Redirects are always disabled: a remote provider following a redirect
	 * could leak the visitor IP (carried in the request itself, not a
	 * parameter this class controls) to an unintended, uncontrolled host.
	 */
	private const REDIRECTION = 0;

	/**
	 * Caps the response body WordPress will read into memory, regardless of
	 * a misbehaving or malicious upstream declaring a larger Content-Length.
	 */
	private const LIMIT_RESPONSE_SIZE = 16384;

	/**
	 * Bounds an arbitrary caller-supplied timeout into a sane range — a
	 * request-level bound this class enforces itself, never trusting the
	 * caller to have already done so.
	 */
	private const MIN_TIMEOUT_SECONDS = 1;
	private const MAX_TIMEOUT_SECONDS = 30;

	/**
	 * Executes a single outbound GET request.
	 *
	 * @param string                $url             The complete request URL.
	 * @param array<string, string> $headers         Request headers, keyed by header name.
	 * @param int                   $timeout_seconds The request timeout, in seconds (sanitized here into a sane bound).
	 *
	 * @return TransportResponse
	 *
	 * @throws TransportException When wp_safe_remote_get() returns a WP_Error.
	 */
	public function get( string $url, array $headers, int $timeout_seconds ): TransportResponse {
		$response = wp_safe_remote_get(
			$url,
			array(
				'redirection'         => self::REDIRECTION,
				'limit_response_size' => self::LIMIT_RESPONSE_SIZE,
				'timeout'             => $this->sanitized_timeout( $timeout_seconds ),
				'headers'             => $headers,
				'user-agent'          => 'Universal Geo Context/' . UNIVERSAL_GEO_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- TransportException::scrubbed() is this codebase's own scrubbing/truncation boundary for exception text (mirrors ProviderHealthStore's scrub()), not user-facing output.
			throw TransportException::scrubbed( $response->get_error_message(), $url );
		}

		return new TransportResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response )
		);
	}

	/**
	 * Clamps the requested timeout into [MIN_TIMEOUT_SECONDS, MAX_TIMEOUT_SECONDS].
	 *
	 * @param int $timeout_seconds The caller-supplied timeout.
	 *
	 * @return int
	 */
	private function sanitized_timeout( int $timeout_seconds ): int {
		return max( self::MIN_TIMEOUT_SECONDS, min( self::MAX_TIMEOUT_SECONDS, $timeout_seconds ) );
	}
}
