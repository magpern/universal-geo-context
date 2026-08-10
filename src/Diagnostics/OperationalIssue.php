<?php
/**
 * One sanitized operational issue contributing to readiness state.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Diagnostics;

/**
 * Immutable, privacy-safe issue row used by OperationalStatus.
 *
 * @internal
 * @final
 */
final class OperationalIssue {

	/**
	 * Informational overlay (e.g. simulation active).
	 */
	public const SEVERITY_INFO = 'info';

	/**
	 * Recommended attention; does not block consumers.
	 */
	public const SEVERITY_RECOMMENDED = 'recommended';

	/**
	 * Administrator action required for correct operation/trust.
	 */
	public const SEVERITY_CRITICAL = 'critical';

	/**
	 * Stores one issue.
	 *
	 * @param string $code         Stable machine code (snake_case).
	 * @param string $severity     One of the SEVERITY_* constants.
	 * @param string $message      Sanitized human message.
	 * @param string $remediation  Concrete next step for an administrator.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $severity,
		public readonly string $message,
		public readonly string $remediation
	) {
	}

	/**
	 * Exports this issue as a plain array.
	 *
	 * @return array{code: string, severity: string, message: string, remediation: string}
	 */
	public function to_array(): array {
		return array(
			'code'        => $this->code,
			'severity'    => $this->severity,
			'message'     => $this->message,
			'remediation' => $this->remediation,
		);
	}
}
