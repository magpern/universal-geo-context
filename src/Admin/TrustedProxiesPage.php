<?php
/**
 * Trusted Proxies admin page.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Http\IpUtils;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Settings;

/**
 * Trusted-proxy configuration and quick-action affordances.
 *
 * @internal
 * @final
 */
final class TrustedProxiesPage implements Page {

	/**
	 * @param DiagnosticsService $diagnostics Masked report slices for status display.
	 * @param ServerRequest      $request     Raw peer for "Trust this peer".
	 * @param ReportRenderer     $renderer    Definition-list renderer.
	 * @param AdminNotices       $notices     PRG redirects.
	 */
	public function __construct(
		private readonly DiagnosticsService $diagnostics,
		private readonly ServerRequest $request,
		private readonly ReportRenderer $renderer,
		private readonly AdminNotices $notices
	) {
	}

	/**
	 * Registers admin_post handlers.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		add_action( 'admin_post_universal_geo_save_trusted_proxies', array( $this, 'handle_save_trusted_proxies' ) );
		add_action( 'admin_post_universal_geo_trust_peer', array( $this, 'handle_trust_peer' ) );
		add_action( 'admin_post_universal_geo_enable_cf_preset', array( $this, 'handle_enable_cf_preset' ) );
	}

	/**
	 * Returns the page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return AdminPageSlugs::TRUSTED_PROXIES;
	}

	/**
	 * Returns the page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Trusted Proxies', 'universal-geo-context' );
	}

	/**
	 * Returns the submenu label.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Trusted Proxies', 'universal-geo-context' );
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

		$sections = $this->diagnostics->trusted_proxies_page_sections();
		$settings = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $this->title() ) . '</h1>';

		echo '<h2>' . esc_html__( 'Current status', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $sections['trusted_proxies'] );

		if ( ! $sections['trusted_proxies']['peer_trusted'] ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=universal_geo_trust_peer' ), 'universal_geo_trust_peer' );
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( $url ),
				esc_html__( 'Trust this peer', 'universal-geo-context' )
			);
		}

		echo '<h2>' . esc_html__( 'Cloudflare', 'universal-geo-context' ) . '</h2>';
		$this->renderer->render_definition_list( $sections['cloudflare'] );

		if ( $sections['cloudflare']['peer_in_cf_ranges'] && ! $sections['cloudflare']['preset_enabled'] ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=universal_geo_enable_cf_preset' ), 'universal_geo_enable_cf_preset' );
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( $url ),
				esc_html__( 'Enable the Cloudflare preset', 'universal-geo-context' )
			);
		}

		echo '<h2>' . esc_html__( 'Configuration', 'universal-geo-context' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'universal_geo_save_trusted_proxies' );
		echo '<input type="hidden" name="action" value="universal_geo_save_trusted_proxies" />';
		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row"><label for="universal_geo_trusted_proxies">%1$s</label></th>' .
			'<td><textarea id="universal_geo_trusted_proxies" name="trusted_proxies" rows="4" cols="50">%2$s</textarea>' .
			'<p class="description">%3$s</p></td></tr>',
			esc_html__( 'Trusted proxies', 'universal-geo-context' ),
			esc_textarea( implode( "\n", $settings['trusted_proxies'] ) ),
			esc_html__( 'One CIDR or IP per line. Empty = trust no forwarding header.', 'universal-geo-context' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="trust_cloudflare" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Trust Cloudflare', 'universal-geo-context' ),
			checked( $settings['trust_cloudflare'], true, false ),
			esc_html__( 'Trust the CF-Connecting-IP / CF-IPCountry headers once the peer is trusted.', 'universal-geo-context' )
		);

		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Public function handle save trusted proxies(.
	 *
	 * @return void
	 */
	public function handle_save_trusted_proxies(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_save_trusted_proxies' );

		$previous = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) );
		$raw      = $previous;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via Settings::sanitize().
		$raw['trusted_proxies']  = isset( $_POST['trusted_proxies'] )
			? $this->parse_trusted_proxies_textarea( wp_unslash( $_POST['trusted_proxies'] ) )
			: array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via Settings::sanitize(); nonce verified above.
		$raw['trust_cloudflare'] = ! empty( $_POST['trust_cloudflare'] );

		Settings::save( Settings::sanitize( $raw ) );
		GeoCache::bump_epoch();

		$this->notices->redirect_with_notice( $this->slug(), 'trusted_proxies_saved', 'success' );
	}

	/**
	 * Public function handle trust peer(.
	 *
	 * @return void
	 */
	public function handle_trust_peer(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_trust_peer' );

		$raw  = $this->request->remote_addr();
		$peer = null !== $raw ? IpUtils::normalize( $raw ) : null;

		if ( null !== $peer ) {
			$settings = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) );
			$prefix   = false !== filter_var( $peer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? 32 : 128;
			$entry    = $peer . '/' . $prefix;

			if ( ! in_array( $entry, $settings['trusted_proxies'], true ) ) {
				$settings['trusted_proxies'][] = $entry;
			}

			Settings::save( $settings );
			GeoCache::bump_epoch();
		}

		$this->notices->redirect_with_notice( $this->slug(), 'peer_trusted', 'success' );
	}

	/**
	 * Public function handle enable cf preset(.
	 *
	 * @return void
	 */
	public function handle_enable_cf_preset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_enable_cf_preset' );

		$settings                     = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) );
		$settings['trust_cloudflare'] = true;

		Settings::save( $settings );
		GeoCache::bump_epoch();

		$this->notices->redirect_with_notice( $this->slug(), 'cf_preset_enabled', 'success' );
	}

	/**
	 * @param string $raw Raw textarea submission.
	 *
	 * @return string[]
	 */
	private function parse_trusted_proxies_textarea( string $raw ): array {
		$lines = preg_split( '/[\r\n]+/', $raw );
		$lines = is_array( $lines ) ? $lines : array();

		return array_values( array_filter( array_map( 'trim', $lines ), static fn( string $line ): bool => '' !== $line ) );
	}
}
