<?php
/**
 * The full, trust-boundary-aware client-IP resolver (Revision 3 §6).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Http;

use UniversalGeo\Contracts\ClientIpResolverInterface;
use UniversalGeo\Model\ResolvedClientIp;

/**
 * The security core: decides who is asking, failing closed at every branch.
 * Replaces RemoteAddrOnlyResolver outright (M2) — that class is deleted in
 * this same step, not extended.
 *
 * With no trusted proxy configured (no admin CIDRs, Cloudflare preset off,
 * and no universal_geo_trusted_proxies filter addition), every forwarding
 * header is ignored unconditionally and REMOTE_ADDR is the answer — the
 * shipped default. Only once the immediate peer is inside the effective
 * trusted set are CF-Connecting-IP, X-Forwarded-For, and X-Real-IP even
 * read, in that fixed precedence, never configurable in v1.
 *
 * CF-Connecting-IP additionally requires the admin's trust_cloudflare
 * preset. Revision 3 §6 step 4a describes this as two named modes — DIRECT
 * (Cloudflare is the immediate peer, matched via TrustedProxies::CLOUDFLARE_RANGES,
 * which TrustedProxies::contains() already folds into the effective set
 * once the preset is on) and CHAINED (a different configured trusted proxy,
 * e.g. an nginx-based reverse proxy, sits between Cloudflare and PHP) — but
 * both modes are only ever reached from inside this class's own "peer is
 * trusted" branch, so both reduce to the same runtime condition:
 * trust_cloudflare enabled AND
 * the peer already established as trusted. cloudflare_header_trusted()
 * captures exactly that combined condition, computed once and memoized, so
 * CloudflareHeaderProvider can consult it without re-deriving trust logic
 * (Revision 3 §7: "decided once by ClientIpResolver and injected, not
 * re-derived").
 *
 * X-Forwarded-For is walked strictly right-to-left: the rightmost entry was
 * appended by the nearest proxy and is the only one this process observed
 * directly; everything left of the first untrusted entry may be forged by
 * the client. The whole header is rejected (falls through to the next
 * header) if it has more than 20 entries or any entry fails to parse as an
 * IP — never partially trusted.
 *
 * The public-address gate (Revision 3 §6 step 5) is intentionally NOT
 * implemented here: ResolvedClientIp::$is_public simply reports the fact,
 * and per the M1/M2 architecture reconciliation, each IP-based provider
 * self-guards against a non-public address inside its own resolve() (the
 * mechanism ADR-0003's frozen GeoProviderInterface has no method to
 * pre-filter by from the outside).
 *
 * @internal
 * @final
 */
final class ClientIpResolver implements ClientIpResolverInterface {

	/**
	 * X-Forwarded-For entries beyond this count reject the whole header
	 * (Revision 3 §6 step 4b).
	 */
	private const MAX_XFF_ENTRIES = 20;

	/**
	 * Fixed header read precedence (Revision 3 §6 step 4) — not configurable.
	 */
	private const HEADER_CLOUDFLARE    = 'CF-Connecting-IP';
	private const HEADER_FORWARDED_FOR = 'X-Forwarded-For';
	private const HEADER_REAL_IP       = 'X-Real-IP';

	/**
	 * Memoized peer normalization. $peer_computed distinguishes "not yet
	 * computed" from "computed as null" (no usable REMOTE_ADDR) — $peer
	 * alone cannot, since null is a legitimate computed result.
	 *
	 * @var string|null
	 */
	private ?string $peer = null;

	/**
	 * Whether peer() has computed $peer yet.
	 *
	 * @var bool
	 */
	private bool $peer_computed = false;

	/**
	 * Memoized universal_geo_trusted_proxies filter result. null means "not
	 * yet fetched"; an empty array is a legitimate fetched result and is not
	 * null, so no separate "computed" flag is needed here.
	 *
	 * @var string[]|null
	 */
	private ?array $filter_cidrs = null;

	/**
	 * Memoized cloudflare_header_trusted() verdict. null means "not yet
	 * computed"; false is a legitimate computed result and is not null.
	 *
	 * @var bool|null
	 */
	private ?bool $cloudflare_header_trusted = null;

	/**
	 * Stores the injected dependencies.
	 *
	 * @param ServerRequest  $request         The captured $_SERVER snapshot.
	 * @param TrustedProxies $trusted_proxies The configured + bundled trusted set.
	 */
	public function __construct(
		private readonly ServerRequest $request,
		private readonly TrustedProxies $trusted_proxies
	) {
	}

