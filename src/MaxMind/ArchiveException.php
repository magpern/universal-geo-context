<?php
/**
 * Bounded exception for untrusted-archive handling failures.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\MaxMind;

use RuntimeException;

/**
 * Thrown by `ArchiveExtractor::extract_country_database()`. Every message is
 * a fixed, static, safe string — never raw archive content, never a raw
 * filesystem path — since this exception's message may reach
 * `DatabaseUpdateResult::message`, diagnostics, and admin notices, exactly
 * like `TransportException`'s own scrubbing rule for the transport layer.
 *
 * @internal
 * @final
 */
final class ArchiveException extends RuntimeException {

	/**
	 * The archive (or a single entry within it) exceeded the byte cap.
	 *
	 * @return self
	 */
	public static function too_large(): self {
		return new self( 'The archive exceeded the maximum allowed size.' );
	}

	/**
	 * The archive could not be opened or read as a valid .tar.gz — a
	 * corrupted download, an unexpected format, or any other PharData
	 * failure.
	 *
	 * @return self
	 */
	public static function malformed(): self {
		return new self( 'The archive could not be read as a valid .tar.gz file.' );
	}

	/**
	 * No entry in the archive matched the expected database filename.
	 *
	 * @return self
	 */
	public static function database_absent(): self {
		return new self( 'The archive did not contain the expected database file.' );
	}

	/**
	 * More than one entry in the archive matched the expected database
	 * filename — rejected rather than guessing which one is authoritative.
	 *
	 * @return self
	 */
	public static function ambiguous_candidates(): self {
		return new self( 'The archive contained more than one matching database file.' );
	}

	/**
	 * The PharData class is unavailable in this PHP build (ext-phar
	 * disabled) — a soft-dependency failure, not a malformed archive.
	 *
	 * @return self
	 */
	public static function extraction_unsupported(): self {
		return new self( 'Archive extraction requires the PHP phar extension, which is unavailable.' );
	}
}
