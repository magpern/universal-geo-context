<?php
/**
 * Providers admin page (placeholder content in M7).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Informational placeholder until M9 populated provider inspection.
 *
 * @internal
 * @final
 */
final class ProvidersPage implements Page {

	/**
	 * Returns the page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return AdminPageSlugs::PROVIDERS;
	}

	/**
	 * Returns the page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Providers', 'universal-geo-context' );
	}

	/**
	 * Returns the submenu label.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Providers', 'universal-geo-context' );
	}

	/**
	 * Returns the required capability.
	 *
	 * @return string
	 */
	public function capability(): string {
		return 'manage_options';
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $this->title() ) . '</h1>';
		printf(
			'<div class="card"><p>%s</p></div>',
			esc_html__(
				'Detailed provider inspection is planned for v1.4.0. Until then, use the Overview dashboard for a summary and the Diagnostics page for the full report.',
				'universal-geo-context'
			)
		);
		echo '</div>';
	}
}
