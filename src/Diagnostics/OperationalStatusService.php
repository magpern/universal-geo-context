<?php
/**
 * Passive operational readiness evaluation for Universal Geo Context.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Diagnostics;

use UniversalGeo\Http\IpUtils;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Simulation\SimulationState;

/**
 * Composes existing local signals into an OperationalStatus. Never probes
 * providers, never contacts HTTP transport, never downloads, and never
 * writes options/cache/provider-health.
 *
 * @internal
 * @final
 */
final class OperationalStatusService {

	/**
	 * Managed-database age (days) treated as stale-but-valid for readiness.
	 */
	private const MANAGED_STALE_DAYS = 14;

	/**
	 * Request-scoped memo.
	 *
	 * @var OperationalStatus|null
	 */
	private ?OperationalStatus $memo = null;

	/**
	 * Stores injected read-only dependencies.
	 *
	 * @param ContextResolver     $resolver                 Provider chain / availability (never probe()).
	 * @param ServerRequest       $request                  Forwarding-header presence for trust checks.
	 * @param TrustedProxies      $trusted_proxies          Trusted-proxy emptiness / CF preset.
	 * @param array               $settings                 Sanitized settings array.
	 * @param ProviderHealthStore $provider_health_store    Failure-only health records.
	 * @param MaxMindProvider     $maxmind_provider         Local MaxMind availability.
	 * @param CircuitBreaker      $circuit_breaker          Remote circuit state (read-only).
	 * @param string              $remote_credential_source One of constants|legacy_constants|settings|none.
	 * @param DatabaseManager     $database_manager         Managed DB status / previous / lock diagnostics.
	 * @param UpdateScheduler     $update_scheduler         Cron next-run / registered state.
	 * @param SimulationState     $simulation_state         Simulation overlay.
	 * @param string              $maxmind_path_source      Resolved MaxMind path source label.
	 */
	public function __construct(
		private readonly ContextResolver $resolver,
		private readonly ServerRequest $request,
		private readonly TrustedProxies $trusted_proxies,
		private readonly array $settings,
		private readonly ProviderHealthStore $provider_health_store,
		private readonly MaxMindProvider $maxmind_provider,
		private readonly CircuitBreaker $circuit_breaker,
		private readonly string $remote_credential_source,
		private readonly DatabaseManager $database_manager,
		private readonly UpdateScheduler $update_scheduler,
		private readonly SimulationState $simulation_state,
		private readonly string $maxmind_path_source
	) {
	}

	/**
	 * Evaluates readiness once per request.
	 *
	 * @return OperationalStatus
	 */
	public function evaluate(): OperationalStatus {
		if ( null !== $this->memo ) {
			return $this->memo;
		}

		$this->memo = $this->compute();

		return $this->memo;
	}

