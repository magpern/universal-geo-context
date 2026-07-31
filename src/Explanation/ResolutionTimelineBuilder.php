<?php
/**
 * Builds the resolution pipeline timeline for the Detection Inspector.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Explanation;

use UniversalGeo\Model\VisitorContext;

/**
 * Observational timeline — uses resolver outputs, never triggers probe().
 *
 * @internal
 * @final
 */
final class ResolutionTimelineBuilder {

	/**
	 * Builds the ordered timeline stages.
	 *
	 * @param array<string, mixed>             $client_address Masked client address section.
	 * @param array<string, mixed>             $trusted        Trusted proxy section.
	 * @param array<int, array<string, mixed>> $forwarding     Forwarding header rows.
	 * @param VisitorContext                   $real_context   Real resolved context.
	 * @param VisitorContext                   $effective      Effective context.
	 * @param bool                             $simulation     Simulation active flag.
	 * @param array<string, mixed>             $cache          Cache describe slice.
	 *
	 * @return ResolutionStage[]
	 */
	public function build(
		array $client_address,
		array $trusted,
		array $forwarding,
		VisitorContext $real_context,
		VisitorContext $effective,
		bool $simulation,
		array $cache
	): array {
		$stages   = array();
		$stages[] = $this->client_ip_stage( $client_address );
		$stages[] = $this->trusted_proxy_stage( $trusted );
		$stages[] = $this->forwarding_stage( $forwarding );
		$stages   = array_merge( $stages, $this->provider_stages( $real_context, $real_context->is_cached ) );
		$stages[] = $this->cache_stage( $cache, $real_context );
		$stages[] = $this->winner_stage( $real_context );
		$stages[] = $this->simulation_stage( $simulation, $real_context, $effective );
		$stages[] = $this->final_stage( $effective );

		return $stages;
	}

	/**
	 * Builds the client IP timeline stage.
	 *
	 * @param array<string, mixed> $client_address Client address section.
	 *
	 * @return ResolutionStage
	 */
	private function client_ip_stage( array $client_address ): ResolutionStage {
		$ip = isset( $client_address['client_masked'] ) ? (string) $client_address['client_masked'] : '';

		if ( '' === $ip || 'n/a' === strtolower( $ip ) ) {
			return new ResolutionStage(
				'client_ip',
				__( 'Client IP', 'universal-geo-context' ),
				ResolutionStage::STATUS_FAILED,
				__( 'No client IP could be resolved for this request.', 'universal-geo-context' )
			);
		}

		return new ResolutionStage(
			'client_ip',
			__( 'Client IP', 'universal-geo-context' ),
			ResolutionStage::STATUS_SUCCESS,
			$ip
		);
	}

	/**
	 * Builds the trusted proxy timeline stage.
	 *
	 * @param array<string, mixed> $trusted Trusted proxy section.
	 *
	 * @return ResolutionStage
	 */
	private function trusted_proxy_stage( array $trusted ): ResolutionStage {
		$matched = (bool) ( $trusted['peer_trusted'] ?? false );

		return new ResolutionStage(
			'trusted_proxies',
			__( 'Trusted proxy evaluation', 'universal-geo-context' ),
			$matched ? ResolutionStage::STATUS_SUCCESS : ResolutionStage::STATUS_SKIPPED,
			$matched
				? __( 'Connecting peer matched a trusted proxy entry.', 'universal-geo-context' )
				: __( 'Connecting peer is not a configured trusted proxy.', 'universal-geo-context' )
		);
	}

	/**
	 * Builds the forwarding header timeline stage.
	 *
	 * @param array<int, array<string, mixed>> $forwarding Forwarding header rows.
	 *
	 * @return ResolutionStage
	 */
	private function forwarding_stage( array $forwarding ): ResolutionStage {
		$selected = '';

		foreach ( $forwarding as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( ! empty( $row['selected'] ) ) {
				$selected = (string) ( $row['header'] ?? '' );
				break;
			}
		}

		if ( '' === $selected ) {
			return new ResolutionStage(
				'forwarding_header',
				__( 'Forwarding header', 'universal-geo-context' ),
				ResolutionStage::STATUS_SKIPPED,
				__( 'REMOTE_ADDR used directly — no forwarding header selected.', 'universal-geo-context' )
			);
		}

		return new ResolutionStage(
			'forwarding_header',
			__( 'Forwarding header', 'universal-geo-context' ),
			ResolutionStage::STATUS_SUCCESS,
			$selected
		);
	}

