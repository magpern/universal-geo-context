<?php
/**
 * Immutable result of a streamed-to-disk HTTP download.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers\Remote;

/**
 * The outcome of `HttpTransport::download()` — the second hop of the M6
 * redirect-safe download flow (`docs/ARCHITECTURE_FREEZE.md` §12.3). Carries
 * only the status code and the number of bytes written to the destination
 * file — never the destination path itself, never headers, never a body (the
 * body was streamed straight to disk by the transport, never held in PHP
 * memory as a string).
 *
 * A value object for the same reason `TransportResponse` is one. Joined to
 * `ImmutabilityGuardTest`'s allowlist alongside it.
 *
 * @internal
 * @final
 */
final class DownloadResult {

	/**
	 * Constructs a download result from an already-completed streamed
	 * request.
	 *
	 * @param int $status_code   The HTTP response status code.
	 * @param int $bytes_written The number of bytes written to the destination file.
	 */
	public function __construct(
		public readonly int $status_code,
		public readonly int $bytes_written
	) {
	}
}
