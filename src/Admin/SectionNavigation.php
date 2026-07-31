<?php
/**
 * Renders the Universal Geo Context icon navigation.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Admin\ViewModel\AdminPageShellViewModel;
use UniversalGeo\Admin\ViewModel\SectionNavItemViewModel;

/**
 * Outputs the segmented section navigation in the shell header.
 *
 * @internal
 * @final
 */
final class SectionNavigation {

	/**
	 * Renders the navigation card.
	 *
	 * @param AdminPageShellViewModel $view_model Shell presentation data.
	 */
	public function render( AdminPageShellViewModel $view_model ): void {
		?>
		<nav class="ugc-shell-nav" aria-label="<?php echo \esc_attr( \__( 'Universal Geo Context pages', 'universal-geo-context' ) ); ?>">
			<ul class="ugc-shell-nav__list">
				<?php foreach ( $view_model->navigation_items as $item ) : ?>
					<?php $this->render_item( $item ); ?>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Renders one navigation item.
	 *
	 * @param SectionNavItemViewModel $item Navigation item view model.
	 */
	private function render_item( SectionNavItemViewModel $item ): void {
		$classes = array( 'ugc-shell-nav__item' );

		if ( $item->is_active ) {
			$classes[] = 'ugc-shell-nav__item--active';
		}

		$current = $item->is_active ? ' aria-current="page"' : '';
		?>
		<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<a class="ugc-shell-nav__link" href="<?php echo esc_url( $item->url ); ?>"<?php echo $current; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute name. ?>>
				<span class="ugc-shell-nav__icon dashicons <?php echo esc_attr( $item->icon_class ); ?>" aria-hidden="true"></span>
				<span class="ugc-shell-nav__label"><?php echo esc_html( $item->label ); ?></span>
			</a>
		</li>
		<?php
	}
}
