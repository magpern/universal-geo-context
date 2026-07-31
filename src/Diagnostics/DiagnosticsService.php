<?php
/**
 * Structured diagnostics report and the trusted-proxy Site Health test.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Diagnostics;

use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\IpUtils;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\Plugin;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Providers\Remote\ReferenceRemoteProvider;
use UniversalGeo\Resolver\ContextResolver;

/**
 * One class, one structured report, consumed by the admin Diagnostics tab
 * (`AdminScreen`) and — from M5 — Site Health `debug_information` and
 * WP-CLI (Revision 3 §12: "one service until it demonstrably outgrows one
 * file"). M2 ships the trusted-proxy Site Health test only; the MaxMind and
 * remote tests, and the provider-health option, arrive in M3/M4.
 *
 * `report()`'s "result" section calls `Plugin::instance()->context()` — the
 * plugin's own public singleton accessor, already used identically by
 * `src/api.php` — rather than resolving independently, so the admin sees
 * exactly what consumers see (Revision 3 §12). This is not a peer-service
 * construction: no `new` is involved, only the frozen public boundary
 * `Plugin` itself exposes.
 *
 * Every address anywhere in the report passes through `IpUtils::mask()`
 * (via `ClientIpResolver::explain()`, or directly here) before being
 * returned — never a raw IP (privacy invariant P5).
 *
 * @internal
 * @final
 */
final class DiagnosticsService {

	/**
	 * Site Health test id (Revision 3 §12), a class constant so `AdminScreen`
	 * and any later consumer share one source of truth for it.
	 */
	public const TEST_TRUSTED_PROXY = 'universal_geo_trusted_proxy';

	/**
	 * The MaxMind database Site Health test id (M3).
	 */
	public const TEST_MAXMIND = 'universal_geo_maxmind';

	/**
	 * The remote-provider Site Health test id (M4).
	 */
	public const TEST_REMOTE = 'universal_geo_remote_provider';

	/**
	 * The managed-database Site Health test id (M6) — the fourth v1.x test.
	 */
	public const TEST_MAXMIND_MANAGED = 'universal_geo_maxmind_managed';

	/**
	 * Build-age thresholds (days) for the MaxMind Site Health test (M3
	 * architecture report §6 3D).
	 */
	private const MAXMIND_CRITICAL_AGE_DAYS    = 90;
	private const MAXMIND_RECOMMENDED_AGE_DAYS = 30;

	/**
	 * Build-age thresholds (days) for the managed-database Site Health test
	 * (M6) — deliberately tighter than the custom-path MaxMind test's own
	 * 30/90-day thresholds above: a managed database updates itself
	 * automatically at most twice a week, so staying current is a much
	 * lower bar than for a manually-maintained custom path.
	 */
	private const MAXMIND_MANAGED_CRITICAL_AGE_DAYS    = 30;
	private const MAXMIND_MANAGED_RECOMMENDED_AGE_DAYS = 14;

	/**
	 * Bundled Cloudflare-range staleness thresholds, mirrored from the
	 * MaxMind build-age pattern Revision 3 §12 describes for the (M3)
	 * MaxMind test — used here only for the diagnostics report's own
	 * "ranges_age_days" figure, not for a Site Health test of its own
	 * (explicitly deferred to 1.1, per Revision 3 §12).
	 */
	private const SECONDS_PER_DAY = 86400;

	/**
	 * Stores the injected dependencies.
	 *
	 * @param ContextResolver     $resolver                 Supplies probe() for the provider table.
	 * @param ClientIpResolver    $ip_resolver              Supplies explain() and the trust verdicts.
	 * @param ServerRequest       $request                  The boot-time $_SERVER snapshot.
	 * @param TrustedProxies      $trusted_proxies          The effective trusted set.
	 * @param array               $settings                 The sanitized settings array (not the Settings class) — the only source of the cache knobs and the remote enabled/acknowledged flags, since GeoCache and ReferenceRemoteProvider's own scalars are not re-read from here.
	 * @param ProviderHealthStore $provider_health_store    Supplies the already-scrubbed, bounded provider-health record (M3) — also the source of the remote section's scrubbed recent-failure field (M4).
	 * @param MaxMindProvider     $maxmind_provider         The same instance the resolver uses (M3 F8) — diagnostics never opens a second reader.
	 * @param CircuitBreaker      $circuit_breaker          The same instance ReferenceRemoteProvider uses (M4) — read via state() only, never may_attempt()/report_*(), so viewing diagnostics never itself flips circuit state.
	 * @param string              $remote_credential_source One of 'constants', 'legacy_constants', 'settings', 'none' — resolved exactly once by Plugin::build_graph(); this class must not call defined() or re-derive the precedence itself (M4 frozen decision, extended M6).
	 * @param DatabaseManager     $database_manager         The same instance Plugin::build_graph() constructed (M6) — diagnostics never constructs its own, never triggers a download; status() only.
	 * @param string              $maxmind_path_source      One of 'constant', 'settings', 'managed', 'woocommerce', 'filter', 'none' — resolved exactly once by Plugin::resolved_maxmind_db_path() (M6); this class must not re-derive the precedence itself, the same frozen-decision shape $remote_credential_source already established.
	 */
	public function __construct(
		private readonly ContextResolver $resolver,
		private readonly ClientIpResolver $ip_resolver,
		private readonly ServerRequest $request,
		private readonly TrustedProxies $trusted_proxies,
		private readonly array $settings,
		private readonly ProviderHealthStore $provider_health_store,
		private readonly MaxMindProvider $maxmind_provider,
		private readonly CircuitBreaker $circuit_breaker,
		private readonly string $remote_credential_source,
		private readonly DatabaseManager $database_manager,
		private readonly string $maxmind_path_source
	) {
	}

