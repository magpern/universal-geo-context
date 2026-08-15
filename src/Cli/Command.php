<?php
/**
 * WP-CLI command family: context, diagnostics, cache flush.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Cli;

use InvalidArgumentException;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\OperationalStatusService;
use UniversalGeo\Http\IpUtils;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\Plugin;
use UniversalGeo\Resolver\ContextResolver;

/**
 * The three v1 WP-CLI commands (Revision 3 §12/§23, M5) — registered only
 * when `defined( 'WP_CLI' ) && WP_CLI`, by `Plugin::init()`, exactly like
 * `AdminScreen`'s admin-only registration (the composition-root pattern:
 * this class is constructed here and nowhere else).
 *
 * **Stated limitation** (documented in every command's own `--help`, per
 * Revision 3 §12): a WP-CLI process has no HTTP request, so `context --ip=`
 * can probe the provider chain directly but can never exercise
 * forwarding-header trust — verifying trusted-proxy configuration requires a
 * real browser request against the admin Diagnostics tab.
 *
 * Every method that can fail on bad input (`resolve_format()`,
 * `build_context_payload()`) is a small, pure-ish, independently testable
 * method that throws `InvalidArgumentException` rather than calling
 * `WP_CLI::error()` itself; the three WP-CLI-facing public methods
 * (`context()`, `diagnostics()`, `cache_flush()`) are the only places that
 * touch the WP-CLI framework (`WP_CLI::error()`/`success()`/`format_items()`)
 * — those thin wrappers are not unit-testable beyond their constituent
 * methods, since `WP_CLI::error()` exits the process outside of WP-CLI's own
 * `capture_exit` test mode; verified via manual/live CLI acceptance instead
 * (the same "not unit-testable beyond X, verified via Y" pattern
 * `AdminScreen::redirect_with_notice()` already established).
 *
 * `--ip` masking reuses `IpUtils::mask()` — the plugin's one masking
 * primitive, already used by the admin Diagnostics tab and `DiagnosticsService`
 * — never a second, divergent implementation.
 *
 * @internal
 * @final
 */
final class Command {

	/**
	 * WP-CLI output formats this class supports, across every command.
	 */
	private const ALLOWED_FORMATS = array( 'table', 'json', 'yaml' );

	/**
	 * Stores the injected dependencies — the same `ContextResolver` and
	 * `DiagnosticsService` instances `Plugin::init()` already builds for the
	 * current request, never a second, independent resolution.
	 *
	 * @param ContextResolver          $resolver            Supplies probe() for `context --ip=` / `providers`.
	 * @param DiagnosticsService       $diagnostics         Supplies report() for `diagnostics` / status composition.
	 * @param OperationalStatusService $operational_status  Passiveiness evaluation for `status` (passive).
	 * @param TrustedProxies           $trusted_proxies     Local membership tests for `trusted-proxies`.
	 */
	public function __construct(
		private readonly ContextResolver $resolver,
		private readonly DiagnosticsService $diagnostics,
		private readonly OperationalStatusService $operational_status,
		private readonly TrustedProxies $trusted_proxies
	) {
	}

	/**
	 * Registers the commands. A no-op when WP-CLI has not actually
	 * run (`WP_CLI::add_command()`'s own guard) — safe to call unconditionally.
	 *
	 * @return void
	 */
	public function register(): void {
		\WP_CLI::add_command( 'universal-geo context', array( $this, 'context' ) );
		\WP_CLI::add_command( 'universal-geo diagnostics', array( $this, 'diagnostics' ) );
		\WP_CLI::add_command( 'universal-geo cache flush', array( $this, 'cache_flush' ) );
		\WP_CLI::add_command( 'universal-geo status', array( $this, 'status' ) );
		\WP_CLI::add_command( 'universal-geo providers', array( $this, 'providers' ) );
		\WP_CLI::add_command( 'universal-geo trusted-proxies', array( $this, 'trusted_proxies' ) );
	}

