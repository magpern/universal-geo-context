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
	 * The remote-provider Site Health test id (M4) — the third and final v1 test.
	 */
	public const TEST_REMOTE = 'universal_geo_remote_provider';

	/**
	 * Build-age thresholds (days) for the MaxMind Site Health test (M3
	 * architecture report §6 3D).
	 */
	private const MAXMIND_CRITICAL_AGE_DAYS    = 90;
	private const MAXMIND_RECOMMENDED_AGE_DAYS = 30;

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
	 * @param string              $remote_credential_source One of 'constants', 'settings', 'none' — resolved exactly once by Plugin::build_graph(); this class must not call defined() or re-derive the precedence itself (M4 frozen decision).
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
		private readonly string $remote_credential_source
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
			'providers'          => $this->resolver->probe(),
			'provider_health'    => $this->provider_health_store->read(),
			'cache'              => $this->cache_section(),
			'environment'        => $this->environment_section(),
		);
	}

	/**
	 * Registers the trusted-proxy, MaxMind, and remote-provider Site Health tests.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_site_status_tests' ) );
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
					'Forwarding headers are present but no trusted proxies are configured. Universal Geo Context is reporting the reverse proxy\'s own location to every visitor instead of the real one. Configure Trusted Proxies under Settings → Geo Context.',
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
			'timeout_seconds'       => ReferenceRemoteProvider::TIMEOUT_SECONDS,
			'circuit_state'         => $this->circuit_breaker->state()['state'],
			'recent_failure'        => '' !== $recent_failure ? $recent_failure : null,
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
