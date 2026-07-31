<?php
/**
 * Formats explanation models for admin display.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Explanation;

/**
 * Presentation helpers for timeline badges and provider status labels.
 *
 * @internal
 * @final
 */
final class ExplanationFormatter {

	/**
	 * Returns a translated label for one timeline status.
	 *
	 * @param string $status One of ResolutionStage::STATUS_*.
	 *
	 * @return string
	 */
	public function timeline_status_label( string $status ): string {
		return match ( $status ) {
			ResolutionStage::STATUS_SUCCESS       => __( 'Success', 'universal-geo-context' ),
			ResolutionStage::STATUS_SKIPPED       => __( 'Skipped', 'universal-geo-context' ),
			ResolutionStage::STATUS_FAILED        => __( 'Failed', 'universal-geo-context' ),
			ResolutionStage::STATUS_NOT_ATTEMPTED => __( 'Not attempted', 'universal-geo-context' ),
			ResolutionStage::STATUS_CACHED        => __( 'Cached', 'universal-geo-context' ),
			default                               => $status,
		};
	}

	/**
	 * Returns a CSS class suffix for one timeline status badge.
	 *
	 * @param string $status One of ResolutionStage::STATUS_*.
	 *
	 * @return string
	 */
	public function timeline_status_class( string $status ): string {
		return match ( $status ) {
			ResolutionStage::STATUS_SUCCESS       => 'success',
			ResolutionStage::STATUS_SKIPPED       => 'skipped',
			ResolutionStage::STATUS_FAILED        => 'failed',
			ResolutionStage::STATUS_NOT_ATTEMPTED => 'not-attempted',
			ResolutionStage::STATUS_CACHED        => 'cached',
			default                               => 'neutral',
		};
	}

	/**
	 * Returns a translated skipped-reason label.
	 *
	 * @param string $reason Internal skipped reason code.
	 *
	 * @return string
	 */
	public function skipped_reason_label( string $reason ): string {
		if ( '' === $reason ) {
			return '';
		}

		return match ( $reason ) {
			'unavailable'       => __( 'Unavailable', 'universal-geo-context' ),
			'miss'              => __( 'Miss', 'universal-geo-context' ),
			'failed'            => __( 'Failed', 'universal-geo-context' ),
			'invalid_country'   => __( 'Invalid country', 'universal-geo-context' ),
			'short_circuit'     => __( 'Not attempted (earlier provider won)', 'universal-geo-context' ),
			'served_from_cache' => __( 'Not attempted (served from cache)', 'universal-geo-context' ),
			default             => $reason,
		};
	}
}