	/**
	 * Resolves the visitor context Universal Geo Context would return for
	 * the current request, or probes the provider chain for a given IP.
	 *
	 * WP-CLI has no HTTP request: without --ip, the client address the
	 * provider chain sees is whatever (if anything) the CLI process's own
	 * environment provides — typically none, so only the configured default
	 * country (if any) can answer. With --ip, the provider chain is probed
	 * directly for that address; this never exercises forwarding-header
	 * trust, since there is no request to trust a header on.
	 *
	 * ## OPTIONS
	 *
	 * [--ip=<ip>]
	 * : Probe the provider chain for this IP instead of resolving the current (CLI) request's context.
	 *
	 * [--format=<format>]
	 * : Render output in this format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--allow-full-ip]
	 * : Show the complete --ip value instead of its masked form. Has no effect without --ip.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-geo context
	 *     wp universal-geo context --ip=203.0.113.4 --format=json
	 *     wp universal-geo context --ip=203.0.113.4 --allow-full-ip
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function context( array $args, array $assoc_args ): void {
		try {
			$format = $this->resolve_format( $assoc_args );
			$data   = $this->build_context_payload( $assoc_args );
		} catch ( InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		$this->render( array( $data ), $format );
	}

	/**
	 * Prints the same structured diagnostics report the admin Diagnostics
	 * tab renders — reused verbatim after a live provider probe, never
	 * re-derived, so the two can never drift and every address stays masked
	 * exactly as report() already guarantees. This command explicitly probes
	 * the provider chain for a fresh health snapshot.
	 *
	 * **Note**: This command may contact remote providers if they are enabled.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in this format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--allow-full-ip]
	 * : Accepted for symmetry with `context`, but has no effect: the diagnostics report never retains an unmasked address to reveal in the first place.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-geo diagnostics --format=json
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function diagnostics( array $args, array $assoc_args ): void {
		try {
			$format = $this->resolve_format( $assoc_args );
		} catch ( InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		// Explicitly probe the provider chain to produce a fresh health snapshot.
		$this->resolver->probe();
		$this->render( $this->flatten_report( $this->diagnostics->report() ), $format );
	}

	/**
	 * Flushes the derived-context cache by bumping its invalidation epoch —
	 * the exact mechanism `AdminScreen` already triggers after every
	 * settings save (`GeoCache::bump_epoch()`), reused rather than
	 * duplicated. Idempotent: every call safely invalidates further,
	 * regardless of how many times it runs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-geo cache flush
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments (unused).
	 *
	 * @return void
	 */
	public function cache_flush( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- both parameters are part of every WP-CLI command callable's required signature, unused here since this command takes no arguments.
		GeoCache::bump_epoch();

		\WP_CLI::success( __( 'The derived-context cache has been flushed.', 'universal-geo-context' ) );
	}

	/**
	 * Prints a compact operational status summary. Passive — never probes providers.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in this format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-geo status
	 *     wp universal-geo status --format=json
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		try {
			$format = $this->resolve_format( $assoc_args );
		} catch ( InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		$this->render( $this->build_status_rows(), $format );
	}

	/**
	 * Live provider probe for every provider in the chain. May contact remote
	 * providers when they are enabled.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in this format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-geo providers
	 *     wp universal-geo providers --format=json
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function providers( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		try {
			$format = $this->resolve_format( $assoc_args );
		} catch ( InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		$rows = $this->resolver->probe();
		$this->render( array_values( $rows ), $format );
	}

	/**
	 * Trusted-proxy configuration summary and optional local membership test.
	 * Never echoes the tested IP back.
	 *
	 * ## OPTIONS
	 *
	 * [--test=<ip>]
	 * : Test whether this IP matches the configured trusted proxy set (local CIDR membership only).
	 *
	 * [--format=<format>]
	 * : Render output in this format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-geo trusted-proxies
	 *     wp universal-geo trusted-proxies --test=172.18.0.5
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function trusted_proxies( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		try {
			$format = $this->resolve_format( $assoc_args );
			$rows   = $this->build_trusted_proxies_rows( $assoc_args );
		} catch ( InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		$this->render( $rows, $format );
	}

	/**
	 * Builds flat status rows from OperationalStatus + passive diagnostics slices.
	 *
	 * @return array<int, array{field: string, value: string}>
	 */
	public function build_status_rows(): array {
		$readiness = $this->operational_status->evaluate();
		$report    = $this->diagnostics->overview_sections();
		$context   = Plugin::instance()->context();
		$managed   = $this->diagnostics->inspector_sections()['maxmind_managed'] ?? array();

		$rows = array(
			array(
				'field' => 'plugin_version',
				'value' => defined( 'UNIVERSAL_GEO_VERSION' ) ? (string) UNIVERSAL_GEO_VERSION : '',
			),
			array(
				'field' => 'state',
				'value' => $readiness->state,
			),
			array(
				'field' => 'consumer_usable',
				'value' => $readiness->consumer_usable ? 'yes' : 'no',
			),
			array(
				'field' => 'simulation_active',
				'value' => $readiness->simulation_active ? 'yes' : 'no',
			),
			array(
				'field' => 'summary',
				'value' => $readiness->summary,
			),
			array(
				'field' => 'country_code',
				'value' => (string) ( $context->country_code ?? '' ),
			),
			array(
				'field' => 'region_code',
				'value' => (string) ( $context->region_code ?? '' ),
			),
			array(
				'field' => 'source',
				'value' => $context->source,
			),
			array(
				'field' => 'provider_chain',
				'value' => implode( ',', $this->resolver->provider_chain() ),
			),
			array(
				'field' => 'cache_enabled',
				'value' => ! empty( $report['cache']['derived_cache_enabled'] ) ? 'yes' : 'no',
			),
			array(
				'field' => 'cache_operational',
				'value' => ! empty( $report['cache']['cache_operational'] ) ? 'yes' : 'no',
			),
			array(
				'field' => 'trusted_proxy_count',
				'value' => (string) ( $report['trusted_proxies']['configured_count'] ?? 0 ),
			),
			array(
				'field' => 'managed_installed',
				'value' => ! empty( $managed['installed'] ) ? 'yes' : 'no',
			),
			array(
				'field' => 'scheduler_registered',
				'value' => ! empty( $managed['scheduler_registered'] ) ? 'yes' : 'no',
			),
			array(
				'field' => 'next_scheduled_at',
				'value' => null !== ( $managed['next_scheduled_at'] ?? null ) ? (string) $managed['next_scheduled_at'] : '',
			),
		);

		foreach ( $readiness->issues as $issue ) {
			$rows[] = array(
				'field' => 'issue:' . $issue->code,
				'value' => $issue->severity . ' — ' . $issue->message . ' → ' . $issue->remediation,
			);
		}

		return $rows;
	}

