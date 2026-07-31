<?php
/**
 * Reusable admin page header (title, description, navigation, actions).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Consistent page header for every Universal Geo Context admin screen.
 *
 * @internal
 * @final
 */
final class AdminHeaderRenderer {

	/**
	 * Stores the navigation renderer.
	 *
	 * @param AdminNavigationRenderer $navigation In-plugin tab navigation.
	 */
	public function __construct(
		private readonly AdminNavigationRenderer $navigation
	) {
	}

	/**
	 * Renders the page header block.
	 *
	 * @param string        $active_slug Active page slug.
	 * @param string        $title       Page title (h1).
	 * @param callable|null $actions     Optional callback that echoes contextual actions.
	 *
	 * @return void
	 */
	public function render( string $active_slug, string $title, ?callable $actions = null ): void {
		$description = AdminPageRegistry::description( $active_slug );

		echo '<header class="universal-geo-admin-header">';
		echo '<h1>' . \esc_html( $title ) . '</h1>';

		if ( '' !== $description ) {
			printf( '<p class="description">%s</p>', \esc_html( $description ) );
		}

		$this->navigation->render( $active_slug );

		if ( null !== $actions ) {
			echo '<div class="universal-geo-admin-actions">';
			$actions();
			echo '</div>';
		}

		echo '</header>';
	}
}
