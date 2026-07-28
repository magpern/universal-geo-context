<?php
/**
 * Owns ordering, confidence, validation, and caching for one resolved visitor context.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Resolver;

use InvalidArgumentException;
use Throwable;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Contracts\ClientIpResolverInterface;
use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Http\IpUtils;
use UniversalGeo\Model\VisitorContext;

/**
 * The composition point Revision 3 §2 places at the centre of the
 * architecture: resolves the client IP once, consults the derived-context
 * cache, iterates the injected provider chain in the order it was given,
 * validates/normalizes candidates via GeoValidator (called statically —
 * never injected, never duplicated here), and assigns source and
 * confidence centrally. Providers and GeoCandidate never carry either.
 *
 * Settings is not a dependency: every settings-derived value this class's
 * algorithm needs already arrives pre-decided inside its other
 * dependencies (a provider's own constructor, or GeoCache's). GeoValidator
 * is not a dependency either — it is "pure static, WordPress-free" (§4.4)
 * and is called by static reference.
 *
 * This class does not read $_SERVER, does not call any WordPress function,
 * does not perform HTTP requests, and is not wired into Plugin.php by this
 * class itself — that composition remains a later step.
 *
 * @internal
 * @final
 */
final class ContextResolver {

	/**
	 * The order Plugin.php builds the default provider array in. Never
	 * consulted here: this class iterates whatever order it was given,
	 * exactly once, with no second ordering authority (Revision 3 §4.4).
	 */
	private const PROVIDER_ORDER = array( 'cloudflare', 'maxmind', 'woocommerce', 'remote', 'default' );

	/**
	 * Confidence in the country determination, keyed by provider id.
	 */
	private const CONFIDENCE = array(
		'cloudflare'  => 0.95,
		'maxmind'     => 0.90,
		'woocommerce' => 0.85,
		'remote'      => 0.85,
		'default'     => 0.10,
		'unknown'     => 0.00,
	);

	/**
	 * Confidence for a filter-registered provider absent from CONFIDENCE.
	 */
	private const UNLISTED_PROVIDER_CONFIDENCE = 0.85;

	/**
	 * Providers in resolution order, exactly as injected.
	 *
	 * @var GeoProviderInterface[]
	 */
	private readonly array $providers;

	/**
	 * Request-level memo. Not static: scoped to this instance, matching
	 * "ten consumers in one request cost one resolution" (§9 Layer 1) — one
	 * instance is expected to live for one request.
	 *
	 * @var VisitorContext|null
	 */
	private ?VisitorContext $memo = null;

	/**
	 * Invoked whenever a provider's resolve() throws — from both resolve()
	 * and probe(). `callable` cannot be a typed property in PHP, so this is
	 * assigned in the constructor body rather than promoted like the other
	 * three dependencies.
	 *
	 * @var callable|null fn(string $provider_id, string $reason): void
	 */
	private $on_provider_failed;