	/**
	 * Resolves the current request's client IP per Revision 3 §6.
	 *
	 * @return ResolvedClientIp|null
	 */
	public function resolve(): ?ResolvedClientIp {
		$peer = $this->peer();

		if ( null === $peer ) {
			return null;
		}

		if ( $this->effective_trusted_is_empty() ) {
			// Revision 3 §6 step 2: an empty effective trusted set is the
			// shipped default, not a suspicious state — there is no proxy
			// chain to fail verifying, so chain_verified is true and every
			// forwarding header is ignored unconditionally.
			return new ResolvedClientIp( $peer, 'REMOTE_ADDR', true, IpUtils::is_public( $peer ) );
		}

		if ( ! $this->is_trusted( $peer ) ) {
			// Step 3: a trusted set IS configured, but this peer isn't in
			// it — any header it sent is attacker-controlled.
			return new ResolvedClientIp( $peer, 'REMOTE_ADDR', false, IpUtils::is_public( $peer ) );
		}

		if ( $this->cloudflare_header_trusted() ) {
			$cf_raw = $this->request->header( self::HEADER_CLOUDFLARE );

			if ( null !== $cf_raw ) {
				$cf_ip = IpUtils::normalize( $cf_raw );

				if ( null !== $cf_ip ) {
					return new ResolvedClientIp( $cf_ip, self::HEADER_CLOUDFLARE, true, IpUtils::is_public( $cf_ip ) );
				}
			}
		}

		$xff_raw = $this->request->header( self::HEADER_FORWARDED_FOR );

		if ( null !== $xff_raw ) {
			$xff_client = $this->xff_client( $xff_raw );

			if ( null !== $xff_client ) {
				return new ResolvedClientIp( $xff_client, self::HEADER_FORWARDED_FOR, true, IpUtils::is_public( $xff_client ) );
			}
		}

		$real_ip_raw = $this->request->header( self::HEADER_REAL_IP );

		if ( null !== $real_ip_raw ) {
			$real_ip = IpUtils::normalize( $real_ip_raw );

			if ( null !== $real_ip ) {
				return new ResolvedClientIp( $real_ip, self::HEADER_REAL_IP, true, IpUtils::is_public( $real_ip ) );
			}
		}

		return new ResolvedClientIp( $peer, 'REMOTE_ADDR', true, IpUtils::is_public( $peer ) );
	}

	/**
	 * Whether CF-Connecting-IP would currently be honored: the peer is
	 * trusted AND the admin's trust_cloudflare preset is enabled — the
	 * combined direct/chained condition described in this class's own
	 * docblock. Computed once per request, memoized, and safe to call
	 * independently of resolve() (CloudflareHeaderProvider's is_available()
	 * calls this directly, never re-deriving the trust decision itself).
	 *
	 * @return bool
	 */
	public function cloudflare_header_trusted(): bool {
		if ( null !== $this->cloudflare_header_trusted ) {
			return $this->cloudflare_header_trusted;
		}

		$peer = $this->peer();

		$this->cloudflare_header_trusted = null !== $peer
			&& $this->is_trusted( $peer )
			&& $this->trusted_proxies->trusts_cloudflare();

		return $this->cloudflare_header_trusted;
	}

	/**
	 * Per-header trust explanation for diagnostics (Revision 3 §12): for
	 * each of the three trust-relevant headers, whether it is present,
	 * whether it would be honored, why, and its masked value (never the raw
	 * value — P5/P3). Forwarded / True-Client-IP / Client-IP are never
	 * trust-relevant and are not covered here; their bare presence is
	 * ServerRequest::present_forwarding_headers()' job.
	 *
	 * @return array<int, array{header: string, present: bool, trusted: bool, reason: string, masked_value: string|null}>
	 */
	public function explain(): array {
		$peer = $this->peer();
		$rows = array();

		foreach ( array( self::HEADER_CLOUDFLARE, self::HEADER_FORWARDED_FOR, self::HEADER_REAL_IP ) as $name ) {
			$raw = $this->request->header( $name );

			[ $trusted, $reason ] = $this->explain_header( $name, $peer );

			$rows[] = array(
				'header'       => $name,
				'present'      => null !== $raw,
				'trusted'      => $trusted,
				'reason'       => $reason,
				'masked_value' => $this->masked_header_value( $name, $raw ),
			);
		}

		return $rows;
	}

	/**
	 * Memoized REMOTE_ADDR normalization.
	 *
	 * @return string|null
	 */
	private function peer(): ?string {
		if ( ! $this->peer_computed ) {
			$raw                 = $this->request->remote_addr();
			$this->peer          = null !== $raw ? IpUtils::normalize( $raw ) : null;
			$this->peer_computed = true;
		}

		return $this->peer;
	}

