<?php
/**
 * Settings + Diagnostics admin screen under Settings → Universal Geo Context.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Http\IpUtils;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Settings;

/**
 * `Settings → Universal Geo Context` (`add_options_page`, `manage_options`), two tabs:
 * Settings and Diagnostics (Revision 3 §11). `WC_Settings_Page` is
 * deliberately not used — this plugin must have a settings screen on sites
 * with no WooCommerce.
 *
 * Hand-rolled form posting to `admin_post_universal_geo_save_settings` with
 * nonce + capability check, then a PRG redirect carrying `universal_geo_msg`
 * / `universal_geo_typ` query args rendered on `admin_notices` — the
 * `universal-multicurrency` notice pattern Revision 3 §11 names.
 *
 * Owns all rendering, notices, nonces, capability checks, the PRG redirect,
 * the first-run notice (per-user dismissal meta), and the two affordances
 * ("Trust this peer", "Enable the Cloudflare preset") — explicit admin
 * clicks, never automatic. `DiagnosticsService` supplies the (masked)
 * report data for display; the raw peer address needed to actually persist
 * "Trust this peer" comes from this class's own injected `ServerRequest`,
 * never from the privacy-masked report.
 *
 * @internal
 * @final
 */
final class AdminScreen {

	/**
	 * The options-page slug.
	 */
	private const PAGE_SLUG = 'universal-geo-context';

	/**
	 * User meta key recording first-run-notice dismissal, per user.
	 */
	private const NOTICE_DISMISSED_META = 'universal_geo_first_run_notice_dismissed';

	/**
	 * Stores the injected dependencies.
	 *
	 * @param DiagnosticsService $diagnostics      Supplies the (masked) diagnostics report and the Site Health verdict.
	 * @param ServerRequest      $request          The boot-time $_SERVER snapshot; source of the raw peer for "Trust this peer".
	 * @param UpdateScheduler    $update_scheduler Reconciled explicitly after a settings save (M6) — the same instance Plugin::build_graph() constructed; this class never constructs its own (the composition-root invariant), and never calls register() itself (that stays Plugin::init()'s job).
	 */
	public function __construct(
		private readonly DiagnosticsService $diagnostics,
		private readonly ServerRequest $request,
		private readonly UpdateScheduler $update_scheduler
	) {
	}

