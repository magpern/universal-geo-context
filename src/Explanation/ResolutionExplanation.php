<?php
/**
 * Full observational explanation for one Detection Inspector view.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Explanation;

use UniversalGeo\Model\VisitorContext;

/**
 * Immutable explanation model — UI renders this; it never mutates resolution.
 *
 * @internal
 * @final
 */
final class ResolutionExplanation {

	/**
	 * Stores the full inspector explanation.
	 *
	 * @param VisitorContext                   $effective_context Context after simulation filter.
	 * @param VisitorContext                   $real_context      Context from resolver before simulation.
	 * @param bool                             $simulation_active Whether simulation is authorized and active.
	 * @param string|null                      $simulated_country Active simulated country code.
	 * @param ResolutionStage[]                $timeline          Ordered pipeline stages.
	 * @param ProviderExplanation[]            $providers         Per-provider rows.
	 * @param array<string, mixed>             $cache             Cache observability slice.
	 * @param array<string, mixed>             $client_address    Masked client address section.
	 * @param array<string, mixed>             $trusted_proxies   Trusted proxy section.
	 * @param array<int, array<string, mixed>> $forwarding_headers Forwarding header rows.
	 * @param array<string, mixed>             $cloudflare        Cloudflare section.
	 * @param array<string, mixed>             $environment       Environment section.
	 * @param array<string, mixed>|null        $probe_summary  ok/total from last explicit refresh.
	 * @param bool                             $has_live_probe    Whether provider rows come from stored probe.
	 */
	public function __construct(
		public readonly VisitorContext $effective_context,
		public readonly VisitorContext $real_context,
		public readonly bool $simulation_active,
		public readonly ?string $simulated_country,
		public readonly array $timeline,
		public readonly array $providers,
		public readonly array $cache,
		public readonly array $client_address,
		public readonly array $trusted_proxies,
		public readonly array $forwarding_headers,
		public readonly array $cloudflare,
		public readonly array $environment,
		public readonly ?array $probe_summary,
		public readonly bool $has_live_probe
	) {
	}
}
