<?php
/**
 * Immutable outcome of a DatabaseManager action.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\MaxMind;

/**
 * Returned by every `DatabaseManager` action method (`download_now()`,
 * `remove_managed_database()`, `restore_previous()`). `message` is always a
 * fixed, safe, human-readable string — never a raw filesystem path, never a
 * redirect URL, never reader-library exception text — the same redaction
 * discipline `ArchiveException`/`DatabaseValidationException` themselves
 * already enforce at the point their messages are constructed.
 *
 * A value object for the same reason `TransportResponse` is one. Joined to
 * `ImmutabilityGuardTest`'s allowlist.
 *
 * @internal
 * @final
 */
final class DatabaseUpdateResult {

	/**
	 * Constructs a result.
	 *
	 * @param bool   $success Whether the action fully succeeded.
	 * @param string $code    A short, stable, machine-readable code (e.g. 'ok', 'credentials_missing', 'already_running').
	 * @param string $message A fixed, human-readable, safe-to-display message.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $code,
		public readonly string $message
	) {
	}

	/**
	 * Builds a successful result.
	 *
	 * @param string $code    A short, stable, machine-readable success code.
	 * @param string $message A fixed, human-readable, safe-to-display message.
	 *
	 * @return self
	 */
	public static function success( string $code, string $message ): self {
		return new self( true, $code, $message );
	}

	/**
	 * Builds a failed result.
	 *
	 * @param string $code    A short, stable, machine-readable failure code.
	 * @param string $message A fixed, human-readable, safe-to-display message.
	 *
	 * @return self
	 */
	public static function failure( string $code, string $message ): self {
		return new self( false, $code, $message );
	}
}
