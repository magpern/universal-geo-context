<?php
/**
 * Resolution timeline presentation component.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Explanation\ExplanationFormatter;
use UniversalGeo\Explanation\ResolutionStage;

/**
 * Renders a polished resolution timeline from explanation stages.
 *
 * @internal
 * @final
 */
final class TimelineRenderer {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param AdminComponentRenderer $components Status badges.
	 * @param ExplanationFormatter   $formatter  Timeline status labels.
	 */
	public function __construct(
		private readonly AdminComponentRenderer $components,
		private readonly ExplanationFormatter $formatter
	) {
	}

	/**
	 * Renders the timeline as an ordered list with status badges.
	 *
	 * @param list<ResolutionStage> $stages Timeline stages.
	 *
	 * @return string
	 */
	public function render( array $stages ): string {
		if ( array() === $stages ) {
			return '';
		}

		$items = '';

		foreach ( $stages as $index => $stage ) {
			if ( ! $stage instanceof ResolutionStage ) {
				continue;
			}

			$variant = $this->badge_variant_for_status( $stage->status );
			$badge   = $this->components->status_badge(
				$this->formatter->timeline_status_label( $stage->status ),
				$variant
			);

			$detail = '' !== $stage->detail
				? sprintf( '<p class="ugc-ui-timeline__detail">%s</p>', esc_html( $stage->detail ) )
				: '';

			$items .= sprintf(
				'<li class="ugc-ui-timeline__item"><div class="ugc-ui-timeline__marker" aria-hidden="true"><span class="ugc-ui-timeline__step">%1$d</span></div><div class="ugc-ui-timeline__content">%2$s<h5 class="ugc-ui-timeline__title">%3$s</h5>%4$s</div></li>',
				$index + 1,
				$badge,
				esc_html( $stage->label ),
				$detail
			);
		}

		return sprintf(
			'<ol class="ugc-ui-timeline" aria-label="%s">%s</ol>',
			esc_attr__( 'Resolution timeline', 'universal-geo-context' ),
			$items
		);
	}

	/**
	 * Maps timeline status to badge variant.
	 *
	 * @param string $status Timeline stage status.
	 */
	private function badge_variant_for_status( string $status ): string {
		return match ( $this->formatter->timeline_status_class( $status ) ) {
			'success' => 'active',
			'warning' => 'warning',
			'error'   => 'error',
			'skipped' => 'disabled',
			default   => 'disabled',
		};
	}
}
