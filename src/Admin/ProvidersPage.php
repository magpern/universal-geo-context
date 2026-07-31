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
	 * @param DetectionInspectorService $inspector  Provider detail supplier.
	 * @param ReportRenderer            $renderer   Definition-list renderer.
	 * @param AdminHeaderRenderer       $header     Shared page header.
	 * @param AdminActionRenderer       $actions    Shared action controls.
	 * @param AdminComponentRenderer    $components Design-system components.
	 */
	public function __construct(
		private readonly DetectionInspectorService $inspector,
		private readonly ReportRenderer $renderer,
		private readonly AdminHeaderRenderer $header,
		private readonly AdminActionRenderer $actions,
		private readonly AdminComponentRenderer $components
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
		$shell           = $this->header->shell();

		$shell->open_wrap();
		$shell->open();
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

		$shell->open_content( true );

		if ( null !== $refresh_summary ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
			echo $this->components->info_panel(
				__( 'Last explicit refresh', 'universal-geo-context' ),
				sprintf(
					/* translators: 1: ok count, 2: total providers */
					__( '%1$d of %2$d providers returned a country.', 'universal-geo-context' ),
					(int) $refresh_summary['ok_count'],
					(int) $refresh_summary['total']
				)
			);
		}

		echo '<div class="ugc-ui-provider-list">';

		foreach ( $details as $provider_id => $section ) {
			$available = ! empty( $section['available'] );
			$badge     = $this->components->status_badge(
				$available ? __( 'Available', 'universal-geo-context' ) : __( 'Unavailable', 'universal-geo-context' ),
				$available ? 'available' : 'disabled'
			);

			ob_start();
			$this->renderer->render_definition_list( $section );
			$body = ob_get_clean();

			$action_html = '';
			$settings_url = $this->settings_url_for_provider( (string) $provider_id );
			if ( null !== $settings_url ) {
				ob_start();
				$this->actions->render_link_button( $settings_url, __( 'Open related settings', 'universal-geo-context' ) );
				$action_html = ob_get_clean();
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
			echo $this->components->provider_card(
				ucfirst( (string) $provider_id ),
				__( 'Provider availability, configuration, and health summary.', 'universal-geo-context' ),
				$available ? __( 'Available', 'universal-geo-context' ) : __( 'Unavailable', 'universal-geo-context' ),
				$available ? 'available' : 'disabled',
				$body,
				$action_html
			);
		}

		echo '</div>';

		$shell->close_content();
		$shell->close();
		$shell->close_wrap();
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