	/**
	 * Deletes the first-run-notice dismissal meta for every user (M4, closing
	 * the M2/M3 uninstall-ownership gap): `delete_metadata()`'s `$delete_all`
	 * argument removes the key across every user at once, since a per-user
	 * meta key has no single object id to target with `delete_user_meta()`.
	 * Called by `uninstall.php` alongside `Settings::uninstall()` and
	 * `GeoCache::uninstall()`.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_metadata( 'user', 0, self::NOTICE_DISMISSED_META, '', true );
	}

	/**
	 * Wires every hook this screen needs.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_universal_geo_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_universal_geo_trust_peer', array( $this, 'handle_trust_peer' ) );
		add_action( 'admin_post_universal_geo_enable_cf_preset', array( $this, 'handle_enable_cf_preset' ) );
		add_action( 'admin_post_universal_geo_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_saved_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_first_run_notice' ) );
	}

	/**
	 * Adds the options page under Settings.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'Universal Geo Context', 'universal-geo-context' ),
			__( 'Universal Geo Context', 'universal-geo-context' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the page shell and the active tab.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = $this->active_tab();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Universal Geo Context', 'universal-geo-context' ) . '</h1>';
		$this->render_tab_nav( $tab );

		if ( 'diagnostics' === $tab ) {
			$this->render_diagnostics_tab();
		} else {
			$this->render_settings_tab();
		}

		echo '</div>';
	}

	/**
	 * Handles the settings form submission: capability + nonce, sanitize,
	 * persist, bump the cache epoch, PRG redirect.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-geo-context' ) );
		}

		check_admin_referer( 'universal_geo_save_settings' );

		$previous = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) );

		// Every value below is re-sanitized by Settings::sanitize() immediately
		// after (type checks, regex shape validation, range clamping) — this
		// array is raw input on its way there, never used or persisted as-is.
		$raw = array(
			'default_country'              => isset( $_POST['default_country'] ) ? wp_unslash( $_POST['default_country'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'trusted_proxies'              => isset( $_POST['trusted_proxies'] )
				? $this->parse_trusted_proxies_textarea( wp_unslash( $_POST['trusted_proxies'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: array(),
			'trust_cloudflare'             => ! empty( $_POST['trust_cloudflare'] ),
			'derived_cache_enabled'        => ! empty( $_POST['derived_cache_enabled'] ),
			'derived_cache_ttl'            => isset( $_POST['derived_cache_ttl'] ) ? wp_unslash( $_POST['derived_cache_ttl'] ) : 900, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'maxmind_db_path'              => isset( $_POST['maxmind_db_path'] ) ? wp_unslash( $_POST['maxmind_db_path'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'remote_enabled'               => ! empty( $_POST['remote_enabled'] ),
			'remote_transfer_acknowledged' => ! empty( $_POST['remote_transfer_acknowledged'] ),
			'remote_account_id'            => $this->submitted_credential( 'remote_account_id', $previous['remote_account_id'] ),
			'remote_license_key'           => $this->submitted_credential( 'remote_license_key', $previous['remote_license_key'] ),
			'remote_timeout'               => isset( $_POST['remote_timeout'] ) ? wp_unslash( $_POST['remote_timeout'] ) : 2, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);

		$sanitized = Settings::sanitize( $raw );

		// The dedicated, non-silent warning M5 D2 requires: an admin typed a
		// non-empty default_country that Settings::sanitize() could not turn
		// into a real country (malformed shape, or a syntactically-valid but
		// nonexistent code like 'ZZ') — detected by comparing what was
		// submitted against what sanitize() actually produced, the same
		// technique the acknowledgement check below already uses. Rejection
		// keeps the previously stored value, mirroring maxmind_path_rejected.
		$default_country_rejected = false;
		$raw_default_country      = is_string( $raw['default_country'] ) ? strtoupper( trim( $raw['default_country'] ) ) : '';

		if ( '' !== $raw_default_country && '' === $sanitized['default_country'] ) {
			$sanitized['default_country'] = $previous['default_country'];
			$default_country_rejected     = true;
		}

		// Filesystem validation (realpath/is_file/is_readable/containment) is
		// exclusively performed here, on the admin-panel surface — never
		// inside Settings::sanitize() (the purity boundary, Settings.php's
		// own docblock). Rejection retains the previously stored value
		// rather than the syntactically-valid-but-unusable submitted one;
		// acceptance stores the submitted path verbatim, not its realpath().
		$maxmind_path_rejected = false;

		if ( '' !== $sanitized['maxmind_db_path'] && ! $this->maxmind_path_is_valid( $sanitized['maxmind_db_path'] ) ) {
			$sanitized['maxmind_db_path'] = $previous['maxmind_db_path'];
			$maxmind_path_rejected        = true;
		}

		// The dedicated, non-silent warning Revision M4 requires: an admin
		// checked "Enable the remote provider" but Settings::sanitize()'s own
		// structural rule forced it back to false (no acknowledgement in this
		// same submission). Detected by comparing what was submitted against
		// what sanitize() actually produced, rather than duplicating
		// sanitize()'s own acknowledgement logic here.
		$enable_blocked_by_acknowledgement = ! empty( $_POST['remote_enabled'] ) && ! $sanitized['remote_enabled'];

		Settings::save( $sanitized );
		GeoCache::bump_epoch();

		// M6: reconciles the cron schedule against the just-saved values
		// immediately, rather than waiting for the next admin page load's
		// own Plugin::init() -> register() call to notice the change.
		$this->update_scheduler->ensure_scheduled(
			$sanitized['maxmind_managed_auto_update_enabled'],
			$sanitized['maxmind_managed_auto_update_frequency']
		);

		if ( $default_country_rejected ) {
			$this->redirect_with_notice( 'default_country_rejected', 'warning' );
			return;
		}

		if ( $maxmind_path_rejected ) {
			$this->redirect_with_notice( 'maxmind_path_rejected', 'warning' );
			return;
		}

		if ( $enable_blocked_by_acknowledgement ) {
			$this->redirect_with_notice( 'remote_enable_requires_acknowledgement', 'warning' );
			return;
		}

		$this->redirect_with_notice( 'saved', 'success' );
	}

	/**
	 * Resolves one submitted credential field's effective raw value
	 * (credential clearing behavior, M4): checking "Clear stored
	 * credentials" forces both credential fields to '' regardless of what
	 * (if anything) was also typed; otherwise a blank submission — including
	 * a field omitted entirely, e.g. because it was rendered `disabled`
	 * while a wp-config.php constant is in effect — means "leave the
	 * previously stored value unchanged" (the same shape a password field
	 * that never round-trips its own value needs), not "erase it". Only a
	 * genuinely non-blank submission replaces the stored value. This merge
	 * logic is deliberately kept here, not inside Settings::sanitize(): that
	 * method's own purity boundary docblock already reserves "merge against
	 * previously stored state" for AdminScreen alone (the maxmind_db_path
	 * precedent).
	 *
	 * @param string $post_key         The $_POST key for this credential.
	 * @param string $previous_value   The previously sanitized, stored value.
	 *
	 * @return string
	 */
	private function submitted_credential( string $post_key, string $previous_value ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified once, by the caller (handle_save_settings()), before this helper runs.
		if ( ! empty( $_POST['remote_clear_credentials'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified once, by the caller (handle_save_settings()), before this helper runs.
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
	 * Whether both legacy remote-provider credential constants are defined
	 * as non-empty strings — the same pair-wise "both or neither" test
	 * `Plugin::resolved_maxmind_credentials()` applies at graph build (as
	 * one precedence level among several, as of M6), kept here as its own
	 * small, display-only check rather than shared: this one decides whether
	 * to render the two legacy credential fields disabled, never resolution
	 * behavior itself. The M6F admin UI restructuring is expected to extend
	 * this check to the new canonical constant pair too.
	 *
	 * @return bool
	 */
	private function remote_credentials_locked_by_constants(): bool {
		return defined( 'UNIVERSAL_GEO_REMOTE_ACCOUNT_ID' ) && is_string( UNIVERSAL_GEO_REMOTE_ACCOUNT_ID ) && '' !== UNIVERSAL_GEO_REMOTE_ACCOUNT_ID
			&& defined( 'UNIVERSAL_GEO_REMOTE_LICENSE_KEY' ) && is_string( UNIVERSAL_GEO_REMOTE_LICENSE_KEY ) && '' !== UNIVERSAL_GEO_REMOTE_LICENSE_KEY;
	}

	/**
	 * Filesystem validation for a submitted maxmind_db_path (Revision 3 §7,
	 * M3 architecture report §6 3B): the path must resolve via realpath(),
	 * be a readable regular file, and be contained under WP_CONTENT_DIR.
	 * Never called from Settings::sanitize() — this is the one place in the
	 * codebase filesystem I/O against an admin-supplied path is allowed.
	 *
	 * @param string $path An already syntactically-sanitized absolute path.
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
	 * "Trust this peer": adds the observed peer's /32 (or /128) to
	 * trusted_proxies. An explicit admin click, never automatic.
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

		$this->redirect_with_notice( 'peer_trusted', 'success' );
	}

	/**
	 * "Enable the Cloudflare preset": sets trust_cloudflare. An explicit
	 * admin click, never automatic — only ever shown by the diagnostics tab
	 * when the peer is actually inside a Cloudflare range.
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

		$this->redirect_with_notice( 'cf_preset_enabled', 'success' );
	}

	/**
	 * Dismisses the first-run notice for the current user only.
	 *
	 * M5: capability-checked before the nonce, exactly like every other
	 * `admin_post_*` handler in this class — closing an inconsistency this
	 * was the only handler missing (the practical risk was already low, since
	 * the dismiss link is only ever rendered to `manage_options` users, and
	 * the nonce is per-user, but the handler should not rely on that alone).
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
	 * Renders the PRG notice after a save/affordance redirect.
	 *
	 * @return void
	 */
	public function maybe_render_saved_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only query args after a PRG redirect this class itself issued.
		if ( ! isset( $_GET['universal_geo_msg'], $_GET['universal_geo_typ'], $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
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
	 * Shows a one-time notice, dismissible per user, when forwarding
	 * headers are present but no trusted proxies are configured — the same
	 * verdict the trusted-proxy Site Health test uses (Revision 3 §11).
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

		$diagnostics_url = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => 'diagnostics',
			),
			admin_url( 'options-general.php' )
		);
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
	 * Whether the first-run notice's condition currently holds — exactly
	 * the trusted-proxy Site Health test's own critical verdict, reused
	 * rather than re-derived.
	 *
	 * @return bool
	 */
	private function should_show_first_run_notice(): bool {
		return 'critical' === $this->diagnostics->trusted_proxy_site_status_test()['status'];
	}

	/**
	 * The message text for one notice key, or '' when unrecognized.
	 *
	 * @param string $message_key A sanitize_key()'d query-arg value.
	 *
	 * @return string
	 */
	private function notice_message( string $message_key ): string {
		$messages = array(
			'saved'                                  => __( 'Settings saved.', 'universal-geo-context' ),
			'peer_trusted'                           => __( 'The current peer address has been added to Trusted Proxies.', 'universal-geo-context' ),
			'cf_preset_enabled'                      => __( 'The Cloudflare preset has been enabled.', 'universal-geo-context' ),
			'default_country_rejected'               => __( 'Other settings were saved, but the default country could not be recognized as a real ISO 3166-1 country code — the previous value was kept.', 'universal-geo-context' ),
			'maxmind_path_rejected'                  => __( 'Other settings were saved, but the MaxMind database path could not be validated (not a readable file, or outside the WordPress content directory) — the previous value was kept.', 'universal-geo-context' ),
			'remote_enable_requires_acknowledgement' => __( 'Other settings were saved, but the remote provider could not be enabled: you must acknowledge that visitor IP addresses will be transferred to MaxMind, Inc. in the same submission that enables it.', 'universal-geo-context' ),
		);

		return $messages[ $message_key ] ?? '';
	}

	/**
	 * Resolves the active tab from the query string. Anything other than
	 * 'diagnostics' is treated as 'settings' — an unrecognized tab never
	 * produces a blank or broken screen.
	 *
	 * @return string
	 */
	private function active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection, no state change.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

		return 'diagnostics' === $tab ? 'diagnostics' : 'settings';
	}

	/**
	 * Splits a trusted-proxies textarea submission into one trimmed entry
	 * per non-empty line. Purely syntactic — Settings::sanitize() performs
	 * the actual CIDR validation; this only turns lines into an array.
	 *
	 * @param string $raw The raw textarea submission.
	 *
	 * @return string[]
	 */
	private function parse_trusted_proxies_textarea( string $raw ): array {
		$lines = preg_split( '/[\r\n]+/', $raw );
		$lines = is_array( $lines ) ? $lines : array();

		return array_values( array_filter( array_map( 'trim', $lines ), static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * Builds the PRG redirect URL carrying the notice query args.
	 *
	 * @param string $message_key One of notice_message()'s keys.
	 * @param string $type        'success' or 'warning'.
	 *
	 * @return string
	 */
	private function notice_redirect_url( string $message_key, string $type ): string {
		return add_query_arg(
			array(
				'page'              => self::PAGE_SLUG,
				'universal_geo_msg' => $message_key,
				'universal_geo_typ' => $type,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Redirects to the settings page carrying a notice, then terminates the
	 * request (the standard WordPress PRG pattern) — not unit-testable
	 * beyond notice_redirect_url() itself, since exit cannot run inside the
	 * test process; verified via the live browser acceptance step instead.
	 *
	 * @param string $message_key One of notice_message()'s keys.
	 * @param string $type        'success' or 'warning'.
	 *
	 * @return void
	 */
	private function redirect_with_notice( string $message_key, string $type ): void {
		wp_safe_redirect( $this->notice_redirect_url( $message_key, $type ) );
		exit;
	}

	/**
	 * Renders the tab navigation.
	 *
	 * @param string $active 'settings' or 'diagnostics'.
	 *
	 * @return void
	 */
	private function render_tab_nav( string $active ): void {
		$base = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		echo '<h2 class="nav-tab-wrapper">';
		printf(
			'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
			esc_url( $base ),
			esc_attr( 'settings' === $active ? 'nav-tab-active' : '' ),
			esc_html__( 'Settings', 'universal-geo-context' )
		);
		printf(
			'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
			esc_url( add_query_arg( 'tab', 'diagnostics', $base ) ),
			esc_attr( 'diagnostics' === $active ? 'nav-tab-active' : '' ),
			esc_html__( 'Diagnostics', 'universal-geo-context' )
		);
		echo '</h2>';
	}

	/**
	 * Renders the Settings tab form.
	 *
	 * @return void
	 */
	private function render_settings_tab(): void {
		$settings = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) );

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

		$this->render_remote_settings_section( $settings );

		submit_button();
		echo '</form>';
	}

	/**
	 * Renders the remote-provider settings section (M4): disabled by
	 * default, requiring both the structural transfer acknowledgement and a
	 * credential pair. Credential fields never round-trip the stored value
	 * (`type="password"`, left blank) — submitted_credential() treats a
	 * blank submission as "keep the existing value", so leaving these blank
	 * on an otherwise-unrelated settings save does not erase them; the
	 * "Clear stored credentials" checkbox is the only way to actually blank
	 * them. When both credential constants are defined, the fields are
	 * rendered disabled (so the browser never submits an override for them
	 * at all) with an explanatory note — the constants supply credentials
	 * only and never affect whether `remote_enabled` itself is editable.
	 *
	 * @param array<string, mixed> $settings The sanitized settings array.
	 *
	 * @return void
	 */
	private function render_remote_settings_section( array $settings ): void {
		$locked = $this->remote_credentials_locked_by_constants();

		echo '<h2>' . esc_html__( 'Remote provider (MaxMind GeoLite2 Country Web Service)', 'universal-geo-context' ) . '</h2>';

		printf(
			'<p>%s</p>',
			esc_html__(
				'Disabled by default. When enabled, this plugin sends visitor IP addresses to MaxMind, Inc. at geolite.info to derive a country — the one exception to this plugin never letting an IP address leave the server. Enabling requires both a credential pair and the acknowledgement below, in the same submission.',
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
			esc_html__( 'Requires the acknowledgement above in the same save, and a credential pair below.', 'universal-geo-context' )
		);

		$disabled_attr = $locked ? ' disabled="disabled"' : '';

		printf(
			'<tr><th scope="row"><label for="universal_geo_remote_account_id">%1$s</label></th>' .
			'<td><input type="password" autocomplete="off" class="regular-text" id="universal_geo_remote_account_id" name="remote_account_id" value="" placeholder="%2$s"%3$s />' .
			'<p class="description">%4$s</p></td></tr>',
			esc_html__( 'Account ID', 'universal-geo-context' ),
			esc_attr( '' !== $settings['remote_account_id'] ? __( 'Currently configured (hidden)', 'universal-geo-context' ) : __( 'Not configured', 'universal-geo-context' ) ),
			$disabled_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed literal (' disabled="disabled"' or ''), never user input; esc_attr() would corrupt the embedded quotes.
			esc_html(
				$locked
				? __( 'Supplied via a wp-config.php constant and cannot be edited here.', 'universal-geo-context' )
				: __( 'Leave blank to keep the stored value unchanged.', 'universal-geo-context' )
			)
		);

		printf(
			'<tr><th scope="row"><label for="universal_geo_remote_license_key">%1$s</label></th>' .
			'<td><input type="password" autocomplete="off" class="regular-text" id="universal_geo_remote_license_key" name="remote_license_key" value="" placeholder="%2$s"%3$s />' .
			'<p class="description">%4$s</p></td></tr>',
			esc_html__( 'License key', 'universal-geo-context' ),
			esc_attr( '' !== $settings['remote_license_key'] ? __( 'Currently configured (hidden)', 'universal-geo-context' ) : __( 'Not configured', 'universal-geo-context' ) ),
			$disabled_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed literal (' disabled="disabled"' or ''), never user input; esc_attr() would corrupt the embedded quotes.
			esc_html(
				$locked
				? __( 'Supplied via a wp-config.php constant and cannot be edited here.', 'universal-geo-context' )
				: __( 'Leave blank to keep the stored value unchanged.', 'universal-geo-context' )
			)
		);

		if ( ! $locked ) {
			printf(
				'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="remote_clear_credentials" value="1" /> %2$s</label></td></tr>',
				esc_html__( 'Clear stored credentials', 'universal-geo-context' ),
				esc_html__( 'Blanks both fields above on save, regardless of what (if anything) is also typed.', 'universal-geo-context' )
			);
		}

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
	 * Renders the Diagnostics tab: the full report plus the two
	 * affordances, each only shown when applicable.
	 *
	 * @return void
	 */
	private function render_diagnostics_tab(): void {
		$report = $this->diagnostics->report();

		echo '<h2>' . esc_html__( 'Client address', 'universal-geo-context' ) . '</h2>';
		$this->render_definition_list( $report['client_address'] );

		echo '<h2>' . esc_html__( 'Trusted proxies', 'universal-geo-context' ) . '</h2>';
		$this->render_definition_list( $report['trusted_proxies'] );

		if ( ! $report['trusted_proxies']['peer_trusted'] ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=universal_geo_trust_peer' ), 'universal_geo_trust_peer' );
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( $url ),
				esc_html__( 'Trust this peer', 'universal-geo-context' )
			);
		}

		echo '<h2>' . esc_html__( 'Forwarding headers', 'universal-geo-context' ) . '</h2>';
		foreach ( $report['forwarding_headers'] as $row ) {
			$this->render_definition_list( $row );
		}

		echo '<h2>' . esc_html__( 'Cloudflare', 'universal-geo-context' ) . '</h2>';
		$this->render_definition_list( $report['cloudflare'] );

		if ( $report['cloudflare']['peer_in_cf_ranges'] && ! $report['cloudflare']['preset_enabled'] ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=universal_geo_enable_cf_preset' ), 'universal_geo_enable_cf_preset' );
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( $url ),
				esc_html__( 'Enable the Cloudflare preset', 'universal-geo-context' )
			);
		}

		echo '<h2>' . esc_html__( 'WooCommerce', 'universal-geo-context' ) . '</h2>';
		$this->render_definition_list( $report['woocommerce'] );

		echo '<h2>' . esc_html__( 'MaxMind', 'universal-geo-context' ) . '</h2>';
		$this->render_definition_list( $report['maxmind'] );

		echo '<h2>' . esc_html__( 'Remote provider', 'universal-geo-context' ) . '</h2>';
		if ( $report['remote']['enabled'] ) {
			// The documented honesty note (M4 frozen decision): the
			// "Providers" probe table below visits every provider,
			// including this one when enabled — viewing this page is not a
			// side-effect-free read while the remote provider is on.
			printf(
				'<p><em>%s</em></p>',
				esc_html__(
					'The remote provider is enabled — viewing this page performs one live request to the configured remote service, as part of the provider probe table below.',
					'universal-geo-context'
				)
			);
		}
		$this->render_definition_list( $report['remote'] );

		echo '<h2>' . esc_html__( 'Providers', 'universal-geo-context' ) . '</h2>';
		foreach ( $report['providers'] as $row ) {
			$this->render_definition_list( $row );
		}

		echo '<h2>' . esc_html__( 'Provider health', 'universal-geo-context' ) . '</h2>';
		foreach ( $report['provider_health'] as $provider_id => $row ) {
			echo '<h3>' . esc_html( (string) $provider_id ) . '</h3>';
			$this->render_definition_list( $row );
		}

		echo '<h2>' . esc_html__( 'Environment', 'universal-geo-context' ) . '</h2>';
		$this->render_definition_list( $report['environment'] );
	}

	/**
	 * Renders one flat associative array as a definition list — the
	 * smallest generic renderer that keeps every report section readable
	 * without a template per section.
	 *
	 * Each key is looked up in DiagnosticsService::field_labels() (M5) for a
	 * translated, human-readable label — shared with the Site Health
	 * `debug_information` section and the WP-CLI `diagnostics` command
	 * rather than duplicated per consumer. A key the map doesn't (yet) know
	 * about falls back to the raw key rather than being hidden — the report
	 * must never drop a field just because its label mapping is missing.
	 *
	 * @param array<string, mixed> $values Scalar or null values only.
	 *
	 * @return void
	 */
	private function render_definition_list( array $values ): void {
		$labels = $this->diagnostics->field_labels();

		echo '<dl>';

		foreach ( $values as $key => $value ) {
			if ( is_bool( $value ) ) {
				$display = $value ? __( 'yes', 'universal-geo-context' ) : __( 'no', 'universal-geo-context' );
			} elseif ( is_array( $value ) ) {
				$display = implode( ', ', $value );
			} elseif ( null === $value ) {
				$display = __( 'n/a', 'universal-geo-context' );
			} else {
				$display = (string) $value;
			}

			$label = $labels[ (string) $key ] ?? (string) $key;

			printf( '<dt>%1$s</dt><dd>%2$s</dd>', esc_html( $label ), esc_html( $display ) );
		}

		echo '</dl>';
	}
}
