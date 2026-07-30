<?php
/**
 * PRG admin notices shared across plugin admin pages.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Notice keys, redirect URLs, and admin_notices rendering for PRG flows.
 *
 * @internal
 * @final
 */
final class AdminNotices {

	/**
	 * Page slugs that may display PRG notices from this plugin.
	 *
	 * @var string[]
	 */
	private const NOTICE_PAGE_SLUGS = array(
		AdminPageSlugs::OVERVIEW,
		AdminPageSlugs::DETECTION,
		AdminPageSlugs::PROVIDERS,
		AdminPageSlugs::TRUSTED_PROXIES,
		AdminPageSlugs::DIAGNOSTICS,
		AdminPageSlugs::SETTINGS,
	);

	/**
	 * Wires the admin_notices callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render_saved_notice' ) );
	}

	/**
	 * Builds a PRG redirect URL carrying notice query args.
	 *
	 * @param string $page_slug   Target admin page slug.
	 * @param string $message_key One of notice_message()'s keys.
	 * @param string $type        'success' or 'warning'.
	 *
	 * @return string
	 */
	public function notice_redirect_url( string $page_slug, string $message_key, string $type ): string {
		return add_query_arg(
			array(
				'page'              => $page_slug,
				'universal_geo_msg' => $message_key,
				'universal_geo_typ' => $type,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Redirects to a plugin admin page carrying a notice, then terminates.
	 *
	 * @param string $page_slug   Target admin page slug.
	 * @param string $message_key One of notice_message()'s keys.
	 * @param string $type        'success' or 'warning'.
	 *
	 * @return void
	 */
	public function redirect_with_notice( string $page_slug, string $message_key, string $type ): void {
		wp_safe_redirect( $this->notice_redirect_url( $page_slug, $message_key, $type ) );
		exit;
	}

	/**
	 * Renders the PRG notice after a save/affordance redirect.
	 *
	 * @return void
	 */
	public function maybe_render_saved_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only query args after a PRG redirect this class itself issued.
		if ( ! isset( $_GET['universal_geo_msg'], $_GET['universal_geo_typ'], $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below.
		$page = sanitize_key( wp_unslash( $_GET['page'] ) );

		if ( ! in_array( $page, self::NOTICE_PAGE_SLUGS, true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below.
		$message_key = sanitize_key( wp_unslash( $_GET['universal_geo_msg'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below.
		$type = sanitize_key( wp_unslash( $_GET['universal_geo_typ'] ) );

		$message = $this->notice_message( $message_key );

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'success' === $type ? 'notice-success' : 'notice-warning' ),
			esc_html( $message )
		);
	}

	/**
	 * The message text for one notice key, or '' when unrecognized.
	 *
	 * @param string $message_key A sanitize_key()'d query-arg value.
	 *
	 * @return string
	 */
	public function notice_message( string $message_key ): string {
		$messages = array(
			'saved'                                  => __( 'Settings saved.', 'universal-geo-context' ),
			'trusted_proxies_saved'                  => __( 'Trusted proxy settings saved.', 'universal-geo-context' ),
			'peer_trusted'                           => __( 'The current peer address has been added to Trusted Proxies.', 'universal-geo-context' ),
			'cf_preset_enabled'                      => __( 'The Cloudflare preset has been enabled.', 'universal-geo-context' ),
			'providers_refreshed'                    => __( 'Provider health was refreshed.', 'universal-geo-context' ),
			'default_country_rejected'               => __( 'Other settings were saved, but the default country could not be recognized as a real ISO 3166-1 country code — the previous value was kept.', 'universal-geo-context' ),
			'maxmind_path_rejected'                  => __( 'Other settings were saved, but the MaxMind database path could not be validated (not a readable file, or outside the WordPress content directory) — the previous value was kept.', 'universal-geo-context' ),
			'remote_enable_requires_acknowledgement' => __( 'Other settings were saved, but the remote provider could not be enabled: you must acknowledge that visitor IP addresses will be transferred to MaxMind, Inc. in the same submission that enables it.', 'universal-geo-context' ),
			'maxmind_download_ok'                    => __( 'The GeoLite2 Country database was downloaded and installed.', 'universal-geo-context' ),
			'maxmind_download_already_current'       => __( 'The GeoLite2 Country database is already up to date.', 'universal-geo-context' ),
			'maxmind_download_failed'                => __( 'The database could not be downloaded or installed. See the status below for details.', 'universal-geo-context' ),
			'maxmind_validate_ok'                    => __( 'The installed database passed validation.', 'universal-geo-context' ),
			'maxmind_validate_failed'                => __( 'The installed database failed validation. See the status below for details.', 'universal-geo-context' ),
			'maxmind_remove_ok'                      => __( 'The managed database was removed.', 'universal-geo-context' ),
			'maxmind_remove_failed'                  => __( 'The managed database could not be removed. See the status below for details.', 'universal-geo-context' ),
			'maxmind_restore_ok'                     => __( 'The previous database version was restored.', 'universal-geo-context' ),
			'maxmind_restore_failed'                 => __( 'The previous version could not be restored. See the status below for details.', 'universal-geo-context' ),
		);

		return $messages[ $message_key ] ?? '';
	}
}