	/**
	 * Builds provider-chain timeline stages.
	 *
	 * @param VisitorContext $real_context Real context.
	 * @param bool           $cache_hit    Cache hit flag.
	 *
	 * @return ResolutionStage[]
	 */
	private function provider_stages( VisitorContext $real_context, bool $cache_hit ): array {
		if ( $cache_hit ) {
			return array(
				new ResolutionStage(
					'providers',
					__( 'Provider chain', 'universal-geo-context' ),
					ResolutionStage::STATUS_NOT_ATTEMPTED,
					__( 'Skipped — result served from geo cache.', 'universal-geo-context' )
				),
			);
		}

		if ( ! $real_context->is_known() && 'unknown' === $real_context->source ) {
			return array(
				new ResolutionStage(
					'providers',
					__( 'Provider chain', 'universal-geo-context' ),
					ResolutionStage::STATUS_FAILED,
					__( 'All providers missed or were unavailable.', 'universal-geo-context' )
				),
			);
		}

		return array(
			new ResolutionStage(
				'providers',
				__( 'Provider chain', 'universal-geo-context' ),
				ResolutionStage::STATUS_SUCCESS,
				sprintf(
					/* translators: %s: provider id */
					__( 'Resolved via %s.', 'universal-geo-context' ),
					$real_context->source
				)
			),
		);
	}

	/**
	 * Builds the cache lookup timeline stage.
	 *
	 * @param array<string, mixed> $cache        Cache describe slice.
	 * @param VisitorContext       $real_context Real context.
	 *
	 * @return ResolutionStage
	 */
	private function cache_stage( array $cache, VisitorContext $real_context ): ResolutionStage {
		if ( ! (bool) ( $cache['cache_operational'] ?? false ) ) {
			return new ResolutionStage(
				'cache_lookup',
				__( 'Cache lookup', 'universal-geo-context' ),
				ResolutionStage::STATUS_NOT_ATTEMPTED,
				__( 'Derived cache inactive for this site configuration.', 'universal-geo-context' )
			);
		}

		if ( $real_context->is_cached ) {
			return new ResolutionStage(
				'cache_lookup',
				__( 'Cache lookup', 'universal-geo-context' ),
				ResolutionStage::STATUS_CACHED,
				__( 'Cache hit — real resolution reused from geo cache.', 'universal-geo-context' )
			);
		}

		return new ResolutionStage(
			'cache_lookup',
			__( 'Cache lookup', 'universal-geo-context' ),
			ResolutionStage::STATUS_SUCCESS,
			__( 'Cache miss — providers ran for a fresh real resolution.', 'universal-geo-context' )
		);
	}

	/**
	 * Builds the winner timeline stage.
	 *
	 * @param VisitorContext $real_context Real context.
	 *
	 * @return ResolutionStage
	 */
	private function winner_stage( VisitorContext $real_context ): ResolutionStage {
		if ( ! $real_context->is_known() ) {
			return new ResolutionStage(
				'winner',
				__( 'Winner', 'universal-geo-context' ),
				ResolutionStage::STATUS_FAILED,
				__( 'No provider produced a valid country.', 'universal-geo-context' )
			);
		}

		return new ResolutionStage(
			'winner',
			__( 'Winner', 'universal-geo-context' ),
			ResolutionStage::STATUS_SUCCESS,
			sprintf(
				/* translators: 1: country code, 2: provider id, 3: confidence */
				__( '%1$s via %2$s (confidence %3$s).', 'universal-geo-context' ),
				(string) $real_context->country_code,
				$real_context->source,
				(string) $real_context->confidence
			)
		);
	}

	/**
	 * Builds the simulation timeline stage.
	 *
	 * @param bool           $simulation     Simulation active.
	 * @param VisitorContext $real_context   Real context.
	 * @param VisitorContext $effective      Effective context.
	 *
	 * @return ResolutionStage
	 */
	private function simulation_stage( bool $simulation, VisitorContext $real_context, VisitorContext $effective ): ResolutionStage {
		if ( ! $simulation ) {
			return new ResolutionStage(
				'simulation',
				__( 'Simulation', 'universal-geo-context' ),
				ResolutionStage::STATUS_NOT_ATTEMPTED,
				__( 'Simulation inactive.', 'universal-geo-context' )
			);
		}

		return new ResolutionStage(
			'simulation',
			__( 'Simulation', 'universal-geo-context' ),
			ResolutionStage::STATUS_SUCCESS,
			sprintf(
				/* translators: 1: simulated country, 2: real country */
				__( 'Active — effective country %1$s overrides real country %2$s.', 'universal-geo-context' ),
				(string) ( $effective->country_code ?? '?' ),
				(string) ( $real_context->country_code ?? '?' )
			)
		);
	}

	/**
	 * Builds the final context timeline stage.
	 *
	 * @param VisitorContext $effective Effective context.
	 *
	 * @return ResolutionStage
	 */
	private function final_stage( VisitorContext $effective ): ResolutionStage {
		return new ResolutionStage(
			'final',
			__( 'Final VisitorContext', 'universal-geo-context' ),
			ResolutionStage::STATUS_SUCCESS,
			sprintf(
				/* translators: 1: country, 2: source */
				__( 'Consumers receive %1$s (source: %2$s).', 'universal-geo-context' ),
				(string) ( $effective->country_code ?? 'unknown' ),
				$effective->source
			)
		);
	}
}