	/**
	 * Builds the full structured report (Revision 3 §12's M2 sections, plus
	 * M3's maxmind and provider_health sections).
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		return array(
			'result'             => $this->result_section(),
			'client_address'     => $this->client_address_section(),
			'trusted_proxies'    => $this->trusted_proxies_section(),
			'forwarding_headers' => $this->ip_resolver->explain(),
			'cloudflare'         => $this->cloudflare_section(),
			'woocommerce'        => $this->woocommerce_section(),
			'maxmind'            => $this->maxmind_section(),
			'remote'             => $this->remote_section(),
			'maxmind_managed'    => $this->maxmind_managed_section(),
			'providers'          => $this->resolver->probe(),
			'provider_health'    => $this->provider_health_store->read(),
			'cache'              => $this->cache_section(),
			'environment'        => $this->environment_section(),
		);
	}

	/**
	 * Diagnostics slices used by the Overview dashboard — no live provider
	 * probe. Presentation-only aggregation of existing section builders.
	 *
	 * @return array<string, mixed>
	 */
	public function overview_sections(): array {
		return array(
			'trusted_proxies' => $this->trusted_proxies_section(),
			'remote'          => $this->remote_section(),
			'cache'           => $this->cache_section(),
			'environment'     => $this->environment_section(),
			'provider_health' => $this->provider_health_store->read(),
		);
	}

	/**
	 * Trusted Proxies page slices — no live provider probe.
	 *
	 * @return array<string, mixed>
	 */
	public function trusted_proxies_page_sections(): array {
		return array(
			'trusted_proxies' => $this->trusted_proxies_section(),
			'cloudflare'      => $this->cloudflare_section(),
		);
	}

	/**
	 * Worst Site Health status among the four plugin tests (M7 Overview badge).
	 *
	 * Uses the existing verdict methods as the single source of truth —
	 * critical beats recommended beats good.
	 *
	 * @return string One of 'critical', 'recommended', 'good'.
	 */
	public function worst_site_health_status(): string {
		$statuses = array(
			$this->trusted_proxy_site_status_test()['status'] ?? 'good',
			$this->maxmind_site_status_test()['status'] ?? 'good',
			$this->remote_site_status_test()['status'] ?? 'good',
			$this->maxmind_managed_site_status_test()['status'] ?? 'good',
		);

		if ( in_array( 'critical', $statuses, true ) ) {
			return 'critical';
		}

		if ( in_array( 'recommended', $statuses, true ) ) {
			return 'recommended';
		}

		return 'good';
	}

	/**
	 * Registers the trusted-proxy, MaxMind, and remote-provider Site Health
	 * tests, plus (M5) the Site Health Info screen's `debug_information` section.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_site_status_tests' ) );
		add_filter( 'debug_information', array( $this, 'add_debug_information' ) );
	}

	/**
	 * Adds a "Universal Geo Context" section to Tools → Site Health → Info
	 * (M5 — Revision 3 §23's "the completed diagnostics report"). Reuses
	 * report() verbatim rather than re-deriving any of its data, so the two
	 * screens can never drift: every value here already passed through the
	 * same masking/scrubbing report() itself guarantees (P5) — no credential,
	 * secret, or full IP address is ever exposed, exactly like the admin
	 * Diagnostics tab and the WP-CLI `diagnostics` command that reuse the
	 * same report(). Nested arrays are flattened to one field per leaf value,
	 * dot-joined, so the section stays readable in WordPress's own
	 * two-column Info table.
	 *
	 * @param array<string, mixed> $info WordPress's own debug_information structure.
	 *
	 * @return array<string, mixed>
	 */
	public function add_debug_information( array $info ): array {
		$info['universal-geo-context'] = array(
			'label'   => __( 'Universal Geo Context', 'universal-geo-context' ),
			'fields'  => $this->debug_information_fields( $this->report() ),
			'private' => false,
		);

		return $info;
	}

	/**
	 * Flattens report()'s nested section/row structure into
	 * `debug_information`'s flat `field_id => array{label, value}` shape.
	 *
	 * @param array<string, mixed> $report The report() return value.
	 *
	 * @return array<string, array{label: string, value: string}>
	 */
	private function debug_information_fields( array $report ): array {
		$fields = array();
		$labels = $this->field_labels();

		foreach ( $report as $section => $value ) {
			foreach ( $this->flatten_for_debug_information( $value ) as $suffix => $display_value ) {
				$field_id = '' !== $suffix ? $section . '.' . $suffix : (string) $section;

				// field_labels() is keyed by the bare field name (e.g.
				// 'last_error_class'), not the full dotted/bracketed path
				// (e.g. 'cloudflare.last_error_class' or '0.country_code') —
				// only the last path segment is looked up.
				$last_segment = (string) preg_replace( '/^.*[.\[]|\]$/', '', $suffix );

				$fields[ $field_id ] = array(
					'label' => $labels[ $last_segment ] ?? $field_id,
					'value' => $display_value,
				);
			}
		}

		return $fields;
	}

