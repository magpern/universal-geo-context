<?php
/**
 * Builds observational Detection Inspector explanations.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Explanation;

use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Plugin;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Simulation\SimulationState;

/**
 * Orchestrates explanation models — never writes cache or overrides resolution.
 * Live provider probes run only in the explicit Refresh now POST handler.
 * After redirect, callers pass $explicit_refresh_summary so the UI can label
 * provider rows as post-refresh without probing again on GET.
 *
 * @internal
 * @final
 */
final class DetectionInspectorService {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param ContextResolver            $resolver         Real resolution source.
	 * @param ClientIpResolver           $ip_resolver      Masked IP for cache describe.
	 * @param GeoCache                   $cache            Read-only cache describe.
	 * @param DiagnosticsService         $diagnostics      Inspector section supplier.
	 * @param SimulationState            $simulation_state Simulation observability.
	 * @param ProviderExplanationBuilder $provider_builder Provider row builder.
	 * @param ResolutionTimelineBuilder  $timeline_builder Timeline builder.
	 */
	public function __construct(
		private readonly ContextResolver $resolver,
		private readonly ClientIpResolver $ip_resolver,
		private readonly GeoCache $cache,
		private readonly DiagnosticsService $diagnostics,
		private readonly SimulationState $simulation_state,
		private readonly ProviderExplanationBuilder $provider_builder,
		private readonly ResolutionTimelineBuilder $timeline_builder
	) {
	}

	/**
	 * Builds the full explanation for the current request.
	 *
	 * @param array{ok_count: int, total: int}|null $explicit_refresh_summary Post-refresh PRG summary, if any.
	 *
	 * @return ResolutionExplanation
	 */
	public function explain( ?array $explicit_refresh_summary = null ): ResolutionExplanation {
		$real_context      = $this->resolver->resolve();
		$effective_context = Plugin::instance()->context();
		$sections          = $this->diagnostics->inspector_sections();
		$simulation_active = $this->simulation_state->is_active();
		$simulated_country = $this->simulation_state->active_country();

		$resolved_ip                            = $this->ip_resolver->resolve();
		$cache_slice                            = array_merge(
			$sections['cache'],
			null !== $resolved_ip
				? $this->cache->describe( $resolved_ip->ip )
				: array(
					'cache_operational'   => false,
					'current_request_hit' => false,
					'cache_epoch'         => null,
					'ttl_remaining'       => null,
					'entry_age'           => null,
				)
		);
		$cache_slice['this_request_from_cache'] = $real_context->is_cached;

		$has_live_probe = null !== $explicit_refresh_summary;
		$probe_summary  = $explicit_refresh_summary;
		$providers      = $this->provider_builder->inferred(
			$real_context,
			$sections['provider_health']
		);

		$timeline = $this->timeline_builder->build(
			$sections['client_address'],
			$sections['trusted_proxies'],
			$sections['forwarding_headers'],
			$real_context,
			$effective_context,
			$simulation_active,
			$cache_slice
		);

		return new ResolutionExplanation(
			$effective_context,
			$real_context,
			$simulation_active,
			$simulated_country,
			$timeline,
			$providers,
			$cache_slice,
			$sections['client_address'],
			$sections['trusted_proxies'],
			$sections['forwarding_headers'],
			$sections['cloudflare'],
			$sections['environment'],
			$probe_summary,
			$has_live_probe
		);
	}

	/**
	 * Builds per-provider detail blocks for the Providers admin page.
	 *
	 * @param array{ok_count: int, total: int}|null $explicit_refresh_summary Post-refresh PRG summary, if any.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function provider_details( ?array $explicit_refresh_summary = null ): array {
		$sections = $this->diagnostics->inspector_sections();
		$health   = $sections['provider_health'];
		$details  = array();

		foreach ( $this->resolver->provider_chain() as $provider_id ) {
			$health_row = $health[ $provider_id ] ?? array();

			$details[ $provider_id ] = array_merge(
				$this->section_for_provider( $provider_id, $sections ),
				array(
					'provider_id'   => $provider_id,
					'available'     => $this->resolver->is_provider_available( $provider_id ) ? 'yes' : 'no',
					'confidence'    => $this->resolver->confidence_for_provider( $provider_id ),
					'probe_reason'  => null !== $explicit_refresh_summary
						? __( 'Explicit refresh completed — rows reflect current configuration and health.', 'universal-geo-context' )
						: __( 'Run Refresh now for a live probe.', 'universal-geo-context' ),
					'probe_country' => null,
					'last_failure'  => $health_row['last_failure'] ?? null,
					'failure_count' => $health_row['failure_count'] ?? null,
				)
			);
		}

		return $details;
	}

	/**
	 * Maps one provider id to its diagnostics section.
	 *
	 * @param string               $provider_id Provider id.
	 * @param array<string, mixed> $sections    Inspector sections.
	 *
	 * @return array<string, mixed>
	 */
	private function section_for_provider( string $provider_id, array $sections ): array {
		return match ( $provider_id ) {
			'cloudflare'  => $sections['cloudflare'],
			'maxmind'       => array_merge( $sections['maxmind'], $sections['maxmind_managed'] ),
			'woocommerce' => $sections['woocommerce'],
			'remote'      => $sections['remote'],
			'default'     => array(
				'default_country' => $sections['default_country'] ?? null,
			),
			default       => array(),
		};
	}

	/**
	 * Converts a VisitorContext into a flat display array.
	 *
	 * @param VisitorContext $context Context to summarize.
	 *
	 * @return array<string, mixed>
	 */
	public function context_slice( VisitorContext $context ): array {
		return array(
			'country_code' => $context->country_code,
			'region_code'  => $context->region_code,
			'source'       => $context->source,
			'confidence'   => $context->confidence,
			'is_cached'    => $context->is_cached,
		);
	}
}
