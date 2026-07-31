<?php
/**
 * In-plugin page navigation for Universal Geo Context admin screens.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Renders keyboard-accessible, no-JS navigation tabs below the page title.
 *
 * @internal
 * @final
 */
final class AdminNavigationRenderer {

	/**
	 * Renders the global page navigation for the active screen.
	 *
	 * @param string $active_slug Current page slug.
	 *
	 * @return void
	 */
	public function render( string $active_slug ): void {
		echo '<nav class="nav-tab-wrapper universal-geo-admin-nav" aria-label="' . \esc_attr__( 'Universal Geo Context pages', 'universal-geo-context' ) . '">';

		foreach ( AdminPageRegistry::navigation_items() as $item ) {
			$slug    = $item['slug'];
			$active  = $slug === $active_slug ? ' nav-tab-active' : '';
			$current = $slug === $active_slug ? ' aria-current="page"' : '';

			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				\esc_url( AdminPageRegistry::page_url( $slug ) ),
				\esc_attr( $active ),
				$current, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute fragment.
				\esc_html( $item['label'] )
			);
		}

		echo '</nav>';
	}
}
