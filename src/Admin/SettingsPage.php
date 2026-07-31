<?php
/**
 * Settings admin page.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Cache\GeoCache;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\DatabaseUpdateResult;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Settings;

/**
 * Plugin settings and managed MaxMind database actions (trusted-proxy fields
 * live on TrustedProxiesPage).
 *
 * @internal
 * @final
 */
final class SettingsPage implements Page {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param UpdateScheduler     $update_scheduler Reconciled after settings save.
	 * @param DatabaseManager     $database_manager Managed database actions.
	 * @param AdminNotices        $notices          PRG redirects.
	 * @param AdminHeaderRenderer $header       Shared page header.
	 * @param AdminActionRenderer $actions      Shared action controls.
	 */
	public function __construct(
		private readonly UpdateScheduler $update_scheduler,
		private readonly DatabaseManager $database_manager,
		private readonly AdminNotices $notices,
		private readonly AdminHeaderRenderer $header,
		private readonly AdminActionRenderer $actions
	) {
	}

	/**
	 * Registers admin_post handlers.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		add_action( 'admin_post_universal_geo_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_universal_geo_maxmind_database_download', array( $this, 'handle_maxmind_database_download' ) );
		add_action( 'admin_post_universal_geo_maxmind_database_validate', array( $this, 'handle_maxmind_database_validate' ) );
		add_action( 'admin_post_universal_geo_maxmind_database_remove', array( $this, 'handle_maxmind_database_remove' ) );
		add_action( 'admin_post_universal_geo_maxmind_database_restore', array( $this, 'handle_maxmind_database_restore' ) );
	}

	/**
	 * Returns the page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return AdminPageSlugs::SETTINGS;
	}

	/**
	 * Returns the page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Settings', 'universal-geo-context' );
	}

	/**
	 * Returns the submenu label.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Settings', 'universal-geo-context' );
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

		$settings = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) );

		echo '<div class="wrap">';
		$this->header->render(
			$this->slug(),
			$this->title(),
			function (): void {
				$this->actions->render_link_button(
					AdminPageRegistry::page_url( AdminPageSlugs::SETTINGS ) . '#universal-geo-managed-database',
					__( 'Download MaxMind Database', 'universal-geo-context' )
				);
			}
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'universal_geo_save_settings' );
		echo '<input type="hidden" name="action" value="universal_geo_save_settings" />';
		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row"><label for="universal_geo_default_country">%1$s</label></th>' .
			'<td><input type="text" maxlength="2" id="universal_geo_default_country" name="default_country" value="%2$s" />' .
			'<p class="description">%3$s</p></td></tr>',
			esc_html__( 'Default country', 'universal-geo-context' ),
			esc_attr( $settings['default_country'] ),
			esc_html__( 'A real two-letter ISO 3166-1 country code (e.g. SE). Empty = no fallback. An unrecognized code is rejected and the previous value is kept.', 'universal-geo-context' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="derived_cache_enabled" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Enable derived-context cache', 'universal-geo-context' ),
			checked( $settings['derived_cache_enabled'], true, false ),
			esc_html__( 'Requires a persistent object cache; otherwise a safe no-op.', 'universal-geo-context' )
		);

		printf(
			'<tr><th scope="row"><label for="universal_geo_derived_cache_ttl">%1$s</label></th>' .
			'<td><input type="number" min="60" max="86400" id="universal_geo_derived_cache_ttl" name="derived_cache_ttl" value="%2$d" /></td></tr>',
			esc_html__( 'Cache TTL (seconds)', 'universal-geo-context' ),
			(int) $settings['derived_cache_ttl']
		);

		printf(
			'<tr><th scope="row"><label for="universal_geo_maxmind_db_path">%1$s</label></th>' .
			'<td><input type="text" class="regular-text" id="universal_geo_maxmind_db_path" name="maxmind_db_path" value="%2$s" />' .
			'<p class="description">%3$s</p></td></tr>',
			esc_html__( 'MaxMind database path', 'universal-geo-context' ),
			esc_attr( $settings['maxmind_db_path'] ),
			esc_html__( 'Absolute path to a .mmdb file under the WordPress content directory. Empty = auto-detect via WooCommerce.', 'universal-geo-context' )
		);

		echo '</tbody></table>';

		$this->render_maxmind_account_section( $settings );
		$this->render_remote_settings_section( $settings );
		$this->render_managed_database_section( $settings );

		submit_button();
		echo '</form></div>';
	}

	/**
	 * Public function handle save settings(.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_save_settings' );

		$previous = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) );

		$raw = array(
			'default_country'                       => isset( $_POST['default_country'] ) ? wp_unslash( $_POST['default_country'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'trusted_proxies'                       => $previous['trusted_proxies'],
			'trust_cloudflare'                      => $previous['trust_cloudflare'],
			'derived_cache_enabled'                 => ! empty( $_POST['derived_cache_enabled'] ),
			'derived_cache_ttl'                     => isset( $_POST['derived_cache_ttl'] ) ? wp_unslash( $_POST['derived_cache_ttl'] ) : 900, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'maxmind_db_path'                       => isset( $_POST['maxmind_db_path'] ) ? wp_unslash( $_POST['maxmind_db_path'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'remote_enabled'                        => ! empty( $_POST['remote_enabled'] ),
			'remote_transfer_acknowledged'          => ! empty( $_POST['remote_transfer_acknowledged'] ),
			'remote_account_id'                     => $previous['remote_account_id'],
			'remote_license_key'                    => $previous['remote_license_key'],
			'remote_timeout'                        => isset( $_POST['remote_timeout'] ) ? wp_unslash( $_POST['remote_timeout'] ) : 2, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'maxmind_account_id'                    => $this->submitted_credential( 'maxmind_account_id', $previous['maxmind_account_id'], 'maxmind_clear_credentials' ),
			'maxmind_license_key'                   => $this->submitted_credential( 'maxmind_license_key', $previous['maxmind_license_key'], 'maxmind_clear_credentials' ),
			'maxmind_managed_enabled'               => ! empty( $_POST['maxmind_managed_enabled'] ),
			'maxmind_managed_auto_update_enabled'   => ! empty( $_POST['maxmind_managed_auto_update_enabled'] ),
			'maxmind_managed_auto_update_frequency' => isset( $_POST['maxmind_managed_auto_update_frequency'] ) ? wp_unslash( $_POST['maxmind_managed_auto_update_frequency'] ) : 'weekly', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'maxmind_managed_retain_previous'       => ! empty( $_POST['maxmind_managed_retain_previous'] ),
		);

		$sanitized = Settings::sanitize( $raw );

		$default_country_rejected = false;
		$raw_default_country      = is_string( $raw['default_country'] ) ? strtoupper( trim( $raw['default_country'] ) ) : '';

		if ( '' !== $raw_default_country && '' === $sanitized['default_country'] ) {
			$sanitized['default_country'] = $previous['default_country'];
			$default_country_rejected     = true;
		}

		$maxmind_path_rejected = false;

		if ( '' !== $sanitized['maxmind_db_path'] && ! $this->maxmind_path_is_valid( $sanitized['maxmind_db_path'] ) ) {
			$sanitized['maxmind_db_path'] = $previous['maxmind_db_path'];
			$maxmind_path_rejected        = true;
		}

		$enable_blocked_by_acknowledgement = ! empty( $_POST['remote_enabled'] ) && ! $sanitized['remote_enabled'];

		Settings::save( $sanitized );
		GeoCache::bump_epoch();

		$this->update_scheduler->ensure_scheduled(
			$sanitized['maxmind_managed_auto_update_enabled'],
			$sanitized['maxmind_managed_auto_update_frequency']
		);

		if ( $default_country_rejected ) {
			$this->notices->redirect_with_notice( $this->slug(), 'default_country_rejected', 'warning' );
			return;
		}

		if ( $maxmind_path_rejected ) {
			$this->notices->redirect_with_notice( $this->slug(), 'maxmind_path_rejected', 'warning' );
			return;
		}

		if ( $enable_blocked_by_acknowledgement ) {
			$this->notices->redirect_with_notice( $this->slug(), 'remote_enable_requires_acknowledgement', 'warning' );
			return;
		}

		$this->notices->redirect_with_notice( $this->slug(), 'saved', 'success' );
	}

	/**
	 * Public function handle maxmind database download(.
	 *
	 * @return void
	 */
	public function handle_maxmind_database_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_maxmind_database_download' );

