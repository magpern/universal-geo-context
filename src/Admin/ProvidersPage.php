<?php
/**
 * Providers admin page.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Explanation\DetectionInspectorService;

/**
 * Per-provider diagnostic inspection (M9).
 *
 * @internal
 * @final
 */
final class ProvidersPage implements Page {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param DetectionInspectorService $inspector Provider detail supplier.
	 * @param ReportRenderer            $renderer  Definition-list renderer.
	 */
	public function __construct(
		private readonly DetectionInspectorService $inspector,
		private readonly ReportRenderer $renderer
	) {
	}

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

		$refresh_summary = AdminProbeFreshFlag::summary();
		$details         = $this->inspector->provider_details( $refresh_summary );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $this->title() ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Observational provider diagnostics. Credentials are never shown. Run Refresh now to run one live probe.', 'universal-geo-context' ) . '</p>';

		if ( null !== $refresh_summary ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: ok count, 2: total providers */
						__( 'Last explicit refresh: %1$d of %2$d providers returned a country.', 'universal-geo-context' ),
						(int) $refresh_summary['ok_count'],
						(int) $refresh_summary['total']
					)
				)
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin: 1em 0;">';
		wp_nonce_field( 'universal_geo_refresh_providers' );
		echo '<input type="hidden" name="action" value="universal_geo_refresh_providers" />';
		echo '<input type="hidden" name="universal_geo_redirect_page" value="' . esc_attr( $this->slug() ) . '" />';
		submit_button( __( 'Refresh provider diagnostics', 'universal-geo-context' ), 'secondary', 'submit', false );
		echo '</form>';

		foreach ( $details as $provider_id => $section ) {
			echo '<div class="postbox" style="max-width:960px;margin-top:1.5em;"><div class="postbox-header"><h2 class="hndle">';
			echo esc_html( ucfirst( (string) $provider_id ) );
			echo '</h2></div><div class="inside">';

			$this->renderer->render_definition_list( $section );

			$settings_url = $this->settings_url_for_provider( (string) $provider_id );
			if ( null !== $settings_url ) {
				printf(
					'<p><a class="button button-secondary" href="%1$s">%2$s</a></p>',
					esc_url( $settings_url ),
					esc_html__( 'Open related settings', 'universal-geo-context' )
				);
			}

			echo '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * Returns a related settings URL for one provider.
	 *
	 * @param string $provider_id Provider identifier.
	 *
	 * @return string|null
	 */
	private function settings_url_for_provider( string $provider_id ): ?string {
		return match ( $provider_id ) {
			'cloudflare', 'maxmind', 'remote', 'default' => admin_url( 'admin.php?page=' . AdminPageSlugs::SETTINGS ),
			'woocommerce' => admin_url( 'admin.php?page=wc-settings&tab=integration' ),
			default       => null,
		};
	}
}
