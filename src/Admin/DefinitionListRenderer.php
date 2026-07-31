<?php
/**
 * Styled definition-list renderer for admin cards.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Diagnostics\DiagnosticsService;

/**
 * Renders flat associative arrays as design-system definition lists.
 *
 * @internal
 * @final
 */
final class DefinitionListRenderer {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param DiagnosticsService $diagnostics Field label translations.
	 */
	public function __construct(
		private readonly DiagnosticsService $diagnostics
	) {
	}

	/**
	 * Renders one flat associative array as a definition list.
	 *
	 * @param array<string, mixed> $values Scalar or null values only.
	 *
	 * @return string
	 */
	public function render( array $values ): string {
		if ( array() === $values ) {
			return '';
		}

		$labels = $this->diagnostics->field_labels();
		$rows   = '';

		foreach ( $values as $key => $value ) {
			if ( is_bool( $value ) ) {
				$display = $value ? __( 'yes', 'universal-geo-context' ) : __( 'no', 'universal-geo-context' );
			} elseif ( is_array( $value ) ) {
				$display = implode( ', ', $value );
			} elseif ( null === $value ) {
				$display = __( 'n/a', 'universal-geo-context' );
			} else {
				$display = (string) $value;
			}

			$label = $labels[ (string) $key ] ?? (string) $key;

			$rows .= sprintf(
				'<div class="ugc-ui-dl__row"><dt class="ugc-ui-dl__term">%1$s</dt><dd class="ugc-ui-dl__value">%2$s</dd></div>',
				esc_html( $label ),
				esc_html( $display )
			);
		}

		return sprintf( '<dl class="ugc-ui-dl">%s</dl>', $rows );
	}
}
