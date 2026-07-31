<?php
/**
 * Renders the Universal Geo Context admin page shell chrome.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Admin\ViewModel\AdminPageShellViewModel;

/**
 * Outputs the branded header card and icon navigation.
 *
 * @internal
 * @final
 */
final class AdminPageShell {

	/**
	 * Icon navigation renderer.
	 *
	 * @param SectionNavigation $navigation Icon navigation renderer.
	 */
	public function __construct(
		private readonly SectionNavigation $navigation
	) {
	}

	/**
	 * Opens the admin page wrap and WordPress notice anchor.
	 *
	 * Core common.js moves global admin notices after `.wp-header-end`, or
	 * otherwise after the first `.wrap h1`/`.wrap h2` — which would land
	 * them inside the branded shell header.
	 */
	public function open_wrap(): void {
		echo '<div class="wrap">';
		echo '<hr class="wp-header-end">';
	}

	/**
	 * Closes the admin page wrap opened by {@see open_wrap()}.
	 */
	public function close_wrap(): void {
		echo '</div>';
	}

	/**
	 * Opens the page shell wrapper.
	 */
	public function open(): void {
		echo '<div class="ugc-settings-shell">';
	}

	/**
	 * Closes the page shell wrapper.
	 */
	public function close(): void {
		echo '</div>';
	}

	/**
	 * Opens the readable content layout region.
	 *
	 * @param bool $wide When true, use wide layout without max-width.
	 */
	public function open_content( bool $wide = false ): void {
		$class = $wide ? 'ugc-ui-layout--wide' : 'ugc-ui-layout--readable';
		printf( '<div class="%s">', esc_attr( $class ) );
	}

	/**
	 * Closes the content layout region.
	 */
	public function close_content(): void {
		echo '</div>';
	}

	/**
	 * Renders the page shell header above page content.
	 *
	 * @param AdminPageShellViewModel $view_model Shell presentation data.
	 * @param callable|null           $actions    Optional callback that echoes contextual actions.
	 */
	public function render_header( AdminPageShellViewModel $view_model, ?callable $actions = null ): void {
		?>
		<header class="ugc-shell-header">
			<div class="ugc-shell-header__main">
				<div class="ugc-shell-header__brand">
					<div class="ugc-shell-header__mark" aria-hidden="true">
						<span class="ugc-shell-header__mark-icon dashicons <?php echo esc_attr( AdminPageShellViewModelFactory::plugin_mark_icon() ); ?>"></span>
					</div>
					<div class="ugc-shell-header__titles">
						<h2 class="ugc-shell-header__title"><?php echo esc_html( $view_model->plugin_title ); ?></h2>
						<p class="ugc-shell-header__subtitle"><?php echo esc_html( $view_model->subtitle ); ?></p>
					</div>
				</div>

				<?php $this->navigation->render( $view_model ); ?>

				<?php if ( null !== $actions ) : ?>
					<div class="ugc-shell-header__actions ugc-ui-shell-actions">
						<?php $actions(); ?>
					</div>
				<?php elseif ( $view_model->has_header_save ) : ?>
					<div class="ugc-shell-header__actions">
						<button type="submit" name="save" value="<?php echo \esc_attr__( 'Save changes', 'universal-geo-context' ); ?>" class="button button-primary ugc-shell-header__save">
							<?php \esc_html_e( 'Save changes', 'universal-geo-context' ); ?>
						</button>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $view_model->notice_html ) : ?>
				<div class="ugc-shell-header__notice">
					<?php echo $view_model->notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at source. ?>
				</div>
			<?php endif; ?>
		</header>
		<?php
	}

	/**
	 * Opens the active section content card.
	 *
	 * @param string $title       Section title.
	 * @param string $description Section description.
	 * @param string $icon_class  Optional dashicon class.
	 */
	public function open_section_card( string $title, string $description = '', string $icon_class = 'dashicons-admin-generic' ): void {
		$description_html = '' !== $description
			? sprintf( '<p class="ugc-section-card__description">%s</p>', esc_html( $description ) )
			: '';
		?>
		<div class="ugc-section-card">
			<header class="ugc-section-card__header">
				<div class="ugc-section-card__icon-wrap" aria-hidden="true">
					<span class="ugc-section-card__icon dashicons <?php echo esc_attr( $icon_class ); ?>"></span>
				</div>
				<div class="ugc-section-card__heading">
					<h2 class="ugc-section-card__title"><?php echo esc_html( $title ); ?></h2>
					<?php echo $description_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
				</div>
			</header>
			<div class="ugc-section-card__body">
		<?php
	}

	/**
	 * Closes the active section content card.
	 */
	public function close_section_card(): void {
		?>
			</div>
		</div>
		<?php
	}
}
