<?php
/**
 * Untrusted .tar.gz extraction for the managed GeoLite2 database.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\MaxMind;

use Exception;
use PharData;
use RecursiveIteratorIterator;

/**
 * Extracts the single expected `.mmdb` entry from a MaxMind-supplied
 * `.tar.gz` archive, treating the archive as fully untrusted input. Never
 * calls `PharData::extractTo()` — that API extracts by the archive's own
 * internal paths, which is exactly the shape a path-traversal-bearing entry
 * name could abuse. Instead: iterate every entry, match by exact basename
 * only, reject if zero or more than one entry matches, then stream-copy only
 * that single matched entry's bytes to a destination path this class's
 * caller chose — never a path derived from anything inside the archive.
 * This makes path traversal structurally impossible rather than merely
 * filtered.
 *
 * Requires `ext-phar` (`PharData`); guarded via `class_exists()` so a build
 * without it fails cleanly (`ArchiveException::extraction_unsupported()`)
 * rather than fatally.
 *
 * @internal
 * @final
 */
final class ArchiveExtractor {

	/**
	 * The exact filename (basename only, any directory depth) this class
	 * looks for inside the archive — GeoLite2 Country's own database
	 * filename, unchanged across MaxMind's dated wrapper directories.
	 */
	private const EXPECTED_BASENAME = 'GeoLite2-Country.mmdb';

	/**
	 * The default byte cap — see $max_extracted_bytes below. GeoLite2
	 * Country is a few MB; this is a generous multiple, not a tight fit.
	 */
	public const DEFAULT_MAX_EXTRACTED_BYTES = 67108864; // 64 MiB.

	/**
	 * The chunk size used for the streamed copy.
	 */
	private const COPY_CHUNK_BYTES = 8192;

	/**
	 * Caps the number of bytes copied out of the archive, regardless of what
	 * the archive's own (untrusted) size metadata claims — the same
	 * defense-in-depth posture `WordPressHttpTransport::LIMIT_RESPONSE_SIZE`
	 * already applies to the download itself. Injectable (defaulting to
	 * DEFAULT_MAX_EXTRACTED_BYTES) purely for test economy — a real 64 MiB
	 * fixture is wasteful to generate/compress on every test run; production
	 * callers (DatabaseManager) always use the default.
	 *
	 * @var int
	 */
	private readonly int $max_extracted_bytes;

	/**
	 * Stores the injected byte cap, defaulting to DEFAULT_MAX_EXTRACTED_BYTES.
	 *
	 * @param int $max_extracted_bytes Caps the number of bytes copied out of the archive.
	 */
	public function __construct( int $max_extracted_bytes = self::DEFAULT_MAX_EXTRACTED_BYTES ) {
		$this->max_extracted_bytes = $max_extracted_bytes;
	}

	/**
	 * Extracts the single GeoLite2-Country.mmdb entry from $archive_path,
	 * writing it to $destination_path.
	 *
	 * @param string $archive_path     Absolute path to a .tar.gz file on disk. Must end in .tar.gz — PharData's own format-detection requirement.
	 * @param string $destination_path Absolute path to write the extracted database to. Never derived from anything inside the archive.
	 *
	 * @return void
	 *
	 * @throws ArchiveException When the archive is unreadable, malformed, contains no matching entry, contains more than one matching entry, or the matched entry exceeds the byte cap.
	 */
	public function extract_country_database( string $archive_path, string $destination_path ): void {
		if ( ! class_exists( PharData::class ) ) {
			throw ArchiveException::extraction_unsupported();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_file -- reading a file this class's own caller (DatabaseManager) just downloaded to a tmp/ path it controls, not an arbitrary user-supplied path.
		if ( ! is_file( $archive_path ) ) {
			throw ArchiveException::malformed();
		}

		try {
			$phar = new PharData( $archive_path );
		} catch ( Exception $e ) {
			throw ArchiveException::malformed();
		}

		$matches = array();

		try {
			foreach ( new RecursiveIteratorIterator( $phar ) as $file ) {
				if ( self::EXPECTED_BASENAME === basename( (string) $file ) ) {
					$matches[] = $file;
				}
			}
		} catch ( Exception $e ) {
			throw ArchiveException::malformed();
		}

		if ( array() === $matches ) {
			throw ArchiveException::database_absent();
		}

		if ( count( $matches ) > 1 ) {
			throw ArchiveException::ambiguous_candidates();
		}

		$this->stream_copy( (string) $matches[0], $destination_path );
	}

	/**
	 * Streams a single matched phar entry's bytes to $destination_path,
	 * enforcing the byte cap during the copy itself rather than trusting the
	 * entry's own declared size.
	 *
	 * @param string $entry_url        A phar:// stream-wrapper URL identifying the single matched entry.
	 * @param string $destination_path Absolute path to write to.
	 *
	 * @return void
	 *
	 * @throws ArchiveException When the entry cannot be read, or exceeds the byte cap.
	 */
	private function stream_copy( string $entry_url, string $destination_path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading a single already-matched phar:// stream entry; filesystem primitives are confined to MaxMind/ (PrivacyGuardTest rule 9), not to WP_Filesystem specifically.
		$in = fopen( $entry_url, 'rb' );

		if ( false === $in ) {
			throw ArchiveException::malformed();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- writing to a caller-controlled tmp/ destination.
		$out = fopen( $destination_path, 'wb' );

		if ( false === $out ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $in );
			throw ArchiveException::malformed();
		}

		$written = 0;

		// Deliberately not `while ( ! feof( $in ) )`: phar:// stream-wrapped
		// entries are known to report feof() unreliably in both directions —
		// sometimes never becoming true (an infinite loop of empty reads on
		// a short entry), sometimes already true before the first read (a
		// silently truncated-to-empty copy). fread()'s own return value is
		// the only signal this loop trusts.
		while ( true ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk = fread( $in, self::COPY_CHUNK_BYTES );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$written += strlen( $chunk );

			if ( $written > $this->max_extracted_bytes ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $in );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $out );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $destination_path );

				throw ArchiveException::too_large();
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $out, $chunk );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $in );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
	}
}
