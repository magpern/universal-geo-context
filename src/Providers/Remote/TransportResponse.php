<?php
/**
 * Immutable HTTP transport result.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers\Remote;

/**
 * A completed HTTP request's status code and body, nothing else — no
 * headers, no request metadata, no reference to the URL that produced it.
 * `WordPressHttpTransport` is the only class that constructs one;
 * `ReferenceRemoteProvider` is the only class that reads one, classifying
 * the status code and parsing the body itself (the frozen "provider
 * classifies, transport only transports" split).
 *
 * A value object for the same reason `MaxMindMetadata` is one: a fixed,
 * typed, immutable shape crossing a class boundary. Joined to
 * `ImmutabilityGuardTest`'s allowlist alongside it (M4).
 *
 * @internal
 * @final
 */
final class TransportResponse {

	/**
	 * Constructs a response from an already-completed HTTP request.
	 *
	 * @param int    $status_code The HTTP response status code.
	 * @param string $body        The raw response body, already size-capped by the transport.
	 */
	public function __construct(
		public readonly int $status_code,
		public readonly string $body
	) {
	}
}