	/**
	 * Whether $ip falls within the effective trusted set: TrustedProxies
	 * (configured CIDRs + the Cloudflare preset) unioned with whatever the
	 * universal_geo_trusted_proxies filter adds. Used both for the peer
	 * itself and for each X-Forwarded-For entry during the right-to-left
	 * walk — the same effective set, per Revision 3 §6.
	 *
	 * @param string $ip An already-normalized address.
	 *
	 * @return bool
	 */
	private function is_trusted( string $ip ): bool {
		if ( $this->trusted_proxies->contains( $ip ) ) {
			return true;
		}

		foreach ( $this->filter_cidrs() as $cidr ) {
			if ( IpUtils::cidr_match( $ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fetches and memoizes the universal_geo_trusted_proxies filter result.
	 * Additive only per Revision 3 §6's algorithm (the filter starts from an
	 * empty array and is unioned in) — it can extend trust, never shrink the
	 * admin's own configuration, since TrustedProxies is never handed the
	 * filtered result to replace its own CIDRs.
	 *
	 * @return string[]
	 */
	private function filter_cidrs(): array {
		if ( null === $this->filter_cidrs ) {
			/**
			 * Filters the trusted-proxy CIDR list, additively.
			 *
			 * @since 0.2.0
			 *
			 * @param string[] $cidrs Starts empty; return values are unioned with the admin's own configuration, never a replacement.
			 */
			$filtered = apply_filters( 'universal_geo_trusted_proxies', array() );

			$this->filter_cidrs = is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : array();
		}

		return $this->filter_cidrs;
	}

	/**
	 * Whether the effective trusted set (TrustedProxies own configuration
	 * plus the filter) is completely empty — the shipped fail-closed
	 * default, and explain()'s most specific "why" for every header.
	 *
	 * @return bool
	 */
	private function effective_trusted_is_empty(): bool {
		return $this->trusted_proxies->is_empty() && array() === $this->filter_cidrs();
	}

	/**
	 * Determines trusted/reason for one header, for explain().
	 *
	 * @param string      $name Canonical header name.
	 * @param string|null $peer The memoized, normalized peer (or null).
	 *
	 * @return array{0: bool, 1: string}
	 */
	private function explain_header( string $name, ?string $peer ): array {
		if ( null === $peer ) {
			return array( false, 'no_client_ip' );
		}

		if ( $this->effective_trusted_is_empty() ) {
			return array( false, 'no_trusted_proxies_configured' );
		}

		if ( ! $this->is_trusted( $peer ) ) {
			return array( false, 'peer_not_trusted' );
		}

		if ( self::HEADER_CLOUDFLARE === $name ) {
			return $this->trusted_proxies->trusts_cloudflare()
				? array( true, 'cloudflare_preset_enabled' )
				: array( false, 'cloudflare_preset_disabled' );
		}

		return array( true, 'peer_trusted' );
	}

	/**
	 * Masks a header's raw value for diagnostics — every entry of
	 * X-Forwarded-For individually (a comma-separated list, not a single
	 * address), or the single value for the other two headers. An entry
	 * that fails to parse as an IP is shown as the literal string 'invalid',
	 * never echoed back raw.
	 *
	 * @param string      $name Canonical header name.
	 * @param string|null $raw  The header's raw value, or null when absent.
	 *
	 * @return string|null
	 */
	private function masked_header_value( string $name, ?string $raw ): ?string {
		if ( null === $raw ) {
			return null;
		}

		if ( self::HEADER_FORWARDED_FOR === $name ) {
			$masked = array();

			foreach ( explode( ',', $raw ) as $entry ) {
				$normalized = IpUtils::normalize( trim( $entry ) );
				$masked[]   = null !== $normalized ? IpUtils::mask( $normalized ) : 'invalid';
			}

			return implode( ', ', $masked );
		}

		$normalized = IpUtils::normalize( $raw );

		return null !== $normalized ? IpUtils::mask( $normalized ) : 'invalid';
	}

	/**
	 * The right-to-left X-Forwarded-For walk (Revision 3 §6 step 4b). The
	 * whole header is rejected — returns null — if it has more than 20
	 * entries or any entry fails to parse as an IP; a partially-trusted
	 * header is never partially honored.
	 *
	 * @param string $raw The raw X-Forwarded-For header value.
	 *
	 * @return string|null The resolved client entry, or null if the header is rejected.
	 */
	private function xff_client( string $raw ): ?string {
		$entries = explode( ',', $raw );

		if ( count( $entries ) > self::MAX_XFF_ENTRIES ) {
			return null;
		}

		$normalized = array();

		foreach ( $entries as $entry ) {
			$value = IpUtils::normalize( trim( $entry ) );

			if ( null === $value ) {
				return null;
			}

			$normalized[] = $value;
		}

		$i = count( $normalized ) - 1;

		while ( $i >= 0 && $this->is_trusted( $normalized[ $i ] ) ) {
			--$i;
		}

		return $i >= 0 ? $normalized[ $i ] : $normalized[0];
	}
}
