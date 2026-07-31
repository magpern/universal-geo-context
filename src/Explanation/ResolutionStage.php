<?php
/**
 * One step in the resolution timeline shown by the Detection Inspector.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Explanation;

/**
 * Observational timeline stage — no resolution side effects.
 *
 * @internal
 * @final
 */
final class ResolutionStage {

	public const STATUS_SUCCESS       = 'success';
	public const STATUS_SKIPPED       = 'skipped';
	public const STATUS_FAILED        = 'failed';
	public const STATUS_NOT_ATTEMPTED = 'not_attempted';
	public const STATUS_CACHED        = 'cached';

	/**
	 * Stores one timeline stage.
	 *
	 * @param string $id     Stable stage identifier.
	 * @param string $label  Human-readable stage name.
	 * @param string $status One of the STATUS_* constants.
	 * @param string $detail Optional explanatory text.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $status,
		public readonly string $detail = ''
	) {
	}
}
