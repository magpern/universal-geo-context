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
	 * @param UpdateScheduler          $update_scheduler Reconciled after settings save.
	 * @param DatabaseManager          $database_manager Managed database actions.
	 * @param AdminNotices             $notices          PRG redirects.
	 * @param AdminHeaderRenderer      $header           Shared page header.
	 * @param AdminActionRenderer      $actions          Shared action controls.
	 * @param AdminComponentRenderer   $components       Design-system components.
	 * @param ReportRenderer           $report           Definition list renderer.
	 */
	public function __construct(
		private readonly UpdateScheduler $update_scheduler,
		private readonly DatabaseManager $database_manager,
		private readonly AdminNotices $notices,
		private readonly AdminHeaderRenderer $header,
		private readonly AdminActionRenderer $actions,
		private readonly AdminComponentRenderer $components,
		private readonly ReportRenderer $report
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
		$shell    = $this->header->shell();

		echo '<div class="wrap">';
		$shell->open();
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

		$shell->open_content( true );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-ugc-sticky-root="settings">';
		wp_nonce_field( 'universal_geo_save_settings' );
		echo '<input type="hidden" name="action" value="universal_geo_save_settings" />';

		$this->render_general_card( $settings );
		$this->render_maxmind_account_card( $settings );
		$this->render_remote_settings_card( $settings );
		$this->render_managed_database_card( $settings );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->sticky_save_bar( 'settings' );
		echo '</form>';

		$shell->close_content();
		$shell->close();
		echo '</div>';
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
	 * Renders the General settings card.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 *
	 * @return void
	 */
	private function render_general_card( array $settings ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open(
			__( 'General', 'universal-geo-context' ),
			__( 'Default country fallback and derived-context caching.', 'universal-geo-context' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->input_row(
			'default_country',
			__( 'Default country', 'universal-geo-context' ),
			$settings['default_country'],
			__( 'A real two-letter ISO 3166-1 country code (e.g. SE). Empty = no fallback. An unrecognized code is rejected and the previous value is kept.', 'universal-geo-context' ),
			array(
				'id'        => 'universal_geo_default_country',
				'maxlength' => '2',
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->toggle_row(
			'derived_cache_enabled',
			$settings['derived_cache_enabled'],
			__( 'Enable derived-context cache', 'universal-geo-context' ),
			__( 'Requires a persistent object cache; otherwise a safe no-op.', 'universal-geo-context' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->number_row(
			'derived_cache_ttl',
			__( 'Cache TTL (seconds)', 'universal-geo-context' ),
			(string) (int) $settings['derived_cache_ttl'],
			'',
			array(
				'id'  => 'universal_geo_derived_cache_ttl',
				'min' => '60',
				'max' => '86400',
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->input_row(
			'maxmind_db_path',
			__( 'MaxMind database path', 'universal-geo-context' ),
			$settings['maxmind_db_path'],
			__( 'Absolute path to a .mmdb file under the WordPress content directory. Empty = auto-detect via WooCommerce.', 'universal-geo-context' ),
			array(
				'id'    => 'universal_geo_maxmind_db_path',
				'class' => 'regular-text',
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();
	}

	/**
	 * Renders the MaxMind account credentials card.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 *
	 * @return void
	 */
	private function render_maxmind_account_card( array $settings ): void {
		$locked = $this->maxmind_credentials_locked_by_constants();
		$badge  = $this->components->status_badge(
			$locked ? __( 'Locked by constant', 'universal-geo-context' ) : ( '' !== $settings['maxmind_account_id'] ? __( 'Configured', 'universal-geo-context' ) : __( 'Not configured', 'universal-geo-context' ) ),
			$locked ? 'warning' : ( '' !== $settings['maxmind_account_id'] ? 'active' : 'disabled' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open(
			__( 'MaxMind Account', 'universal-geo-context' ),
			__( 'One shared credential pair, used by both the remote provider below and managed database downloads.', 'universal-geo-context' ),
			$badge
		);

		$disabled = $locked ? array( 'disabled' => 'disabled' ) : array();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->password_row(
			'maxmind_account_id',
			__( 'Account ID', 'universal-geo-context' ),
			'' !== $settings['maxmind_account_id'] ? __( 'Currently configured (hidden)', 'universal-geo-context' ) : __( 'Not configured', 'universal-geo-context' ),
			$locked
				? __( 'Supplied via a wp-config.php constant and cannot be edited here.', 'universal-geo-context' )
				: __( 'Leave blank to keep the stored value unchanged.', 'universal-geo-context' ),
			array_merge( array( 'id' => 'universal_geo_maxmind_account_id' ), $disabled )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->password_row(
			'maxmind_license_key',
			__( 'License key', 'universal-geo-context' ),
			'' !== $settings['maxmind_license_key'] ? __( 'Currently configured (hidden)', 'universal-geo-context' ) : __( 'Not configured', 'universal-geo-context' ),
			$locked
				? __( 'Supplied via a wp-config.php constant and cannot be edited here.', 'universal-geo-context' )
				: __( 'Leave blank to keep the stored value unchanged.', 'universal-geo-context' ),
			array_merge( array( 'id' => 'universal_geo_maxmind_license_key' ), $disabled )
		);

		if ( ! $locked ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
			echo $this->components->toggle_row(
				'maxmind_clear_credentials',
				false,
				__( 'Clear stored credentials', 'universal-geo-context' ),
				__( 'Blanks both fields above on save, regardless of what (if anything) is also typed.', 'universal-geo-context' )
			);
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();
	}

	/**
	 * Renders the remote MaxMind provider settings card.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 *
	 * @return void
	 */
	private function render_remote_settings_card( array $settings ): void {
		$badge = $this->components->status_badge(
			$settings['remote_enabled'] ? __( 'Enabled', 'universal-geo-context' ) : __( 'Disabled', 'universal-geo-context' ),
			$settings['remote_enabled'] ? 'active' : 'disabled'
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open(
			__( 'Remote Provider', 'universal-geo-context' ),
			__( 'MaxMind GeoLite2 Country Web Service — disabled by default. Enabling transfers visitor IP addresses to MaxMind.', 'universal-geo-context' ),
			$badge
		);
		echo '<div id="universal-geo-remote-provider"></div>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->warning_panel(
			'',
			__( 'When enabled, this plugin sends visitor IP addresses to MaxMind, Inc. at geolite.info to derive a country — the one exception to this plugin never letting an IP address leave the server.', 'universal-geo-context' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->toggle_row(
			'remote_transfer_acknowledged',
			$settings['remote_transfer_acknowledged'],
			__( 'Transfer acknowledgement', 'universal-geo-context' ),
			__( 'I acknowledge that enabling the remote provider transfers visitor IP addresses to MaxMind, Inc.', 'universal-geo-context' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->toggle_row(
			'remote_enabled',
			$settings['remote_enabled'],
			__( 'Enable remote provider', 'universal-geo-context' ),
			__( 'Requires the acknowledgement above in the same save, and the MaxMind account credentials above.', 'universal-geo-context' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->number_row(
			'remote_timeout',
			__( 'Request timeout (seconds)', 'universal-geo-context' ),
			(string) (int) $settings['remote_timeout'],
			__( 'Bounds how long a single remote lookup may hold a page view open. 1–5 seconds; default 2.', 'universal-geo-context' ),
			array(
				'id'  => 'universal_geo_remote_timeout',
				'min' => '1',
				'max' => '5',
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static close tag.
		echo $this->components->settings_card_close();
	}

	/**
	 * Renders the managed MaxMind database settings card.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 *
	 * @return void
	 */
	private function render_managed_database_card( array $settings ): void {
		$badge = $this->components->status_badge(
			$settings['maxmind_managed_enabled'] ? __( 'Managed', 'universal-geo-context' ) : __( 'Manual', 'universal-geo-context' ),
			$settings['maxmind_managed_enabled'] ? 'active' : 'disabled'
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_open(
			__( 'MaxMind Database', 'universal-geo-context' ),
			__( 'Automatic GeoLite2 Country downloads using the MaxMind account credentials above.', 'universal-geo-context' ),
			$badge
		);

		echo '<div id="universal-geo-managed-database"></div>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->toggle_row(
			'maxmind_managed_enabled',
			$settings['maxmind_managed_enabled'],
			__( 'Enable managed database', 'universal-geo-context' ),
			__( 'Let this plugin download and install the GeoLite2 Country database itself.', 'universal-geo-context' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->toggle_row(
			'maxmind_managed_auto_update_enabled',
			$settings['maxmind_managed_auto_update_enabled'],
			__( 'Enable automatic updates', 'universal-geo-context' ),
			__( 'Requires "Enable managed database" above, in the same save.', 'universal-geo-context' )
		);

		$select = sprintf(
			'<select id="universal_geo_maxmind_managed_auto_update_frequency" name="maxmind_managed_auto_update_frequency"><option value="weekly"%1$s>%2$s</option><option value="twice_weekly"%3$s>%4$s</option></select>',
			selected( $settings['maxmind_managed_auto_update_frequency'], 'weekly', false ),
			esc_html__( 'Weekly', 'universal-geo-context' ),
			selected( $settings['maxmind_managed_auto_update_frequency'], 'twice_weekly', false ),
			esc_html__( 'Twice weekly', 'universal-geo-context' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->select_row(
			'maxmind_managed_auto_update_frequency',
			__( 'Update frequency', 'universal-geo-context' ),
			__( 'GeoLite2 Country is published at most twice a week.', 'universal-geo-context' ),
			$select,
			array( 'id' => 'universal_geo_maxmind_managed_auto_update_frequency' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->toggle_row(
			'maxmind_managed_retain_previous',
			$settings['maxmind_managed_retain_previous'],
			__( 'Retain previous version', 'universal-geo-context' ),
			__( 'Keep one prior generation on disk after a successful update, restorable below.', 'universal-geo-context' )
		);

		$this->render_managed_database_status();

		ob_start();
		$this->render_managed_database_action_button( 'universal_geo_maxmind_database_download', __( 'Download now', 'universal-geo-context' ), false );
		echo ' ';
		$this->render_managed_database_action_button( 'universal_geo_maxmind_database_validate', __( 'Check database', 'universal-geo-context' ), false );
		echo ' ';
		$this->render_managed_database_action_button( 'universal_geo_maxmind_database_remove', __( 'Remove managed database', 'universal-geo-context' ), true );
		echo ' ';
		$this->render_managed_database_action_button( 'universal_geo_maxmind_database_restore', __( 'Restore previous version', 'universal-geo-context' ), true );
		$footer = ob_get_clean();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in component renderer.
		echo $this->components->settings_card_footer( $footer );
	}

	/**
	 * Renders managed database status inside the card body.
	 *
	 * @return void
	 */
	private function render_managed_database_status(): void {
		$installed_path = $this->database_manager->installed_path();
		$status         = $this->database_manager->status();

		$this->report->render_definition_list(
			array(
				'installed'    => '' !== $installed_path ? __( 'Yes', 'universal-geo-context' ) : __( 'No', 'universal-geo-context' ),
				'last_attempt' => null !== $status['last_attempt_at'] ? gmdate( 'Y-m-d H:i:s', $status['last_attempt_at'] ) . ' UTC' : __( 'Never', 'universal-geo-context' ),
				'last_result'  => '' !== $status['last_result_code'] ? $status['last_result_code'] : __( 'None', 'universal-geo-context' ),
			)
		);
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
			'<form method="post" action="%1$s" class="ugc-ui-inline-form">',
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
