<?php
/**
 * Shared admin action controls (forms and links).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Reusable presentation for existing POST handlers — no new backend behaviour.
 *
 * @internal
 * @final
 */
final class AdminActionRenderer {

	/**
	 * Renders a standard admin link styled as a button.
	 *
	 * @param string $url   Destination URL.
	 * @param string $label Accessible link label.
	 * @param string $button_class Button class list.
	 *
	 * @return void
	 */
	public function render_link_button( string $url, string $label, string $button_class = 'button button-secondary' ): void {
		printf(
			'<a class="%1$s" href="%2$s">%3$s</a>',
			\esc_attr( $button_class ),
			\esc_url( $url ),
			\esc_html( $label )
		);
	}

	/**
	 * Renders the shared Refresh provider diagnostics POST form.
	 *
	 * @param string $redirect_slug Page slug for PRG redirect target.
	 * @param string $button_label  Submit button text.
	 * @param string $button_class  WP submit button class suffix.
	 *
	 * @return void
	 */
	public function render_refresh_providers_form(
		string $redirect_slug,
		string $button_label = '',
		string $button_class = 'secondary'
	): void {
		if ( '' === $button_label ) {
			$button_label = __( 'Refresh provider diagnostics', 'universal-geo-context' );
		}

		echo '<form method="post" action="' . \esc_url( admin_url( 'admin-post.php' ) ) . '" class="universal-geo-inline-form ugc-ui-inline-form">';
		wp_nonce_field( 'universal_geo_refresh_providers' );
		echo '<input type="hidden" name="action" value="universal_geo_refresh_providers" />';
		echo '<input type="hidden" name="universal_geo_redirect_page" value="' . \esc_attr( $redirect_slug ) . '" />';
		submit_button( $button_label, $button_class, 'submit', false );
		echo '</form>';
	}
}
