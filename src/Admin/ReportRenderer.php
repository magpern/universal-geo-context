<?php
/**
 * Shared diagnostics definition-list renderer for admin pages.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Renders flat associative arrays from diagnostics reports.
 * No diagnostics logic — display only.
 *
 * @internal
 * @final
 */
final class ReportRenderer {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param DefinitionListRenderer $definition_list Styled definition list renderer.
	 */
	public function __construct(
		private readonly DefinitionListRenderer $definition_list
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
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in DefinitionListRenderer.
		echo $this->definition_list->render( $values );
	}

	/**
	 * Returns rendered definition list markup.
	 *
	 * @param array<string, mixed> $values Scalar or null values only.
	 */
	public function definition_list_html( array $values ): string {
		return $this->definition_list->render( $values );
	}
}