	/**
	 * Clears the request memo — tests only.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->memo = null;
	}

	/**
	 * Builds a fresh status from passive signals.
	 *
	 * @return OperationalStatus
	 */
	private function compute(): OperationalStatus {
		$issues            = array();
		$simulation_active = $this->simulation_state->is_active();

		$chain             = $this->resolver->provider_chain();
		$available         = $this->available_provider_ids( $chain );
		$preferred         = array_values( array_filter( $available, static fn ( string $id ): bool => 'default' !== $id ) );
		$default_available = in_array( 'default', $available, true );
		$default_country   = is_string( $this->settings['default_country'] ?? null ) ? (string) $this->settings['default_country'] : '';
		$geo_expected      = $this->geo_detection_expected();

		if ( array() === $chain ) {
			$issues[] = new OperationalIssue(
				'empty_provider_chain',
				OperationalIssue::SEVERITY_CRITICAL,
				__( 'No providers are registered in the resolution chain.', 'universal-geo-context' ),
				__( 'Reinstall or repair Universal Geo Context so the default provider chain is restored.', 'universal-geo-context' )
			);

			return $this->finalize( OperationalStatus::STATE_UNAVAILABLE, false, $simulation_active, $issues );
		}

		if ( $this->trusted_proxy_misconfigured() ) {
			$issues[] = new OperationalIssue(
				'trusted_proxy_misconfigured',
				OperationalIssue::SEVERITY_CRITICAL,
				__( 'Forwarding headers are present but no trusted proxies are configured.', 'universal-geo-context' ),
				__( 'Configure Trusted Proxies under Universal Geo Context → Trusted Proxies so visitors are not attributed to the reverse proxy.', 'universal-geo-context' )
			);
		}

		$auto_update = ! empty( $this->settings['maxmind_managed_auto_update_enabled'] );
		$managed_on  = ! empty( $this->settings['maxmind_managed_enabled'] );
		$creds_ok    = 'none' !== $this->remote_credential_source;
		$scheduler   = $this->update_scheduler->status();

		if ( $auto_update && ! $creds_ok ) {
			$issues[] = new OperationalIssue(
				'managed_auto_update_missing_credentials',
				OperationalIssue::SEVERITY_CRITICAL,
				__( 'Managed MaxMind auto-update is enabled but no MaxMind credentials are configured.', 'universal-geo-context' ),
				__( 'Enter a MaxMind account ID and license key under Settings, or disable automatic updates.', 'universal-geo-context' )
			);
		}

		if ( $auto_update && empty( $scheduler['scheduler_registered'] ) ) {
			$severity = ( 'managed' === $this->maxmind_path_source ) ? OperationalIssue::SEVERITY_CRITICAL : OperationalIssue::SEVERITY_RECOMMENDED;
			$issues[] = new OperationalIssue(
				'managed_scheduler_missing',
				$severity,
				__( 'Managed MaxMind auto-update is enabled but no update is scheduled.', 'universal-geo-context' ),
				__( 'Save Settings to reconcile the update schedule, or disable automatic updates.', 'universal-geo-context' )
			);
		}

		if ( $managed_on && '' === $this->database_manager->installed_path() && 'managed' === $this->maxmind_path_source ) {
			$issues[] = new OperationalIssue(
				'managed_database_missing',
				OperationalIssue::SEVERITY_CRITICAL,
				__( 'Managed downloads are enabled but no managed database is installed, and no higher-precedence MaxMind source is covering the gap.', 'universal-geo-context' ),
				__( 'Use Download now under Settings → Managed database, or configure another MaxMind database path.', 'universal-geo-context' )
			);
		}

		$maxmind_path = $this->maxmind_provider->db_path();
		if ( '' !== $maxmind_path && ! $this->maxmind_provider->is_available() && $geo_expected ) {
			$issues[] = new OperationalIssue(
				'maxmind_unavailable',
				OperationalIssue::SEVERITY_RECOMMENDED,
				__( 'A MaxMind database path is configured but the database is not currently usable.', 'universal-geo-context' ),
				__( 'Check the MaxMind database path, install a managed database, or rely on another provider in the chain.', 'universal-geo-context' )
			);
		}

		if ( ! empty( $this->settings['remote_enabled'] ) ) {
			if ( 'none' === $this->remote_credential_source ) {
				$issues[] = new OperationalIssue(
					'remote_missing_credentials',
					OperationalIssue::SEVERITY_RECOMMENDED,
					__( 'The remote provider is enabled but credentials are not configured.', 'universal-geo-context' ),
					__( 'Add MaxMind credentials under Settings, or disable the remote provider.', 'universal-geo-context' )
				);
			}

			$circuit = $this->circuit_breaker->state();
			if ( in_array( $circuit['state'] ?? '', array( 'open', 'half_open' ), true ) ) {
				$issues[] = new OperationalIssue(
					'remote_circuit_open',
					OperationalIssue::SEVERITY_RECOMMENDED,
					__( 'The remote provider circuit breaker is open or half-open after recent failures.', 'universal-geo-context' ),
					__( 'Wait for the cooldown or fix remote connectivity; other providers continue to serve resolution.', 'universal-geo-context' )
				);
			}

			$remote_health = $this->provider_health_store->read()['remote'] ?? null;
			if ( is_array( $remote_health ) && '' !== (string) ( $remote_health['last_error_message'] ?? '' ) ) {
				$issues[] = new OperationalIssue(
					'remote_recent_failure',
					OperationalIssue::SEVERITY_RECOMMENDED,
					__( 'The remote provider has a recent recorded failure.', 'universal-geo-context' ),
					__( 'Review Diagnostics → Providers; resolution continues via earlier providers when available.', 'universal-geo-context' )
				);
			}
		}

		$managed_status = $this->database_manager->status();
		$build_epoch    = $managed_status['installed_build_epoch'] ?? null;
		if ( is_int( $build_epoch ) && $build_epoch > 0 ) {
			$age_days = (int) floor( ( time() - $build_epoch ) / 86400 );
			if ( $age_days >= self::MANAGED_STALE_DAYS && '' !== $this->database_manager->installed_path() ) {
				$issues[] = new OperationalIssue(
					'managed_database_stale',
					OperationalIssue::SEVERITY_RECOMMENDED,
					sprintf(
						/* translators: %d: age in days */
						__( 'The managed MaxMind database is %d days old and should be refreshed.', 'universal-geo-context' ),
						$age_days
					),
					__( 'Run Download now under Settings, or wait for the next scheduled update.', 'universal-geo-context' )
				);
			}
		}

		$lock = $this->database_manager->lock_diagnostics();
		if ( ! empty( $lock['locked'] ) ) {
			$issues[] = new OperationalIssue(
				'update_lock_held',
				OperationalIssue::SEVERITY_RECOMMENDED,
				__( 'A managed-database update lock is currently held.', 'universal-geo-context' ),
				__( 'Wait for the in-progress update to finish. Stale locks expire automatically after ten minutes.', 'universal-geo-context' )
			);
		}

		if ( array() === $preferred ) {
			if ( $geo_expected ) {
				$issues[] = new OperationalIssue(
					'geo_sources_unavailable',
					OperationalIssue::SEVERITY_CRITICAL,
					__( 'Geographic providers appear configured, but none except the default fallback is currently available.', 'universal-geo-context' ),
					__( 'Repair MaxMind, WooCommerce MaxMind, Cloudflare trust, or remote provider configuration under Settings / Trusted Proxies / Providers.', 'universal-geo-context' )
				);
			} else {
				$issues[] = new OperationalIssue(
					'default_only',
					OperationalIssue::SEVERITY_RECOMMENDED,
					$default_available && '' !== $default_country
						? __( 'Only the configured default country provider is available.', 'universal-geo-context' )
						: __( 'No preferred geographic provider is available; resolution may return an unknown country.', 'universal-geo-context' ),
					__( 'Optional: configure MaxMind, trust Cloudflare, enable WooCommerce MaxMind, or set a default country under Settings.', 'universal-geo-context' )
				);
			}
		} elseif ( count( $preferred ) < $this->configured_preferred_count() ) {
			$issues[] = new OperationalIssue(
				'preferred_provider_unavailable',
				OperationalIssue::SEVERITY_RECOMMENDED,
				__( 'One or more preferred geographic providers are currently unavailable; fallback providers remain in use.', 'universal-geo-context' ),
				__( 'Review Providers and Diagnostics for the unavailable source.', 'universal-geo-context' )
			);
		}

		if ( $simulation_active ) {
			$issues[] = new OperationalIssue(
				'simulation_active',
				OperationalIssue::SEVERITY_INFO,
				__( 'Country simulation is active for this administrator session.', 'universal-geo-context' ),
				__( 'Stop simulation from Detection & Testing when finished testing.', 'universal-geo-context' )
			);
		}

		return $this->finalize_from_issues( $simulation_active, $issues );
	}

