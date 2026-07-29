<?php
/**
 * Settings + Diagnostics admin screen under Settings → Geo Context.
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
 * `Settings → Geo Context` (`add_options_page`, `manage_options`), two tabs:
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
	 * @param DiagnosticsService $diagnostics Supplies the (masked) diagnostics report and the Site Health verdict.
	 * @param ServerRequest      $request     The boot-time $_SERVER snapshot; source of the raw peer for "Trust this peer".
	 */
	public function __construct(
		private readonly DiagnosticsService $diagnostics,
		private readonly ServerRequest $request
	) {
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
			__( 'Geo Context', 'universal-geo-context' ),
			__( 'Geo Context', 'universal-geo-context' ),
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
			'default_country'       => isset( $_POST['default_country'] ) ? wp_unslash( $_POST['default_country'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'trusted_proxies'       => isset( $_POST['trusted_proxies'] )
				? $this->parse_trusted_proxies_textarea( wp_unslash( $_POST['trusted_proxies'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: array(),
			'trust_cloudflare'      => ! empty( $_POST['trust_cloudflare'] ),
			'derived_cache_enabled' => ! empty( $_POST['derived_cache_enabled'] ),
			'derived_cache_ttl'     => isset( $_POST['derived_cache_ttl'] ) ? wp_unslash( $_POST['derived_cache_ttl'] ) : 900, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'maxmind_db_path'       => isset( $_POST['maxmind_db_path'] ) ? wp_unslash( $_POST['maxmind_db_path'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);

		$sanitized = Settings::sanitize( $raw );

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

		Settings::save( $sanitized );
		GeoCache::bump_epoch();

		if ( $maxmind_path_rejected ) {
			$this->redirect_with_notice( 'maxmind_path_rejected', 'warning' );
			return;
		}

		$this->redirect_with_notice( 'saved', 'success' );
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
	 * @return void
	 */
	public function handle_dismiss_notice(): void {
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
			'saved'                 => __( 'Settings saved.', 'universal-geo-context' ),
			'peer_trusted'          => __( 'The current peer address has been added to Trusted Proxies.', 'universal-geo-context' ),
			'cf_preset_enabled'     => __( 'The Cloudflare preset has been enabled.', 'universal-geo-context' ),
			'maxmind_path_rejected' => __( 'Other settings were saved, but the MaxMind database path could not be validated (not a readable file, or outside the WordPress content directory) — the previous value was kept.', 'universal-geo-context' ),
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
			'<td><input type="text" maxlength="2" id="universal_geo_default_country" name="default_country" value="%2$s" /></td></tr>',
			esc_html__( 'Default country', 'universal-geo-context' ),
			esc_attr( $settings['default_country'] )
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
		submit_button();
		echo '</form>';
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
	 * @param array<string, mixed> $values Scalar or null values only.
	 *
	 * @return void
	 */
	private function render_definition_list( array $values ): void {
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

			printf( '<dt>%1$s</dt><dd>%2$s</dd>', esc_html( (string) $key ), esc_html( $display ) );
		}

		echo '</dl>';
	}
}
