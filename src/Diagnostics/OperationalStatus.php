<?php
/**
 * Immutable operational readiness snapshot for Universal Geo Context.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Diagnostics;

/**
 * Answers whether WordPress / another plugin can safely rely on geographic
 * context right now. Distinct from Site Health's good/recommended/critical
 * vocabulary: this is the plugin's own readiness model.
 *
 * `state` and `consumer_usable` are independent dimensions — e.g.
 * `action_required` with `consumer_usable=true` is intentional when
 * resolution still works but an administrator must fix configuration.
 *
 * @internal
 * @final
 */
final class OperationalStatus {

	/**
	 * Operating as intended.
	 */
	public const STATE_READY = 'ready';

	/**
	 * Usable, but below preferred capability.
	 */
	public const STATE_DEGRADED = 'degraded';

	/**
	 * Callable, but an administrator must correct a configuration issue.
	 */
	public const STATE_ACTION_REQUIRED = 'action_required';

	/**
	 * Cannot be relied upon (catastrophic / trust-breaking).
	 */
	public const STATE_UNAVAILABLE = 'unavailable';

	/**
	 * Allowed state values.
	 *
	 * @var list<string>
	 */
	private const STATES = array(
		self::STATE_READY,
		self::STATE_DEGRADED,
		self::STATE_ACTION_REQUIRED,
		self::STATE_UNAVAILABLE,
	);

	/**
	 * Sanitized contributing issues for this snapshot.
	 *
	 * @var OperationalIssue[]
	 */
	public readonly array $issues;

	/**
	 * Stores one readiness snapshot.
	 *
	 * @param string             $state             One of the STATE_* constants.
	 * @param bool               $consumer_usable   Whether downstream consumers can rely on the public API path.
	 * @param bool               $simulation_active Whether administrator simulation is overriding context.
	 * @param OperationalIssue[] $issues            Sanitized contributing issues.
	 * @param string             $summary           Short operator-facing summary.
	 *
	 * @throws \InvalidArgumentException When state or issues are malformed.
	 */
	public function __construct(
		public readonly string $state,
		public readonly bool $consumer_usable,
		public readonly bool $simulation_active,
		array $issues,
		public readonly string $summary
	) {
		if ( ! in_array( $state, self::STATES, true ) ) {
			throw new \InvalidArgumentException( 'OperationalStatus state is invalid.' );
		}

		foreach ( $issues as $issue ) {
			if ( ! $issue instanceof OperationalIssue ) {
				throw new \InvalidArgumentException( 'OperationalStatus issues must be OperationalIssue instances.' );
			}
		}

		$this->issues = array_values( $issues );
	}

	/**
	 * Exports this status as a plain array for CLI/admin presenters.
	 *
	 * @return array{
	 *     state: string,
	 *     consumer_usable: bool,
	 *     simulation_active: bool,
	 *     summary: string,
	 *     issues: list<array{code: string, severity: string, message: string, remediation: string}>
	 * }
	 */
	public function to_array(): array {
		return array(
			'state'             => $this->state,
			'consumer_usable'   => $this->consumer_usable,
			'simulation_active' => $this->simulation_active,
			'summary'           => $this->summary,
			'issues'            => array_map(
				static fn ( OperationalIssue $issue ): array => $issue->to_array(),
				$this->issues
			),
		);
	}
}