	/**
	 * Maps accumulated issues to a final OperationalStatus.
	 *
	 * @param bool               $simulation_active Whether simulation is active.
	 * @param OperationalIssue[] $issues            Issues found.
	 *
	 * @return OperationalStatus
	 */
	private function finalize_from_issues( bool $simulation_active, array $issues ): OperationalStatus {
		$has_critical    = false;
		$has_recommended = false;

		foreach ( $issues as $issue ) {
			if ( OperationalIssue::SEVERITY_CRITICAL === $issue->severity ) {
				$has_critical = true;
			} elseif ( OperationalIssue::SEVERITY_RECOMMENDED === $issue->severity ) {
				$has_recommended = true;
			}
		}

		if ( $has_critical ) {
			$trust_breaking = false;
			foreach ( $issues as $issue ) {
				if ( 'trusted_proxy_misconfigured' === $issue->code || 'empty_provider_chain' === $issue->code ) {
					$trust_breaking = true;
					break;
				}
			}

			if ( $trust_breaking && $this->has_issue_code( $issues, 'empty_provider_chain' ) ) {
				return $this->finalize( OperationalStatus::STATE_UNAVAILABLE, false, $simulation_active, $issues );
			}

			$consumer_usable = ! $this->has_issue_code( $issues, 'trusted_proxy_misconfigured' );

			return $this->finalize(
				OperationalStatus::STATE_ACTION_REQUIRED,
				$consumer_usable,
				$simulation_active,
				$issues
			);
		}

		if ( $has_recommended ) {
			return $this->finalize( OperationalStatus::STATE_DEGRADED, true, $simulation_active, $issues );
		}

		return $this->finalize( OperationalStatus::STATE_READY, true, $simulation_active, $issues );
	}

