<?php
/**
 * Bounded exception for managed-database structural validation failures.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\MaxMind;

use RuntimeException;

/**
 * Thrown by `DatabaseManager`'s candidate/installed-file validation step.
 * Every message is a fixed, static, safe string — never raw filesystem
 * paths, never reader-library exception text (which could echo the file's
 * own path back) — since this exception's message may reach
 * `DatabaseUpdateResult::message`, diagnostics, and admin notices, exactly
 * like `ArchiveException`'s own scrubbing rule.
 *
 * @internal
 * @final
 */
final class DatabaseValidationException extends RuntimeException {

	/**
	 * The candidate file is empty or outside plausible size bounds.
	 *
	 * @return self
	 */
	public static function unreadable(): self {
		return new self( 'The database file is empty or unreadable.' );
	}

	/**
	 * MaxMind\Db\Reader could not open the candidate file.
	 *
	 * @return self
	 */
	public static function unopenable(): self {
		return new self( 'The database file could not be opened as a valid MaxMind database.' );
	}

	/**
	 * The opened database's own metadata does not identify it as a Country
	 * edition.
	 *
	 * @return self
	 */
	public static function wrong_edition(): self {
		return new self( 'The database is not a GeoLite2 Country edition.' );
	}

	/**
	 * The opened database's metadata (build epoch) is not plausible — zero,
	 * negative, or in the future.
	 *
	 * @return self
	 */
	public static function implausible_metadata(): self {
		return new self( 'The database metadata is not plausible.' );
	}

	/**
	 * A lookup against the fixed smoke-test IP threw — a database that
	 * opens and reports plausible metadata but cannot actually be queried.
	 *
	 * @return self
	 */
	public static function lookup_threw(): self {
		return new self( 'A lookup against the database failed unexpectedly.' );
	}
}
