<?php
/**
 * Internal HTTP transport seam for the remote provider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers\Remote;

/**
 * A one-method internal contract separating "how to make an HTTP GET
 * request" (transport) from "what URL, what auth, what to do with the
 * result" (provider) — the frozen M4 responsibility split. Deliberately
 * placed under the internal `Providers\Remote` namespace, not
 * `src/Contracts/`: the frozen public-contracts directory holds only
 * `ClientIpResolverInterface` and `GeoProviderInterface` (`docs/API.md`'s
 * "only these types are stable" list), and this interface is not one of
 * them — no third party is meant to implement or consume it.
 *
 * `WordPressHttpTransport` is the only production implementation and the
 * only production file permitted to call `wp_safe_remote_get()`
 * (`PrivacyGuardTest` rule 8); `FakeHttpTransport` is the test double every
 * `ReferenceRemoteProvider` unit test uses instead.
 *
 * @internal
 */
interface HttpTransport {

	/**
	 * Performs a single HTTP GET request. No retries: exactly one attempt,
	 * exactly one outcome, either a response or a thrown exception.
	 *
	 * @param string                $url             The complete request URL.
	 * @param array<string, string> $headers         Request headers, keyed by header name.
	 * @param int                   $timeout_seconds The request timeout, in seconds.
	 *
	 * @return TransportResponse
	 *
	 * @throws TransportException When the request could not be completed (network failure, DNS failure, or any other transport-level error).
	 */
	public function get( string $url, array $headers, int $timeout_seconds ): TransportResponse;
}