	/**
	 * Stores the injected dependencies and validates every provider.
	 *
	 * @param ClientIpResolverInterface $client_ip_resolver  Resolves the current request's client IP.
	 * @param GeoProviderInterface[]    $providers            Providers in resolution order.
	 * @param GeoCache                  $cache                The derived-context cache.
	 * @param callable|null             $on_provider_failed   fn(string $provider_id, string $reason): void — called for every provider Throwable, from both resolve() and probe(). Optional: null (the default) fires nothing, so this class stays usable without WordPress. $reason is the exception's class and message only — a provider author must never put a client IP in an exception message; ContextResolver does not (and cannot generically) scrub one out.
	 *
	 * @throws InvalidArgumentException When any provider element does not implement GeoProviderInterface.
	 */
	public function __construct(
		private readonly ClientIpResolverInterface $client_ip_resolver,
		array $providers,
		private readonly GeoCache $cache,
		?callable $on_provider_failed = null
	) {
		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof GeoProviderInterface ) {
				throw new InvalidArgumentException( 'ContextResolver providers must all implement GeoProviderInterface.' );
			}
		}

		$this->providers          = array_values( $providers );
		$this->on_provider_failed = $on_provider_failed;
	}

	/**
	 * Resolves the current visitor's context.
	 *
	 * Memoized: the first call performs the full resolution (client IP,
	 * cache, provider loop); every later call in the same request returns
	 * the identical instance without consulting the client-IP resolver,
	 * the cache, or any provider again.
	 *
	 * No request context (client-IP resolver returns null) short-circuits
	 * to VisitorContext::unknown() without ever touching the cache or any
	 * provider.
	 *
	 * @return VisitorContext
	 */
	public function resolve(): VisitorContext {
		if ( null !== $this->memo ) {
			return $this->memo;
		}

		$resolved_ip = $this->client_ip_resolver->resolve();

		if ( null === $resolved_ip ) {
			$this->memo = VisitorContext::unknown();

			return $this->memo;
		}

		$cached = $this->cache->get( $resolved_ip->ip );

		if ( null !== $cached ) {
			$this->memo = $cached;

			return $this->memo;
		}

		$context = $this->resolve_via_providers( $resolved_ip->ip );

		$this->cache->set( $resolved_ip->ip, $context );

		$this->memo = $context;

		return $this->memo;
	}

	/**
	 * Runs every provider with no memoization and no cache, for diagnostics.
	 *
	 * Every provider is visited, even after one already produced a valid
	 * result — unlike resolve(), there is no short-circuit, so conflicting
	 * candidates are visible. Never touches the memo or the cache.
	 *
	 * When $ip is given, it is normalized and used as-is; when null, the
	 * client-IP resolver is consulted. If no usable IP results either way,
	 * no provider is visited and an empty array is returned.
	 *
	 * Result shape: Revision 3 does not specify one anywhere (its first
	 * real consumer, DiagnosticsService, is a later milestone) — this is
	 * the one architecture gap this class fills. Returns a list with one
	 * entry per provider, in injected order:
	 *
	 *   'provider'     => string       Provider's get_id().
	 *   'available'    => bool         is_available()'s result.
	 *   'country_code' => string|null  Raw candidate value if invalid; validated value if 'ok'; null otherwise.
	 *   'region_code'  => string|null  Validated value if 'ok'; null otherwise.
	 *   'reason'       => string       One of 'unavailable' | 'failed' | 'miss' | 'invalid_country' | 'ok'.
	 *
	 * No raw IP, no ResolvedClientIp, no provider object, no exception
	 * detail, and no cache key ever appear in the result.
	 *
	 * @param string|null $ip Optional IP to probe instead of the current request's.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function probe( ?string $ip = null ): array {
		if ( null !== $ip ) {
			$normalized_ip = IpUtils::normalize( $ip );
		} else {
			$resolved_ip   = $this->client_ip_resolver->resolve();
			$normalized_ip = $resolved_ip?->ip;
		}

		if ( null === $normalized_ip ) {
			return array();
		}

		$results = array();

		foreach ( $this->providers as $provider ) {
			$results[] = $this->probe_provider( $provider, $normalized_ip );
		}

		return $results;
	}

	/**
	 * Clears the memoized resolution only. Does not flush the derived
	 * cache, alter its salt or epoch, or reconstruct any dependency — the
	 * next resolve() call re-runs the full process.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->memo = null;
	}

	/**
	 * Iterates the injected providers in order, short-circuiting at the
	 * first candidate whose country validates.
	 *
	 * A provider can never fatal a page view (Revision 3 §7): an exception
	 * from resolve() is treated exactly like a miss.
	 *
	 * @param string $ip The already-resolved, already-normalized client IP.
	 *
	 * @return VisitorContext
	 */
	private function resolve_via_providers( string $ip ): VisitorContext {
		foreach ( $this->providers as $provider ) {
			if ( ! $provider->is_available() ) {
				continue;
			}

			try {
				$candidate = $provider->resolve( $ip );
			} catch ( Throwable $e ) {
				$this->notify_provider_failed( $provider, $e );
				continue;
			}

			if ( null === $candidate ) {
				continue;
			}

			$country = GeoValidator::country( $candidate->country_code );

			if ( null === $country ) {
				continue;
			}

			$provider_id = $provider->get_id();

			return new VisitorContext(
				$country,
				GeoValidator::region( $candidate->region_code, $country ),
				$provider_id,
				self::CONFIDENCE[ $provider_id ] ?? self::UNLISTED_PROVIDER_CONFIDENCE,
				false
			);
		}

		return VisitorContext::unknown();
	}

	/**
	 * Builds one probe() result entry for a single provider.
	 *
	 * @param GeoProviderInterface $provider The provider to probe.
	 * @param string               $ip       The IP to probe it with.
	 *
	 * @return array<string, mixed>
	 */
	private function probe_provider( GeoProviderInterface $provider, string $ip ): array {
		$provider_id = $provider->get_id();

		if ( ! $provider->is_available() ) {
			return array(
				'provider'     => $provider_id,
				'available'    => false,
				'country_code' => null,
				'region_code'  => null,
				'reason'       => 'unavailable',
			);
		}

		try {
			$candidate = $provider->resolve( $ip );
		} catch ( Throwable $e ) {
			$this->notify_provider_failed( $provider, $e );

			return array(
				'provider'     => $provider_id,
				'available'    => true,
				'country_code' => null,
				'region_code'  => null,
				'reason'       => 'failed',
			);
		}

		if ( null === $candidate ) {
			return array(
				'provider'     => $provider_id,
				'available'    => true,
				'country_code' => null,
				'region_code'  => null,
				'reason'       => 'miss',
			);
		}

		$country = GeoValidator::country( $candidate->country_code );

		if ( null === $country ) {
			return array(
				'provider'     => $provider_id,
				'available'    => true,
				'country_code' => $candidate->country_code,
				'region_code'  => null,
				'reason'       => 'invalid_country',
			);
		}

		return array(
			'provider'     => $provider_id,
			'available'    => true,
			'country_code' => $country,
			'region_code'  => GeoValidator::region( $candidate->region_code, $country ),
			'reason'       => 'ok',
		);
	}

	/**
	 * Invokes the injected on_provider_failed callable, when one was given.
	 * A no-op otherwise, so this class stays fully usable — and, per its own
	 * class docblock, WordPress-free — without one.
	 *
	 * @param GeoProviderInterface $provider The provider whose resolve() threw.
	 * @param Throwable            $e        The exception it threw.
	 *
	 * @return void
	 */
	private function notify_provider_failed( GeoProviderInterface $provider, Throwable $e ): void {
		if ( null === $this->on_provider_failed ) {
			return;
		}

		( $this->on_provider_failed )( $provider->get_id(), get_class( $e ) . ': ' . $e->getMessage() );
	}
}