		$result = $this->database_manager->download_now( 'admin' );

		$this->redirect_with_maxmind_result( $result, 'maxmind_download' );
	}

	/**
	 * Public function handle maxmind database validate(.
	 *
	 * @return void
	 */
	public function handle_maxmind_database_validate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_maxmind_database_validate' );

		$result = $this->database_manager->validate_installed();

		$this->redirect_with_maxmind_result( $result, 'maxmind_validate' );
	}

	/**
	 * Public function handle maxmind database remove(.
	 *
	 * @return void
	 */
	public function handle_maxmind_database_remove(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_maxmind_database_remove' );

		$result = $this->database_manager->remove_managed_database( 'admin' );

		$this->redirect_with_maxmind_result( $result, 'maxmind_remove' );
	}

	/**
	 * Public function handle maxmind database restore(.
	 *
	 * @return void
	 */
	public function handle_maxmind_database_restore(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_maxmind_database_restore' );

		$result = $this->database_manager->restore_previous( 'admin' );

		$this->redirect_with_maxmind_result( $result, 'maxmind_restore' );
	}

	/**
	 * Redirects back with a managed-database action notice.
	 *
	 * @param DatabaseUpdateResult $result        Completed action result.
	 * @param string               $action_prefix Notice key prefix.
	 *
	 * @return void
	 */
	private function redirect_with_maxmind_result( DatabaseUpdateResult $result, string $action_prefix ): void {
		$key = $action_prefix . ( $result->success ? '_' . $result->code : '_failed' );

		if ( '' === $this->notices->notice_message( $key ) ) {
			$key = $action_prefix . '_ok';
		}

		$this->notices->redirect_with_notice( $this->slug(), $key, $result->success ? 'success' : 'warning' );
	}

	/**
	 * Resolves a credential field from POST without clearing stored secrets.
	 *
	 * @param string $post_key           POST key for credential.
	 * @param string $previous_value     Stored value.
	 * @param string $clear_checkbox_key Clear checkbox POST key.
	 *
	 * @return string
	 */
	private function submitted_credential( string $post_key, string $previous_value, string $clear_checkbox_key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST[ $clear_checkbox_key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $post_key ] ) ) {
			return $previous_value;
		}

		$submitted = wp_unslash( $_POST[ $post_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing

		if ( ! is_string( $submitted ) || '' === trim( $submitted ) ) {
			return $previous_value;
		}

		return $submitted;
	}

	/**
	 * Returns whether MaxMind credentials are locked by wp-config constants.
	 *
	 * @return bool
	 */
	private function maxmind_credentials_locked_by_constants(): bool {
		$canonical_locked = defined( 'UNIVERSAL_GEO_MAXMIND_ACCOUNT_ID' ) && is_string( UNIVERSAL_GEO_MAXMIND_ACCOUNT_ID ) && '' !== UNIVERSAL_GEO_MAXMIND_ACCOUNT_ID
			&& defined( 'UNIVERSAL_GEO_MAXMIND_LICENSE_KEY' ) && is_string( UNIVERSAL_GEO_MAXMIND_LICENSE_KEY ) && '' !== UNIVERSAL_GEO_MAXMIND_LICENSE_KEY;

		$legacy_locked = defined( 'UNIVERSAL_GEO_REMOTE_ACCOUNT_ID' ) && is_string( UNIVERSAL_GEO_REMOTE_ACCOUNT_ID ) && '' !== UNIVERSAL_GEO_REMOTE_ACCOUNT_ID
			&& defined( 'UNIVERSAL_GEO_REMOTE_LICENSE_KEY' ) && is_string( UNIVERSAL_GEO_REMOTE_LICENSE_KEY ) && '' !== UNIVERSAL_GEO_REMOTE_LICENSE_KEY;

		return $canonical_locked || $legacy_locked;
	}

	/**
	 * Returns whether a MaxMind database path points to a readable file.
	 *
	 * @param string $path Sanitized absolute path.
	 *
	 * @return bool
	 */
	private function maxmind_path_is_valid( string $path ): bool {
		$resolved = realpath( $path );

		if ( false === $resolved || ! is_file( $resolved ) || ! is_readable( $resolved ) ) {
			return false;
		}

		$content_dir = realpath( WP_CONTENT_DIR );

		if ( false === $content_dir ) {
			return false;
		}

		return str_starts_with( $resolved, rtrim( $content_dir, '/' ) . '/' );
	}

	/**
	 * Renders the MaxMind account credentials section.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 *
	 * @return void
	 */
	private function render_maxmind_account_section( array $settings ): void {
		$locked = $this->maxmind_credentials_locked_by_constants();

		echo '<h2>' . esc_html__( 'MaxMind account', 'universal-geo-context' ) . '</h2>';

		printf(
			'<p>%s</p>',
			esc_html__(
				'One shared credential pair, used by both the remote provider below and managed database downloads. A MaxMind account has one account ID/license key regardless of which MaxMind product it authenticates against.',
				'universal-geo-context'
			)
		);

		echo '<table class="form-table"><tbody>';

		$disabled_attr = $locked ? ' disabled="disabled"' : '';

		printf(
			'<tr><th scope="row"><label for="universal_geo_maxmind_account_id">%1$s</label></th>' .
			'<td><input type="password" autocomplete="off" class="regular-text" id="universal_geo_maxmind_account_id" name="maxmind_account_id" value="" placeholder="%2$s"%3$s />' .
			'<p class="description">%4$s</p></td></tr>',
			esc_html__( 'Account ID', 'universal-geo-context' ),
			esc_attr( '' !== $settings['maxmind_account_id'] ? __( 'Currently configured (hidden)', 'universal-geo-context' ) : __( 'Not configured', 'universal-geo-context' ) ),
			$disabled_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html(
				$locked
				? __( 'Supplied via a wp-config.php constant and cannot be edited here.', 'universal-geo-context' )
				: __( 'Leave blank to keep the stored value unchanged.', 'universal-geo-context' )
			)
		);

		printf(
			'<tr><th scope="row"><label for="universal_geo_maxmind_license_key">%1$s</label></th>' .
			'<td><input type="password" autocomplete="off" class="regular-text" id="universal_geo_maxmind_license_key" name="maxmind_license_key" value="" placeholder="%2$s"%3$s />' .
			'<p class="description">%4$s</p></td></tr>',
			esc_html__( 'License key', 'universal-geo-context' ),
			esc_attr( '' !== $settings['maxmind_license_key'] ? __( 'Currently configured (hidden)', 'universal-geo-context' ) : __( 'Not configured', 'universal-geo-context' ) ),
			$disabled_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html(
				$locked
				? __( 'Supplied via a wp-config.php constant and cannot be edited here.', 'universal-geo-context' )
				: __( 'Leave blank to keep the stored value unchanged.', 'universal-geo-context' )
			)
		);

		if ( ! $locked ) {
			printf(
				'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="maxmind_clear_credentials" value="1" /> %2$s</label></td></tr>',
				esc_html__( 'Clear stored credentials', 'universal-geo-context' ),
				esc_html__( 'Blanks both fields above on save, regardless of what (if anything) is also typed.', 'universal-geo-context' )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the remote MaxMind provider settings section.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 *
	 * @return void
	 */
	private function render_remote_settings_section( array $settings ): void {
		echo '<h2 id="universal-geo-remote-provider">' . esc_html__( 'Remote provider (MaxMind GeoLite2 Country Web Service)', 'universal-geo-context' ) . '</h2>';

		printf(
			'<p>%s</p>',
			esc_html__(
				'Disabled by default. When enabled, this plugin sends visitor IP addresses to MaxMind, Inc. at geolite.info to derive a country — the one exception to this plugin never letting an IP address leave the server. Enabling requires both the MaxMind account credentials above and the acknowledgement below, in the same submission.',
				'universal-geo-context'
			)
		);

		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="remote_transfer_acknowledged" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Transfer acknowledgement', 'universal-geo-context' ),
			checked( $settings['remote_transfer_acknowledged'], true, false ),
			esc_html__( 'I acknowledge that enabling the remote provider transfers visitor IP addresses to MaxMind, Inc.', 'universal-geo-context' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="remote_enabled" value="1" %2$s /> %3$s</label></td>' .
			'<td><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Enable remote provider', 'universal-geo-context' ),
			checked( $settings['remote_enabled'], true, false ),
			esc_html__( 'Use MaxMind GeoLite2 Country Web Service as a fallback provider.', 'universal-geo-context' ),
			esc_html__( 'Requires the acknowledgement above in the same save, and the MaxMind account credentials above.', 'universal-geo-context' )
		);

		printf(
			'<tr><th scope="row"><label for="universal_geo_remote_timeout">%1$s</label></th>' .
			'<td><input type="number" min="1" max="5" id="universal_geo_remote_timeout" name="remote_timeout" value="%2$d" />' .
			'<p class="description">%3$s</p></td></tr>',
			esc_html__( 'Request timeout (seconds)', 'universal-geo-context' ),
			(int) $settings['remote_timeout'],
			esc_html__( 'Bounds how long a single remote lookup may hold a page view open. 1–5 seconds; default 2.', 'universal-geo-context' )
		);

		echo '</tbody></table>';
	}

	/**
	 * Renders the managed MaxMind database settings section.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 *
	 * @return void
	 */
	private function render_managed_database_section( array $settings ): void {
		echo '<h2 id="universal-geo-managed-database">' . esc_html__( 'Managed database (automatic GeoLite2 Country downloads)', 'universal-geo-context' ) . '</h2>';

		printf(
			'<p>%s</p>',
			esc_html__(
				'Disabled by default. When enabled, this plugin downloads and keeps the GeoLite2 Country database up to date automatically, using the MaxMind account credentials above.',
				'universal-geo-context'
			)
		);

		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="maxmind_managed_enabled" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Enable managed database', 'universal-geo-context' ),
			checked( $settings['maxmind_managed_enabled'], true, false ),
			esc_html__( 'Let this plugin download and install the GeoLite2 Country database itself.', 'universal-geo-context' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="maxmind_managed_auto_update_enabled" value="1" %2$s /> %3$s</label></td>' .
			'<td><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Enable automatic updates', 'universal-geo-context' ),
			checked( $settings['maxmind_managed_auto_update_enabled'], true, false ),
			esc_html__( 'Keep the managed database current on a schedule (WP-Cron).', 'universal-geo-context' ),
			esc_html__( 'Requires "Enable managed database" above, in the same save.', 'universal-geo-context' )
		);

		printf(
			'<tr><th scope="row"><label for="universal_geo_maxmind_managed_auto_update_frequency">%1$s</label></th>' .
			'<td><select id="universal_geo_maxmind_managed_auto_update_frequency" name="maxmind_managed_auto_update_frequency">' .
			'<option value="weekly"%2$s>%3$s</option><option value="twice_weekly"%4$s>%5$s</option></select>' .
			'<p class="description">%6$s</p></td></tr>',
			esc_html__( 'Update frequency', 'universal-geo-context' ),
			selected( $settings['maxmind_managed_auto_update_frequency'], 'weekly', false ),
			esc_html__( 'Weekly', 'universal-geo-context' ),
			selected( $settings['maxmind_managed_auto_update_frequency'], 'twice_weekly', false ),
			esc_html__( 'Twice weekly', 'universal-geo-context' ),
			esc_html__( 'GeoLite2 Country is published at most twice a week.', 'universal-geo-context' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="maxmind_managed_retain_previous" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Retain previous version', 'universal-geo-context' ),
			checked( $settings['maxmind_managed_retain_previous'], true, false ),
			esc_html__( 'Keep one prior generation on disk after a successful update, restorable below.', 'universal-geo-context' )
		);

		echo '</tbody></table>';

		$this->render_managed_database_status();
	}

	/**
	 * Private function render managed database status(.
	 *
	 * @return void
	 */
	private function render_managed_database_status(): void {
		$installed_path = $this->database_manager->installed_path();
		$status         = $this->database_manager->status();

		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html__( 'Installed', 'universal-geo-context' ),
			esc_html( '' !== $installed_path ? __( 'Yes', 'universal-geo-context' ) : __( 'No', 'universal-geo-context' ) )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html__( 'Last attempt', 'universal-geo-context' ),
			esc_html( null !== $status['last_attempt_at'] ? gmdate( 'Y-m-d H:i:s', $status['last_attempt_at'] ) . ' UTC' : __( 'Never', 'universal-geo-context' ) )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html__( 'Last result', 'universal-geo-context' ),
			esc_html( '' !== $status['last_result_code'] ? $status['last_result_code'] : __( 'None', 'universal-geo-context' ) )
		);

		echo '</tbody></table>';

		echo '<p>';
		$this->render_managed_database_action_button( 'universal_geo_maxmind_database_download', __( 'Download now', 'universal-geo-context' ), false );
		echo ' ';
		$this->render_managed_database_action_button( 'universal_geo_maxmind_database_validate', __( 'Check database', 'universal-geo-context' ), false );
		echo ' ';
		$this->render_managed_database_action_button( 'universal_geo_maxmind_database_remove', __( 'Remove managed database', 'universal-geo-context' ), true );
		echo ' ';
		$this->render_managed_database_action_button( 'universal_geo_maxmind_database_restore', __( 'Restore previous version', 'universal-geo-context' ), true );
		echo '</p>';
	}

	/**
	 * Renders one managed-database admin_post action button.
	 *
	 * @param string $action  admin_post action name.
	 * @param string $label   Button label.
	 * @param bool   $confirm Whether to require JS confirm.
	 *
	 * @return void
	 */
	private function render_managed_database_action_button( string $action, string $label, bool $confirm ): void {
		printf(
			'<form method="post" action="%1$s" style="display:inline">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( $action );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );

		if ( $confirm ) {
			printf(
				'<button type="submit" class="button" onclick="return confirm(%s);">%s</button>',
				esc_attr( wp_json_encode( __( 'Are you sure?', 'universal-geo-context' ) ) ),
				esc_html( $label )
			);
		} else {
			printf( '<button type="submit" class="button">%s</button>', esc_html( $label ) );
		}

		echo '</form>';
	}
}
