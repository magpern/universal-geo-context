<?php
/**
 * Builds per-provider explanation rows for the Detection Inspector.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Explanation;

use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Resolver\ContextResolver;

/**
 * Converts probe output or resolver inference into ProviderExplanation rows.
 *
 * @internal
 * @final
 */
final class ProviderExplanationBuilder {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param ContextResolver $resolver Supplies chain order and confidence table.
	 */
	public function __construct(
		private readonly ContextResolver $resolver
	) {
	}

	/**
	 * Builds rows from a stored explicit probe (Refresh now).
	 *
	 * @param array<int, array<string, mixed>> $probe_rows ContextResolver::probe() output.
	 * @param string                           $winner_id  Real context source id.
	 *
	 * @return ProviderExplanation[]
	 */
	public function from_probe( array $probe_rows, string $winner_id ): array {
		$providers = array();
		$winner_id = 'simulation' === $winner_id ? $this->infer_winner_from_probe( $probe_rows ) : $winner_id;

		foreach ( $probe_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$provider_id = (string) ( $row['provider'] ?? '' );
			$available   = (bool) ( $row['available'] ?? false );
			$reason      = (string) ( $row['reason'] ?? '' );
			$country     = isset( $row['country_code'] ) ? (string) $row['country_code'] : null;
			$is_winner   = $provider_id === $winner_id && 'ok' === $reason;

			$providers[] = new ProviderExplanation(
				$provider_id,
				$available,
				$available,
				$country,
				'ok' === $reason ? null : $reason,
				$is_winner ? $this->resolver->confidence_for_provider( $provider_id ) : null,
				$is_winner,
				$this->skipped_reason_from_probe( $reason, $available ),
				null
			);
		}

		return $providers;
	}

	/**
	 * Builds rows inferred from real resolution without running probe().
	 *
	 * @param VisitorContext       $real_context Real resolved context.
	 * @param array<string, mixed> $health_rows  Provider health store keyed by id.
	 *
	 * @return ProviderExplanation[]
	 */
	public function inferred( VisitorContext $real_context, array $health_rows ): array {
		$chain     = $this->resolver->provider_chain();
		$providers = array();
		$winner_id = $real_context->source;
		$cache_hit = $real_context->is_cached;
		$winner_ix = array_search( $winner_id, $chain, true );

		if ( 'simulation' === $winner_id ) {
			$winner_id = 'unknown';
			$winner_ix = false;
		}

		if ( $cache_hit ) {
			foreach ( $chain as $provider_id ) {
				$providers[] = new ProviderExplanation(
					$provider_id,
					$this->resolver->is_provider_available( $provider_id ),
					false,
					$provider_id === $real_context->source ? $real_context->country_code : null,
					null,
					$provider_id === $real_context->source ? $real_context->confidence : null,
					$provider_id === $real_context->source,
					'served_from_cache',
					null
				);
			}

			return $providers;
		}

		foreach ( $chain as $index => $provider_id ) {
			$available = $this->resolver->is_provider_available( $provider_id );

			if ( ! $available ) {
				$providers[] = new ProviderExplanation(
					$provider_id,
					false,
					false,
					null,
					$this->health_reason( $health_rows, $provider_id ),
					null,
					false,
					'unavailable',
					null
				);
				continue;
			}

			if ( false !== $winner_ix && $index > $winner_ix ) {
				$providers[] = new ProviderExplanation(
					$provider_id,
					true,
					false,
					null,
					null,
					null,
					false,
					'short_circuit',
					null
				);
				continue;
			}

			$is_winner = $provider_id === $winner_id;

			$providers[] = new ProviderExplanation(
				$provider_id,
				true,
				true,
				$is_winner ? $real_context->country_code : null,
				$is_winner ? null : ( $this->health_reason( $health_rows, $provider_id ) ?? 'miss' ),
				$is_winner ? $real_context->confidence : null,
				$is_winner,
				$is_winner ? '' : 'miss',
				null
			);
		}

		return $providers;
	}

	/**
	 * Picks the first ok provider from probe rows when winner is simulation.
	 *
	 * @param array<int, array<string, mixed>> $probe_rows Probe output.
	 *
	 * @return string
	 */
	private function infer_winner_from_probe( array $probe_rows ): string {
		foreach ( $probe_rows as $row ) {
			if ( is_array( $row ) && ( $row['reason'] ?? '' ) === 'ok' ) {
				return (string) ( $row['provider'] ?? 'unknown' );
			}
		}

		return 'unknown';
	}

	/**
	 * Maps a probe reason to a skipped-reason label.
	 *
	 * @param string $reason    Probe reason code.
	 * @param bool   $available Provider availability flag.
	 *
	 * @return string
	 */
	private function skipped_reason_from_probe( string $reason, bool $available ): string {
		if ( ! $available ) {
			return 'unavailable';
		}

		return match ( $reason ) {
			'miss'             => 'miss',
			'failed'           => 'failed',
			'invalid_country'  => 'invalid_country',
			'ok'               => '',
			default            => $reason,
		};
	}

	/**
	 * Reads a failure reason from the health store.
	 *
	 * @param array<string, mixed> $health_rows Health store rows.
	 * @param string               $provider_id Provider id.
	 *
	 * @return string|null
	 */
	private function health_reason( array $health_rows, string $provider_id ): ?string {
		if ( ! isset( $health_rows[ $provider_id ] ) || ! is_array( $health_rows[ $provider_id ] ) ) {
			return null;
		}

		$row = $health_rows[ $provider_id ];

		return isset( $row['last_failure'] ) ? (string) $row['last_failure'] : null;
	}
}
