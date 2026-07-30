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
 * M6 adds two further methods, `get_redirect_location()` and `download()`,
 * used exclusively by `MaxMind\DatabaseManager`'s redirect-safe download flow
 * (`docs/ARCHITECTURE_FREEZE.md` §12.3) — never by `ReferenceRemoteProvider`,
 * which keeps using `get()` alone, unchanged. Both new methods remain
 * confined to `WordPressHttpTransport.php` by the same `PrivacyGuardTest`
 * rule 8 that already governs `get()`, since all three call
 * `wp_safe_remote_get()` internally.
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

	/**
	 * Performs a single HTTP GET request with redirect-following disabled,
	 * reporting whether the response was itself a redirect rather than
	 * following it. The first hop of the M6 redirect-safe download flow: the
	 * caller sends this request with credentials attached, inspects the
	 * result, and — only after independently validating the Location value
	 * (never trusted here) — issues a second, separate `download()` call
	 * with no credentials at all.
	 *
	 * @param string                $url             The complete request URL.
	 * @param array<string, string> $headers         Request headers, keyed by header name.
	 * @param int                   $timeout_seconds The request timeout, in seconds. Not clamped to get()'s 1-5 second page-view-latency bound — this call never runs mid-page-view.
	 *
	 * @return RedirectResult
	 *
	 * @throws TransportException When the request could not be completed.
	 */
	public function get_redirect_location( string $url, array $headers, int $timeout_seconds ): RedirectResult;

	/**
	 * Performs a single HTTP GET request, streaming the response body
	 * directly to $destination rather than holding it in memory, with
	 * redirect-following disabled. The second hop of the M6 redirect-safe
	 * download flow — always called with an already-validated URL and
	 * (per that flow's design) an empty $headers array, since credentials
	 * must never reach the redirect target.
	 *
	 * @param string                $url             The complete, already-validated request URL.
	 * @param string                $destination     The absolute filesystem path to stream the response body to.
	 * @param array<string, string> $headers         Request headers, keyed by header name. Empty for the credential-isolation guarantee this flow depends on.
	 * @param int                   $timeout_seconds The request timeout, in seconds.
	 * @param int                   $max_bytes       Caps the response body size; the transport must never write more than this many bytes to $destination.
	 *
	 * @return DownloadResult
	 *
	 * @throws TransportException When the request could not be completed.
	 */
	public function download( string $url, string $destination, array $headers, int $timeout_seconds, int $max_bytes ): DownloadResult;
}
