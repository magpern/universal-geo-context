<?php
/**
 * First-run trusted-proxy notice.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Diagnostics\DiagnosticsService;

/**
 * Per-user dismissible notice when forwarding headers are present but no
 * trusted proxies are configured.
 *
 * @internal
 * @final
 */
final class FirstRunNotice {

	/**
	 * User meta key recording dismissal, per user.
	 */
	public const NOTICE_DISMISSED_META = 'universal_geo_first_run_notice_dismissed';

	/**
	 * Creates the notice with diagnostics access.
	 *
	 * @param DiagnosticsService $diagnostics Site Health verdict source.
	 */
	public function __construct(
		private readonly DiagnosticsService $diagnostics
	) {
	}

	/**
	 * Deletes dismissal meta for every user on uninstall.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_metadata( 'user', 0, self::NOTICE_DISMISSED_META, '', true );
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_universal_geo_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_first_run_notice' ) );
	}

	/**
	 * Public function handle dismiss notice(.
	 *
	 * @return void
	 */
	public function handle_dismiss_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_dismiss_notice' );

		update_user_meta( get_current_user_id(), self::NOTICE_DISMISSED_META, 1 );

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Public function maybe render first run notice(.
	 *
	 * @return void
	 */
	public function maybe_render_first_run_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::NOTICE_DISMISSED_META, true ) ) {
			return;
		}

		if ( ! $this->should_show_first_run_notice() ) {
			return;
		}

		$diagnostics_url = admin_url( 'admin.php?page=' . AdminPageSlugs::DIAGNOSTICS );
		$dismiss_url     = wp_nonce_url(
			admin_url( 'admin-post.php?action=universal_geo_dismiss_notice' ),
			'universal_geo_dismiss_notice'
		);

		printf(
			'<div class="notice notice-warning"><p>%1$s</p><p><a href="%2$s">%3$s</a> | <a href="%4$s">%5$s</a></p></div>',
			esc_html__(
				'Universal Geo Context detected forwarding headers but no trusted proxies are configured — geographic results may be wrong for every visitor.',
				'universal-geo-context'
			),
			esc_url( $diagnostics_url ),
			esc_html__( 'Review Diagnostics', 'universal-geo-context' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'universal-geo-context' )
		);
	}

	/**
	 * Returns whether the first-run notice should display.
	 *
	 * @return bool
	 */
	private function should_show_first_run_notice(): bool {
		return 'critical' === $this->diagnostics->trusted_proxy_site_status_test()['status'];
	}
}
