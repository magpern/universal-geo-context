<?php
/**
 * Shared diagnostics definition-list renderer for admin pages.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Diagnostics\DiagnosticsService;

/**
 * Renders flat associative arrays from diagnostics reports as `<dl>` lists.
 * No diagnostics logic — display only.
 *
 * @internal
 * @final
 */
final class ReportRenderer {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param DiagnosticsService $diagnostics Supplies field label translations.
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
	 * @return void
	 */
	public function render_definition_list( array $values ): void {
		$labels = $this->diagnostics->field_labels();

		echo '<dl>';

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

			printf( '<dt>%1$s</dt><dd>%2$s</dd>', esc_html( $label ), esc_html( $display ) );
		}

		echo '</dl>';
	}
}
