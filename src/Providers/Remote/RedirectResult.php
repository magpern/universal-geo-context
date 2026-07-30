<?php
/**
 * Immutable result of a redirect-detection-only HTTP request.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers\Remote;

/**
 * The outcome of `HttpTransport::get_redirect_location()` — the first hop of
 * the M6 redirect-safe download flow (`docs/ARCHITECTURE_FREEZE.md` §12.3).
 * Carries only what the caller needs to decide whether a redirect occurred
 * and, if so, where to (validated separately, by `MaxMind\RedirectValidator`,
 * never here): never a body, never other headers, never request metadata.
 *
 * A value object for the same reason `TransportResponse` is one: a fixed,
 * typed, immutable shape crossing the transport/caller boundary. Joined to
 * `ImmutabilityGuardTest`'s allowlist alongside it.
 *
 * @internal
 * @final
 */
final class RedirectResult {

	/**
	 * Constructs a redirect-detection result from an already-completed,
	 * non-followed HTTP request.
	 *
	 * @param bool        $is_redirect Whether the response was a 3xx with a Location header.
	 * @param string|null $location    The raw Location header value, or null when not a redirect.
	 * @param int         $status_code The HTTP response status code.
	 */
	public function __construct(
		public readonly bool $is_redirect,
		public readonly ?string $location,
		public readonly int $status_code
	) {
	}
}