	/**
	 * Recursively reduces one report value to a flat `suffix => display
	 * string` map — the same generic shape AdminScreen::render_definition_list()
	 * and the WP-CLI `diagnostics` command both reduce report() sections to,
	 * kept here as this class's own reusable primitive rather than
	 * duplicated three times (M5).
	 *
	 * @param mixed  $value  A report leaf value, row, or list of rows.
	 * @param string $prefix The dot/bracket path built up so far.
	 *
	 * @return array<string, string>
	 */
	private function flatten_for_debug_information( $value, string $prefix = '' ): array {
		if ( ! is_array( $value ) ) {
			return array( $prefix => $this->stringify_for_debug_information( $value ) );
		}

		$is_list = array_is_list( $value );
		$rows    = array();

		foreach ( $value as $key => $item ) {
			$label = $is_list ? sprintf( '%s[%d]', $prefix, $key ) : ( '' !== $prefix ? $prefix . '.' . $key : (string) $key );
			$rows  = $rows + $this->flatten_for_debug_information( $item, $label );
		}

		return $rows;
	}

	/**
	 * Renders one report leaf value as a display string.
	 *
	 * @param mixed $value A report leaf value.
	 *
	 * @return string
	 */
	private function stringify_for_debug_information( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'yes', 'universal-geo-context' ) : __( 'no', 'universal-geo-context' );
		}

		if ( null === $value ) {
			return __( 'n/a', 'universal-geo-context' );
		}

		return (string) $value;
	}

	/**
	 * Translated, human-readable labels for every snake_case field key a
	 * report() section can contain (M5 admin-UX polish) — the single shared
	 * source `AdminScreen::render_definition_list()`, add_debug_information(),
	 * and the WP-CLI `diagnostics` command all consult, so the same key is
	 * never labeled three different ways. Built fresh per call rather than
	 * as a class constant, since the values must go through __() at
	 * runtime. Deliberately one flat map, not scoped per report section: no
	 * key collides in meaning across sections (e.g. "reason" and "available"
	 * mean the same thing in both the forwarding-headers rows and the
	 * provider-probe rows). A key this map doesn't (yet) know about is never
	 * hidden by a consumer — every caller falls back to the raw key.
	 *
	 * @return array<string, string>
	 */
	public function field_labels(): array {
		return array(
			'peer_masked'                => __( 'Peer address (masked)', 'universal-geo-context' ),
			'peer_classification'        => __( 'Peer classification', 'universal-geo-context' ),
			'client_masked'              => __( 'Resolved client address (masked)', 'universal-geo-context' ),
			'source_header'              => __( 'Source header', 'universal-geo-context' ),
			'is_public'                  => __( 'Public address', 'universal-geo-context' ),
			'chain_verified'             => __( 'Chain verified', 'universal-geo-context' ),
			'server_snapshot_drift'      => __( 'Server snapshot drift', 'universal-geo-context' ),
			'configured_count'           => __( 'Configured trusted proxies', 'universal-geo-context' ),
			'trust_cloudflare'           => __( 'Trust Cloudflare', 'universal-geo-context' ),
			'peer_trusted'               => __( 'Peer trusted', 'universal-geo-context' ),
			'matched_entry'              => __( 'Matched entry', 'universal-geo-context' ),
			'header'                     => __( 'Header', 'universal-geo-context' ),
			'present'                    => __( 'Present', 'universal-geo-context' ),
			'trusted'                    => __( 'Trusted', 'universal-geo-context' ),
			'reason'                     => __( 'Reason', 'universal-geo-context' ),
			'masked_value'               => __( 'Masked value', 'universal-geo-context' ),
			'preset_enabled'             => __( 'Cloudflare preset enabled', 'universal-geo-context' ),
			'peer_in_cf_ranges'          => __( 'Peer in Cloudflare ranges', 'universal-geo-context' ),
			'cf_ipcountry_present'       => __( 'CF-IPCountry header present', 'universal-geo-context' ),
			'ranges_date'                => __( 'Cloudflare ranges date', 'universal-geo-context' ),
			'ranges_age_days'            => __( 'Cloudflare ranges age (days)', 'universal-geo-context' ),
			'active'                     => __( 'WooCommerce active', 'universal-geo-context' ),
			'maxmind_integration_active' => __( 'WooCommerce MaxMind integration active', 'universal-geo-context' ),
			'license_key_present'        => __( 'WooCommerce MaxMind license key present', 'universal-geo-context' ),
			'mmdb_present'               => __( 'MaxMind database file present', 'universal-geo-context' ),
			'effective_path'             => __( 'Effective database path', 'universal-geo-context' ),
			'path_source'                => __( 'Database path source', 'universal-geo-context' ),
			'available'                  => __( 'Available', 'universal-geo-context' ),
			'reader_class_file'          => __( 'Reader class file', 'universal-geo-context' ),
			'database_type'              => __( 'Database type', 'universal-geo-context' ),
			'build_age_days'             => __( 'Database age (days)', 'universal-geo-context' ),
			'city_database_note'         => __( 'Notes', 'universal-geo-context' ),
			'enabled'                    => __( 'Enabled', 'universal-geo-context' ),
			'transfer_acknowledged'      => __( 'Transfer acknowledged', 'universal-geo-context' ),
			'credentials_present'        => __( 'Credentials present', 'universal-geo-context' ),
			'credential_source'          => __( 'Credential source', 'universal-geo-context' ),
			'endpoint_host'              => __( 'Endpoint host', 'universal-geo-context' ),
			'timeout_seconds'            => __( 'Timeout (seconds)', 'universal-geo-context' ),
			'circuit_state'              => __( 'Circuit breaker state', 'universal-geo-context' ),
			'recent_failure'             => __( 'Recent failure', 'universal-geo-context' ),
			'installed'                  => __( 'Installed', 'universal-geo-context' ),
			'auto_update_enabled'        => __( 'Automatic updates enabled', 'universal-geo-context' ),
			'auto_update_frequency'      => __( 'Update frequency', 'universal-geo-context' ),
			'retain_previous'            => __( 'Retain previous version', 'universal-geo-context' ),
			'last_attempt_at'            => __( 'Last attempt at (timestamp)', 'universal-geo-context' ),
			'last_success_at'            => __( 'Last success at (timestamp)', 'universal-geo-context' ),
			'last_result_code'           => __( 'Last result code', 'universal-geo-context' ),
			'installed_build_epoch'      => __( 'Installed build epoch', 'universal-geo-context' ),
			'provider'                   => __( 'Provider', 'universal-geo-context' ),
			'country_code'               => __( 'Country code', 'universal-geo-context' ),
			'region_code'                => __( 'Region code', 'universal-geo-context' ),
			'last_error_class'           => __( 'Last error class', 'universal-geo-context' ),
			'last_error_message'         => __( 'Last error message', 'universal-geo-context' ),
			'approx_count'               => __( 'Approximate failure count', 'universal-geo-context' ),
			'last_seen_at'               => __( 'Last seen at (timestamp)', 'universal-geo-context' ),
			'derived_cache_enabled'      => __( 'Derived-context cache enabled', 'universal-geo-context' ),
			'derived_cache_ttl'          => __( 'Cache TTL (seconds)', 'universal-geo-context' ),
			'object_cache_active'        => __( 'Persistent object cache active', 'universal-geo-context' ),
			'php_version'                => __( 'PHP version', 'universal-geo-context' ),
			'wp_version'                 => __( 'WordPress version', 'universal-geo-context' ),
			'plugin_version'             => __( 'Plugin version', 'universal-geo-context' ),
			'confidence'                 => __( 'Confidence', 'universal-geo-context' ),
			'is_cached'                  => __( 'From cache', 'universal-geo-context' ),
			'source'                     => __( 'Source', 'universal-geo-context' ),
		);
	}

	/**
	 * Adds the trusted-proxy direct test to WordPress's Site Health test list.
	 *
	 * @param array<string, array<string, mixed>> $tests WordPress's own site_status_tests structure.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function add_site_status_tests( array $tests ): array {
		$tests['direct'][ self::TEST_TRUSTED_PROXY ] = array(
			'label' => __( 'Universal Geo Context: trusted proxy configuration', 'universal-geo-context' ),
			'test'  => array( $this, 'trusted_proxy_site_status_test' ),
		);

		$tests['direct'][ self::TEST_MAXMIND ] = array(
			'label' => __( 'Universal Geo Context: MaxMind database', 'universal-geo-context' ),
			'test'  => array( $this, 'maxmind_site_status_test' ),
		);

		$tests['direct'][ self::TEST_REMOTE ] = array(
			'label' => __( 'Universal Geo Context: remote provider', 'universal-geo-context' ),
			'test'  => array( $this, 'remote_site_status_test' ),
		);

		$tests['direct'][ self::TEST_MAXMIND_MANAGED ] = array(
			'label' => __( 'Universal Geo Context: managed database', 'universal-geo-context' ),
			'test'  => array( $this, 'maxmind_managed_site_status_test' ),
		);

		return $tests;
	}

	/**
	 * The trusted-proxy Site Health test itself (Revision 3 §12): critical
	 * when a forwarding header is present, the peer is private, and no
	 * trusted proxies are configured — the plugin would be returning the
	 * proxy's own location to every visitor. Gated on manage_options, per
	 * Revision 3 §12.
	 *
	 * @return array<string, mixed>
	 */
	public function trusted_proxy_site_status_test(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->site_status_result( 'good', __( 'Not applicable for this user.', 'universal-geo-context' ) );
		}

		$headers_present = array() !== $this->request->present_forwarding_headers();
		$peer            = $this->normalized_peer();
		$peer_is_private = null !== $peer && ! IpUtils::is_public( $peer );
		$misconfigured   = $headers_present && $peer_is_private && $this->trusted_proxies->is_empty();

		if ( $misconfigured ) {
			return $this->site_status_result(
				'critical',
				__(
					'Forwarding headers are present but no trusted proxies are configured. Universal Geo Context is reporting the reverse proxy\'s own location to every visitor instead of the real one. Configure Trusted Proxies under Universal Geo Context → Trusted Proxies.',
					'universal-geo-context'
				)
			);
		}

		return $this->site_status_result(
			'good',
			__( 'Universal Geo Context\'s trusted proxy configuration looks correct.', 'universal-geo-context' )
		);
	}

	/**
	 * Builds one Site Health result array in WordPress's expected shape.
	 *
	 * @param string $status      'good' or 'critical'.
	 * @param string $description Plain text, wrapped in a paragraph here.
	 *
	 * @return array<string, mixed>
	 */
	private function site_status_result( string $status, string $description ): array {
		return array(
			'label'       => __( 'Universal Geo Context: trusted proxy configuration', 'universal-geo-context' ),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'universal-geo-context' ),
				'color' => 'critical' === $status ? 'red' : 'blue',
			),
			'description' => sprintf( '<p>%s</p>', esc_html( $description ) ),
			'actions'     => '',
			'test'        => self::TEST_TRUSTED_PROXY,
		);
	}

	/**
	 * The MaxMind database Site Health test (M3 architecture report §6 3D):
	 * an unconfigured path is always 'good' (an optional feature that isn't
	 * set up is not ill health); a configured-but-missing/unreadable file
	 * (with the reader library actually present) or a build age over
	 * MAXMIND_CRITICAL_AGE_DAYS is 'critical'; a build age over
	 * MAXMIND_RECOMMENDED_AGE_DAYS is 'recommended'; otherwise 'good'.
	 * Gated on manage_options, per the trusted-proxy test's own precedent.
	 *
	 * @return array<string, mixed>
	 */
	public function maxmind_site_status_test(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->maxmind_site_status_result( 'good', __( 'Not applicable for this user.', 'universal-geo-context' ) );
		}

		$path = $this->maxmind_provider->db_path();

		if ( '' === $path ) {
			return $this->maxmind_site_status_result(
				'good',
				__( 'No MaxMind database is configured — this is an optional feature.', 'universal-geo-context' )
			);
		}

		if ( ! class_exists( 'MaxMind\\Db\\Reader' ) ) {
			return $this->maxmind_site_status_result(
				'good',
				__( 'A MaxMind database path is configured, but the MaxMind reader library is not available.', 'universal-geo-context' )
			);
		}

		if ( ! is_readable( $path ) ) {
			return $this->maxmind_site_status_result(
				'critical',
				__( 'A MaxMind database path is configured, but the file is missing or unreadable.', 'universal-geo-context' )
			);
		}

		$metadata = $this->maxmind_provider->metadata();

		if ( null === $metadata ) {
			return $this->maxmind_site_status_result(
				'critical',
				__( 'The configured MaxMind database could not be opened.', 'universal-geo-context' )
			);
		}

		$age_days = $metadata->build_age_days( time() );

		if ( $age_days > self::MAXMIND_CRITICAL_AGE_DAYS ) {
			return $this->maxmind_site_status_result(
				'critical',
				sprintf(
					/* translators: %d: database age in days. */
					__( 'The MaxMind database is %d days old (over 90) and should be updated.', 'universal-geo-context' ),
					$age_days
				)
			);
		}

		if ( $age_days > self::MAXMIND_RECOMMENDED_AGE_DAYS ) {
			return $this->maxmind_site_status_result(
				'recommended',
				sprintf(
					/* translators: %d: database age in days. */
					__( 'The MaxMind database is %d days old (over 30). Consider updating it.', 'universal-geo-context' ),
					$age_days
				)
			);
		}

		return $this->maxmind_site_status_result(
			'good',
			__( 'The MaxMind database is present and current.', 'universal-geo-context' )
		);
	}

	/**
	 * Builds one MaxMind Site Health result array — a separate builder from
	 * site_status_result() since that one is hardcoded to the trusted-proxy
	 * label/test id and only ever produces 'good'/'critical', never
	 * 'recommended'.
	 *
	 * @param string $status      'good', 'recommended', or 'critical'.
	 * @param string $description Plain text, wrapped in a paragraph here.
	 *
	 * @return array<string, mixed>
	 */
	private function maxmind_site_status_result( string $status, string $description ): array {
		if ( 'critical' === $status ) {
			$color = 'red';
		} elseif ( 'recommended' === $status ) {
			$color = 'orange';
		} else {
			$color = 'blue';
		}

		return array(
			'label'       => __( 'Universal Geo Context: MaxMind database', 'universal-geo-context' ),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'universal-geo-context' ),
				'color' => $color,
			),
			'description' => sprintf( '<p>%s</p>', esc_html( $description ) ),
			'actions'     => '',
			'test'        => self::TEST_MAXMIND,
		);
	}

	/**
	 * The remote-provider Site Health test (M4, the third and final v1
	 * test): reads only already-persisted/configuration state — `$this->settings`,
	 * `$this->remote_credential_source`, `$this->circuit_breaker->state()`
	 * (a pure read, never `may_attempt()`/`report_*()`), and
	 * `$this->provider_health_store->read()` — and performs no outbound
	 * request of its own. An unconfigured/disabled provider is always
	 * 'good' (an optional feature not turned on is not ill health, the
	 * MaxMind test's own precedent); this test never returns 'critical' —
	 * missing credentials, an open/half-open circuit, or a recent recorded
	 * failure are all 'recommended' at most, per the frozen M4 policy.
	 * Gated on manage_options, per both existing tests' own precedent.
	 *
	 * @return array<string, mixed>
	 */
	public function remote_site_status_test(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->remote_site_status_result( 'good', __( 'Not applicable for this user.', 'universal-geo-context' ) );
		}

		if ( ! ( $this->settings['remote_enabled'] ?? false ) ) {
			return $this->remote_site_status_result(
				'good',
				__( 'The remote provider is disabled — this is an optional feature.', 'universal-geo-context' )
			);
		}

		if ( 'none' === $this->remote_credential_source ) {
			return $this->remote_site_status_result(
				'recommended',
				__( 'The remote provider is enabled but no credentials are configured.', 'universal-geo-context' )
			);
		}

		$circuit_state = $this->circuit_breaker->state()['state'];

		if ( in_array( $circuit_state, array( 'open', 'half_open' ), true ) ) {
			return $this->remote_site_status_result(
				'recommended',
				sprintf(
					/* translators: %s: circuit breaker state, "open" or "half_open". */
					__( 'The remote provider\'s circuit breaker is currently %s after repeated failures.', 'universal-geo-context' ),
					$circuit_state
				)
			);
		}

		$recent_failure = $this->provider_health_store->read()['remote']['last_error_message'] ?? '';

		if ( '' !== $recent_failure ) {
			return $this->remote_site_status_result(
				'recommended',
				__( 'The remote provider recently failed. Review Diagnostics for details.', 'universal-geo-context' )
			);
		}

		return $this->remote_site_status_result(
			'good',
			__( 'The remote provider is enabled and healthy.', 'universal-geo-context' )
		);
	}

	/**
	 * Builds one remote-provider Site Health result array — mirrors
	 * maxmind_site_status_result()'s shape but is capped at 'recommended':
	 * this test never produces 'critical' (the frozen M4 policy).
	 *
	 * @param string $status      'good' or 'recommended'.
	 * @param string $description Plain text, wrapped in a paragraph here.
	 *
	 * @return array<string, mixed>
	 */
	private function remote_site_status_result( string $status, string $description ): array {
		return array(
			'label'       => __( 'Universal Geo Context: remote provider', 'universal-geo-context' ),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'universal-geo-context' ),
				'color' => 'recommended' === $status ? 'orange' : 'blue',
			),
			'description' => sprintf( '<p>%s</p>', esc_html( $description ) ),
			'actions'     => '',
			'test'        => self::TEST_REMOTE,
		);
	}

	/**
	 * The managed-database Site Health test (M6), with its own,
	 * GeoLite-specific 14/30-day thresholds — deliberately distinct from
	 * the custom-path MaxMind test's 30/90-day constants, since a managed
	 * database updates itself automatically and staying current is a much
	 * lower bar. Age is measured from the installed file's own MaxMind
	 * build epoch (when the data was published), not from when this site
	 * downloaded it — the same basis maxmind_site_status_test() already
	 * uses for the custom-path test, for a consistent "how stale is the
	 * data" meaning across both tests.
	 *
	 * Critical only when enabled with no valid managed database installed,
	 * or 30+ days stale, AND the overall resolved MaxMind source
	 * (`$this->maxmind_provider->is_available()`, the actual result of
	 * `Plugin::resolved_maxmind_db_path()`'s full precedence chain) is
	 * genuinely unavailable — a working higher-precedence custom path
	 * covering the gap keeps this test at 'recommended', never 'critical':
	 * "don't fail the whole site over an unused optional feature" applies
	 * even when the managed database specifically is the one that's stale,
	 * as long as something else is actually serving lookups. Gated on
	 * manage_options, per every other test's own precedent.
	 *
	 * @return array<string, mixed>
	 */
	public function maxmind_managed_site_status_test(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->maxmind_managed_site_status_result( 'good', __( 'Not applicable for this user.', 'universal-geo-context' ) );
		}

		if ( ! ( $this->settings['maxmind_managed_enabled'] ?? false ) ) {
			return $this->maxmind_managed_site_status_result(
				'good',
				__( 'Managed database downloads are disabled — this is an optional feature.', 'universal-geo-context' )
			);
		}

		$status            = $this->database_manager->status();
		$installed         = '' !== $this->database_manager->installed_path();
		$overall_available = $this->maxmind_provider->is_available();

		if ( ! $installed ) {
			if ( $overall_available ) {
				return $this->maxmind_managed_site_status_result(
					'recommended',
					__( 'Managed downloads are enabled but no managed database is installed yet. A higher-precedence MaxMind source is currently covering the gap.', 'universal-geo-context' )
				);
			}

			return $this->maxmind_managed_site_status_result(
				'critical',
				__( 'Managed downloads are enabled but no managed database is installed, and no other MaxMind source is currently available.', 'universal-geo-context' )
			);
		}

		$build_epoch = $status['installed_build_epoch'];
		$age_days    = null !== $build_epoch ? (int) floor( ( time() - $build_epoch ) / self::SECONDS_PER_DAY ) : null;

		if ( null === $age_days ) {
			return $this->maxmind_managed_site_status_result(
				'good',
				__( 'The managed database is installed.', 'universal-geo-context' )
			);
		}

		if ( $age_days >= self::MAXMIND_MANAGED_CRITICAL_AGE_DAYS ) {
			if ( $overall_available ) {
				return $this->maxmind_managed_site_status_result(
					'recommended',
					sprintf(
						/* translators: %d: database age in days. */
						__( 'The managed database is %d days old (30 or more) and should update automatically soon. A higher-precedence MaxMind source is currently covering the gap.', 'universal-geo-context' ),
						$age_days
					)
				);
			}

			return $this->maxmind_managed_site_status_result(
				'critical',
				sprintf(
					/* translators: %d: database age in days. */
					__( 'The managed database is %d days old (30 or more) and no other MaxMind source is currently available.', 'universal-geo-context' ),
					$age_days
				)
			);
		}

		if ( $age_days >= self::MAXMIND_MANAGED_RECOMMENDED_AGE_DAYS ) {
			return $this->maxmind_managed_site_status_result(
				'recommended',
				sprintf(
					/* translators: %d: database age in days. */
					__( 'The managed database is %d days old (14 or more). It should update automatically soon.', 'universal-geo-context' ),
					$age_days
				)
			);
		}

		return $this->maxmind_managed_site_status_result(
			'good',
			__( 'The managed database is present and current.', 'universal-geo-context' )
		);
	}

	/**
	 * Builds one managed-database Site Health result array — mirrors
	 * maxmind_site_status_result()'s shape exactly (three tiers, the same
	 * color mapping).
	 *
	 * @param string $status      'good', 'recommended', or 'critical'.
	 * @param string $description Plain text, wrapped in a paragraph here.
	 *
	 * @return array<string, mixed>
	 */
	private function maxmind_managed_site_status_result( string $status, string $description ): array {
		if ( 'critical' === $status ) {
			$color = 'red';
		} elseif ( 'recommended' === $status ) {
			$color = 'orange';
		} else {
			$color = 'blue';
		}

		return array(
			'label'       => __( 'Universal Geo Context: managed database', 'universal-geo-context' ),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'universal-geo-context' ),
				'color' => $color,
			),
			'description' => sprintf( '<p>%s</p>', esc_html( $description ) ),
			'actions'     => '',
			'test'        => self::TEST_MAXMIND_MANAGED,
		);
	}

	/**
	 * Builds the "result" section.
	 *
	 * @return array<string, mixed>
	 */
	private function result_section(): array {
		$context = Plugin::instance()->context();

		return array(
			'country_code' => $context->country_code,
			'region_code'  => $context->region_code,
			'source'       => $context->source,
			'confidence'   => $context->confidence,
			'is_cached'    => $context->is_cached,
		);
	}

	/**
	 * Builds the "client_address" section.
	 *
	 * @return array<string, mixed>
	 */
	private function client_address_section(): array {
		$peer     = $this->normalized_peer();
		$resolved = $this->ip_resolver->resolve();
		$live     = ServerRequest::capture( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return array(
			'peer_masked'           => null !== $peer ? IpUtils::mask( $peer ) : null,
			'peer_classification'   => null !== $peer ? IpUtils::describe( $peer ) : 'unknown',
			'client_masked'         => null !== $resolved ? $resolved->masked() : null,
			'source_header'         => null !== $resolved ? $resolved->header : null,
			'is_public'             => null !== $resolved ? $resolved->is_public : null,
			'chain_verified'        => null !== $resolved ? $resolved->chain_verified : null,
			'server_snapshot_drift' => $this->request->drift( $live ),
		);
	}

	/**
	 * Builds the "trusted_proxies" section.
	 *
	 * @return array<string, mixed>
	 */
	private function trusted_proxies_section(): array {
		$peer = $this->normalized_peer();

		return array(
			'configured_count' => $this->trusted_proxies->configured_count(),
			'trust_cloudflare' => $this->trusted_proxies->trusts_cloudflare(),
			'peer_trusted'     => null !== $peer && $this->trusted_proxies->contains( $peer ),
			'matched_entry'    => null !== $peer ? $this->trusted_proxies->matched_entry( $peer ) : null,
		);
	}

	/**
	 * Builds the "cloudflare" section.
	 *
	 * @return array<string, mixed>
	 */
	private function cloudflare_section(): array {
		$peer            = $this->normalized_peer();
		$ranges_age_days = (int) floor(
			( time() - (int) strtotime( TrustedProxies::CLOUDFLARE_RANGES_DATE ) ) / self::SECONDS_PER_DAY
		);

		return array(
			'preset_enabled'       => $this->trusted_proxies->trusts_cloudflare(),
			'peer_in_cf_ranges'    => null !== $peer && $this->trusted_proxies->is_cloudflare( $peer ),
			'cf_ipcountry_present' => null !== $this->request->header( 'CF-IPCountry' ),
			'ranges_date'          => TrustedProxies::CLOUDFLARE_RANGES_DATE,
			'ranges_age_days'      => $ranges_age_days,
		);
	}

	/**
	 * Builds the "woocommerce" section.
	 *
	 * @return array<string, mixed>
	 */
	private function woocommerce_section(): array {
		$maxmind_settings = get_option( 'woocommerce_maxmind_geolocation_settings', array() );
		$maxmind_settings = is_array( $maxmind_settings ) ? $maxmind_settings : array();

		return array(
			'active'                     => class_exists( 'WC_Geolocation' ),
			'maxmind_integration_active' => array() !== $maxmind_settings,
			'license_key_present'        => ! empty( $maxmind_settings['license_key'] ?? '' ),
			'mmdb_present'               => $this->mmdb_present(),
		);
	}

	/**
	 * Whether any `.mmdb` file exists under the uploads directory —
	 * WooCommerce's own MaxMind integration storage location (Revision 3
	 * §7's M2 acceptance note: "there is no .mmdb under wp-content/uploads/"
	 * on this dev site).
	 *
	 * @return bool
	 */
	private function mmdb_present(): bool {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$base       = is_array( $upload_dir ) ? ( $upload_dir['basedir'] ?? null ) : null;

		if ( ! is_string( $base ) || '' === $base ) {
			return false;
		}

		$matches = glob( rtrim( $base, '/' ) . '/*.mmdb' );

		return is_array( $matches ) && array() !== $matches;
	}

	/**
	 * Builds the "maxmind" section (M3): the effective resolved path (server
	 * config, not personal data — never masked), availability, and — when
	 * the reader opened successfully — its metadata. Reuses the injected
	 * MaxMindProvider instance exclusively; never opens a second reader
	 * (M3 F8).
	 *
	 * @return array<string, mixed>
	 */
	private function maxmind_section(): array {
		$metadata = $this->maxmind_provider->metadata();
		$is_city  = null !== $metadata && str_contains( $metadata->database_type, 'City' );

		return array(
			'effective_path'     => $this->maxmind_provider->db_path(),
			'path_source'        => $this->maxmind_path_source,
			'available'          => $this->maxmind_provider->is_available(),
			'reader_class_file'  => null !== $metadata ? $metadata->reader_class_file : null,
			'database_type'      => null !== $metadata ? $metadata->database_type : null,
			'build_age_days'     => null !== $metadata ? $metadata->build_age_days( time() ) : null,
			'city_database_note' => $is_city
				? __( 'City database detected; region support is deferred to a future release.', 'universal-geo-context' )
				: '',
		);
	}

	/**
	 * Builds the "remote" section (M4): booleans and enums only, plus the
	 * fixed endpoint host, timeout, circuit state, and a scrubbed recent
	 * failure message — never a credential value, never the account id or
	 * license key even as a boolean-adjacent hint beyond "present or not".
	 * `credential_source` is read from the already-resolved scalar
	 * `Plugin::build_graph()` injected; this method does not call
	 * `defined()` or re-derive the constants-vs-settings precedence itself
	 * (the frozen M4 rule). `circuit_state` reads `CircuitBreaker::state()`
	 * only — never `may_attempt()`/`report_*()` — so building this report
	 * never itself mutates circuit state.
	 *
	 * @return array<string, mixed>
	 */
	private function remote_section(): array {
		$recent_failure = $this->provider_health_store->read()['remote']['last_error_message'] ?? '';

		return array(
			'enabled'               => $this->settings['remote_enabled'] ?? false,
			'transfer_acknowledged' => $this->settings['remote_transfer_acknowledged'] ?? false,
			'credentials_present'   => 'none' !== $this->remote_credential_source,
			'credential_source'     => $this->remote_credential_source,
			'endpoint_host'         => ReferenceRemoteProvider::ENDPOINT_HOST,
			'timeout_seconds'       => $this->settings['remote_timeout'] ?? 2,
			'circuit_state'         => $this->circuit_breaker->state()['state'],
			'recent_failure'        => '' !== $recent_failure ? $recent_failure : null,
		);
	}

	/**
	 * Builds the "maxmind_managed" section (M6): booleans, enums, and
	 * timestamps only, from `DatabaseManager::status()`/`installed_path()`
	 * alone — never triggers a download, never opens a second `Reader`.
	 * Timestamps are Unix epoch integers, matching `provider_health`'s own
	 * `last_seen_at` convention elsewhere in this report — never a raw
	 * filesystem path beyond `installed`'s boolean, never the redirect
	 * target a download used.
	 *
	 * @return array<string, mixed>
	 */
	private function maxmind_managed_section(): array {
		$status = $this->database_manager->status();

		return array(
			'enabled'               => $this->settings['maxmind_managed_enabled'] ?? false,
			'installed'             => '' !== $this->database_manager->installed_path(),
			'auto_update_enabled'   => $this->settings['maxmind_managed_auto_update_enabled'] ?? false,
			'auto_update_frequency' => $this->settings['maxmind_managed_auto_update_frequency'] ?? 'weekly',
			'retain_previous'       => $this->settings['maxmind_managed_retain_previous'] ?? true,
			'last_attempt_at'       => $status['last_attempt_at'],
			'last_success_at'       => $status['last_success_at'],
			'last_result_code'      => '' !== $status['last_result_code'] ? $status['last_result_code'] : null,
			'installed_build_epoch' => $status['installed_build_epoch'],
		);
	}

	/**
	 * Builds the "cache" section.
	 *
	 * @return array<string, mixed>
	 */
	private function cache_section(): array {
		return array(
			'derived_cache_enabled' => $this->settings['derived_cache_enabled'] ?? true,
			'derived_cache_ttl'     => $this->settings['derived_cache_ttl'] ?? 900,
		);
	}

	/**
	 * Builds the "environment" section.
	 *
	 * @return array<string, mixed>
	 */
	private function environment_section(): array {
		return array(
			'object_cache_active' => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
			'php_version'         => PHP_VERSION,
			'wp_version'          => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '',
			'plugin_version'      => defined( 'UNIVERSAL_GEO_VERSION' ) ? UNIVERSAL_GEO_VERSION : '',
		);
	}

	/**
	 * The captured peer, normalized — or null when absent/malformed.
	 *
	 * @return string|null
	 */
	private function normalized_peer(): ?string {
		$raw = $this->request->remote_addr();

		return null !== $raw ? IpUtils::normalize( $raw ) : null;
	}
}
