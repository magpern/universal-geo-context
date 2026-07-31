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
	 * @param AdminHeaderRenderer       $header    Shared page header.
	 * @param AdminActionRenderer       $actions   Shared action controls.
	 */
	public function __construct(
		private readonly DetectionInspectorService $inspector,
		private readonly ReportRenderer $renderer,
		private readonly AdminHeaderRenderer $header,
		private readonly AdminActionRenderer $actions
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
		$this->header->render(
			$this->slug(),
			$this->title(),
			function (): void {
				$this->actions->render_refresh_providers_form(
					$this->slug(),
					__( 'Refresh Providers', 'universal-geo-context' )
				);
				$this->actions->render_link_button(
					AdminPageRegistry::page_url( AdminPageSlugs::SETTINGS ),
					__( 'Open Settings', 'universal-geo-context' )
				);
			}
		);

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

		foreach ( $details as $provider_id => $section ) {
			echo '<div class="postbox" style="max-width:960px;margin-top:1em;"><div class="postbox-header"><h2 class="hndle">';
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
			'cloudflare'  => AdminPageRegistry::page_url( AdminPageSlugs::TRUSTED_PROXIES ),
			'maxmind'       => AdminPageRegistry::page_url( AdminPageSlugs::SETTINGS ) . '#universal-geo-managed-database',
			'remote'        => AdminPageRegistry::page_url( AdminPageSlugs::SETTINGS ) . '#universal-geo-remote-provider',
			'default'       => AdminPageRegistry::page_url( AdminPageSlugs::SETTINGS ),
			'woocommerce' => admin_url( 'admin.php?page=wc-settings&tab=integration' ),
			default         => null,
		};
	}
}