	/**
	 * Builds the final OperationalStatus value object.
	 *
	 * @param string             $state             State constant.
	 * @param bool               $consumer_usable   Consumer usability.
	 * @param bool               $simulation_active Simulation flag.
	 * @param OperationalIssue[] $issues            Issues.
	 *
	 * @return OperationalStatus
	 */
	private function finalize( string $state, bool $consumer_usable, bool $simulation_active, array $issues ): OperationalStatus {
		$summary = match ( $state ) {
			OperationalStatus::STATE_READY => __( 'Universal Geo Context is ready for consumers.', 'universal-geo-context' ),
			OperationalStatus::STATE_DEGRADED => __( 'Universal Geo Context is usable but operating below preferred capability.', 'universal-geo-context' ),
			OperationalStatus::STATE_ACTION_REQUIRED => __( 'Universal Geo Context needs administrator attention before it is fully healthy.', 'universal-geo-context' ),
			default => __( 'Universal Geo Context cannot currently be relied upon.', 'universal-geo-context' ),
		};

		if ( $simulation_active ) {
			$summary .= ' ' . __( 'Simulation is active.', 'universal-geo-context' );
		}

		return new OperationalStatus( $state, $consumer_usable, $simulation_active, $issues, $summary );
	}

	/**
	 * Returns the available provider ids from a chain.
	 *
	 * @param string[] $chain Provider ids.
	 *
	 * @return string[]
	 */
	private function available_provider_ids( array $chain ): array {
		$available = array();

		foreach ( $chain as $provider_id ) {
			if ( $this->resolver->is_provider_available( $provider_id ) ) {
				$available[] = $provider_id;
			}
		}

		return $available;
	}

	/**
	 * Whether configuration evidence shows the administrator expected geo detection.
	 *
	 * @return bool
	 */
	private function geo_detection_expected(): bool {
		if ( '' !== (string) ( $this->settings['maxmind_db_path'] ?? '' ) ) {
			return true;
		}

		if ( ! empty( $this->settings['maxmind_managed_enabled'] ) ) {
			return true;
		}

		if ( ! empty( $this->settings['remote_enabled'] ) ) {
			return true;
		}

		if ( 'woocommerce' === $this->maxmind_path_source || 'constant' === $this->maxmind_path_source || 'filter' === $this->maxmind_path_source ) {
			return true;
		}

		if ( $this->trusted_proxies->trusts_cloudflare() ) {
			return true;
		}

		return false;
	}

	/**
	 * Count of preferred providers that appear intentionally configured.
	 *
	 * @return int
	 */
	private function configured_preferred_count(): int {
		$count = 0;

		if ( $this->trusted_proxies->trusts_cloudflare() ) {
			++$count;
		}

		if ( '' !== $this->maxmind_provider->db_path() || ! empty( $this->settings['maxmind_managed_enabled'] ) ) {
			++$count;
		}

		if ( 'woocommerce' === $this->maxmind_path_source ) {
			++$count;
		}

		if ( ! empty( $this->settings['remote_enabled'] ) ) {
			++$count;
		}

		return $count;
	}

	/**
	 * Mirrors the trusted-proxy Site Health critical condition.
	 *
	 * @return bool
	 */
	private function trusted_proxy_misconfigured(): bool {
		$headers_present = array() !== $this->request->present_forwarding_headers();
		$peer            = $this->request->remote_addr();
		$normalized      = null !== $peer ? IpUtils::normalize( $peer ) : null;
		$peer_is_private = null !== $normalized && ! IpUtils::is_public( $normalized );

		return $headers_present && $peer_is_private && $this->trusted_proxies->is_empty();
	}

	/**
	 * Whether any issue carries the given code.
	 *
	 * @param OperationalIssue[] $issues Issues.
	 * @param string             $code   Code to find.
	 *
	 * @return bool
	 */
	private function has_issue_code( array $issues, string $code ): bool {
		foreach ( $issues as $issue ) {
			if ( $issue->code === $code ) {
				return true;
			}
		}

		return false;
	}
}