	/**
	 * Builds trusted-proxies CLI rows.
	 *
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws InvalidArgumentException When --test is invalid.
	 */
	public function build_trusted_proxies_rows( array $assoc_args ): array {
		$rows = array(
			array(
				'field' => 'configured_count',
				'value' => (string) $this->trusted_proxies->configured_count(),
			),
			array(
				'field' => 'cloudflare_preset',
				'value' => $this->trusted_proxies->trusts_cloudflare() ? 'yes' : 'no',
			),
		);

		if ( ! isset( $assoc_args['test'] ) || '' === $assoc_args['test'] ) {
			return $rows;
		}

		$ip = IpUtils::normalize( (string) $assoc_args['test'] );

		if ( null === $ip ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid --test value "%s": not a syntactically valid IPv4 or IPv6 address.', (string) $assoc_args['test'] )
			);
		}

		$matched = $this->trusted_proxies->contains( $ip );
		$entry   = $matched ? $this->trusted_proxies->matched_entry( $ip ) : null;

		$rows[] = array(
			'field' => 'matched',
			'value' => $matched ? 'yes' : 'no',
		);
		$rows[] = array(
			'field' => 'matching_entry',
			'value' => null !== $entry ? (string) $entry : '',
		);

		return $rows;
	}

	/**
	 * Validates and normalizes --format, defaulting to 'table'.
	 *
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return string One of ALLOWED_FORMATS.
	 *
	 * @throws InvalidArgumentException When --format is set but not recognized.
	 */
	public function resolve_format( array $assoc_args ): string {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		if ( ! in_array( $format, self::ALLOWED_FORMATS, true ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Invalid --format value "%s". Must be one of: %s.',
					$format,
					implode( ', ', self::ALLOWED_FORMATS )
				)
			);
		}

		return $format;
	}

	/**
	 * Builds the single-row payload for the `context` command: the real,
	 * current-request answer (Plugin::instance()->context(), the same
	 * public boundary DiagnosticsService's own result_section() and every
	 * `universal_geo_*` consumer function use) when --ip is absent, or a
	 * direct provider-chain probe (ContextResolver::probe(), already
	 * public, no ContextResolver change needed) when --ip is given —
	 * "probe the provider chain" is exactly Revision 3 §12's own wording for
	 * this case, never a fabricated trust-boundary resolution. probe() does
	 * not compute confidence (that is resolve()'s job, tied to a real
	 * HTTP-derived IP and the cache); --ip mode therefore has no confidence
	 * field, deliberately, rather than a fabricated one.
	 *
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When --ip is set but not a syntactically valid address.
	 */
	public function build_context_payload( array $assoc_args ): array {
		if ( ! isset( $assoc_args['ip'] ) || '' === $assoc_args['ip'] ) {
			$context = Plugin::instance()->context();

			return array(
				'country_code' => $context->country_code,
				'region_code'  => $context->region_code,
				'source'       => $context->source,
				'confidence'   => $context->confidence,
				'is_cached'    => $context->is_cached,
			);
		}

		$ip = IpUtils::normalize( (string) $assoc_args['ip'] );

		if ( null === $ip ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid --ip value "%s": not a syntactically valid IPv4 or IPv6 address.', (string) $assoc_args['ip'] )
			);
		}

		$winner = $this->first_successful_probe_row( $this->resolver->probe( $ip ) );

		return array(
			'ip'           => ! empty( $assoc_args['allow-full-ip'] ) ? $ip : IpUtils::mask( $ip ),
			'country_code' => $winner['country_code'] ?? null,
			'region_code'  => $winner['region_code'] ?? null,
			'source'       => $winner['provider'] ?? 'unknown',
		);
	}

	/**
	 * The first probe() row whose provider actually resolved a valid
	 * country ('ok') — the same short-circuit-at-first-match semantics
	 * ContextResolver::resolve() itself applies to the provider order, so
	 * `context --ip=` and a real resolve() agree on which provider "wins"
	 * for a given IP.
	 *
	 * @param array<int, array<string, mixed>> $rows probe()'s return value.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first_successful_probe_row( array $rows ): ?array {
		foreach ( $rows as $row ) {
			if ( 'ok' === ( $row['reason'] ?? '' ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Flattens report()'s nested section/row structure into flat rows for
	 * WP_CLI\Utils\format_items() — one row per leaf field, labeled via
	 * DiagnosticsService::field_labels() (M5's single shared label source;
	 * the admin Diagnostics tab and Site Health debug_information both
	 * consult the same map, never a fourth copy).
	 *
	 * @param array<string, mixed> $report The report() return value.
	 *
	 * @return array<int, array{section: string, field: string, value: string}>
	 */
	public function flatten_report( array $report ): array {
		$labels = $this->diagnostics->field_labels();
		$rows   = array();

		foreach ( $report as $section => $value ) {
			foreach ( $this->flatten_section( $value ) as $suffix => $display_value ) {
				$last_segment = (string) preg_replace( '/^.*[.\[]|\]$/', '', $suffix );

				$rows[] = array(
					'section' => (string) $section,
					'field'   => $labels[ $last_segment ] ?? ( '' !== $suffix ? $suffix : (string) $section ),
					'value'   => $display_value,
				);
			}
		}

		return $rows;
	}

	/**
	 * Recursively reduces one report value to a flat `suffix => display string` map.
	 *
	 * @param mixed  $value  A report leaf value, row, or list of rows.
	 * @param string $prefix The dot/bracket path built up so far.
	 *
	 * @return array<string, string>
	 */
	private function flatten_section( $value, string $prefix = '' ): array {
		if ( ! is_array( $value ) ) {
			return array( $prefix => $this->stringify( $value ) );
		}

		$is_list = array_is_list( $value );
		$rows    = array();

		foreach ( $value as $key => $item ) {
			$label = $is_list ? sprintf( '%s[%d]', $prefix, $key ) : ( '' !== $prefix ? $prefix . '.' . $key : (string) $key );
			$rows  = $rows + $this->flatten_section( $item, $label );
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
	private function stringify( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( null === $value ) {
			return '';
		}

		return (string) $value;
	}

	/**
	 * The one place this class touches WP_CLI\Utils\format_items() —
	 * everything above builds plain arrays, this renders them.
	 *
	 * @param array<int, array<string, mixed>> $items  Rows, all sharing the same keys.
	 * @param string                           $format One of ALLOWED_FORMATS.
	 *
	 * @return void
	 */
	private function render( array $items, string $format ): void {
		if ( array() === $items ) {
			\WP_CLI::log( __( 'No data.', 'universal-geo-context' ) );
			return;
		}

		// The real `wp` binary always loads this functions file as part of
		// its own bootstrap, before any plugin's plugins_loaded fires — this
		// require is a defensive no-op there. It matters only for callers
		// (this plugin's own PHPUnit integration suite) that construct and
		// call this class directly, bypassing that bootstrap.
		if ( ! function_exists( '\WP_CLI\Utils\format_items' ) ) {
			require_once dirname( __DIR__, 2 ) . '/vendor/wp-cli/wp-cli/php/utils.php';
		}

		\WP_CLI\Utils\format_items( $format, $items, array_keys( $items[0] ) );
	}
}
