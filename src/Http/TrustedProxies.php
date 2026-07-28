<?php
/**
 * The effective trusted-proxy set: configured CIDRs plus bundled Cloudflare ranges.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Http;

/**
 * Revision 3 §4.3/§6: the security boundary deciding which peers this
 * plugin will ever read a forwarding header from. Fail-closed by
 * construction — with no configured CIDRs and the Cloudflare preset off,
 * `contains()` never matches anything.
 *
 * The Cloudflare ranges are bundled data (ADR-0002), not a trust default:
 * they only ever enter the effective set when an administrator explicitly
 * enables `trust_cloudflare`. `is_cloudflare()` is unconditional — it
 * answers "is this specific address inside Cloudflare's published ranges",
 * independent of whether the preset is on, because `ClientIpResolver` needs
 * that fact on its own to distinguish direct-mode from chained-mode trust
 * (Revision 3 §6 step 4a).
 *
 * WordPress-free, pure data plus pure membership checks — no I/O, no
 * settings access of its own. `Plugin` extracts `trusted_proxies` and
 * `trust_cloudflare` from `Settings` once and injects them here.
 *
 * @internal
 * @final
 */
final class TrustedProxies {

	/**
	 * Cloudflare's published IPv4 and IPv6 ranges
	 * (https://www.cloudflare.com/ips-v4/, https://www.cloudflare.com/ips-v6/),
	 * bundled as a dated constant rather than fetched at runtime (ADR-0002):
	 * the default posture stays "zero outbound HTTP", tests stay
	 * deterministic, and the list stays auditable (in the repo, dated,
	 * diffable). Staleness is handled operationally — see
	 * CLOUDFLARE_RANGES_DATE and docs/TRUSTED_PROXIES.md.
	 */
	public const CLOUDFLARE_RANGES = array(
		// IPv4.
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		// IPv6.
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * The date CLOUDFLARE_RANGES was last verified against
	 * cloudflare.com/ips-v4 and cloudflare.com/ips-v6. Surfaced in
	 * diagnostics (R6) so staleness is visible rather than silent; refreshed
	 * only by deliberately re-editing this file (or the 1.1 CLI command),
	 * never by an automatic runtime fetch.
	 */
	public const CLOUDFLARE_RANGES_DATE = '2026-07-28';

	/**
	 * Stores the administrator's configuration verbatim.
	 *
	 * @param string[] $configured_cidrs Admin-configured CIDRs (or bare IPs). Empty means none configured.
	 * @param bool     $trust_cloudflare Whether the Cloudflare preset is enabled.
	 */
	public function __construct(
		private readonly array $configured_cidrs,
		private readonly bool $trust_cloudflare
	) {
	}

	/**
	 * Whether $ip falls within the effective trusted set: the configured
	 * CIDRs, plus the bundled Cloudflare ranges when the preset is enabled.
	 *
	 * @param string $ip An address, ideally already normalize()'d.
	 *
	 * @return bool
	 */
	public function contains( string $ip ): bool {
		foreach ( $this->configured_cidrs as $cidr ) {
			if ( IpUtils::cidr_match( $ip, $cidr ) ) {
				return true;
			}
		}

		if ( $this->trust_cloudflare ) {
			return $this->is_cloudflare( $ip );
		}

		return false;
	}

	/**
	 * Whether $ip falls within Cloudflare's published ranges specifically —
	 * unconditional on the trust_cloudflare preset, since ClientIpResolver
	 * needs this fact on its own to distinguish direct-mode trust (the peer
	 * itself is Cloudflare) from chained-mode trust (a configured proxy sits
	 * between Cloudflare and PHP).
	 *
	 * @param string $ip An address, ideally already normalize()'d.
	 *
	 * @return bool
	 */
	public function is_cloudflare( string $ip ): bool {
		foreach ( self::CLOUDFLARE_RANGES as $range ) {
			if ( IpUtils::cidr_match( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the Cloudflare preset is enabled.
	 *
	 * @return bool
	 */
	public function trusts_cloudflare(): bool {
		return $this->trust_cloudflare;
	}

	/**
	 * The configured CIDR (or bare IP) $ip matched, if any — the specific
	 * admin-configured entry, never the bundled Cloudflare ranges (that
	 * distinction is is_cloudflare()'s job). For diagnostics ("peer matched
	 * against which entry", Revision 3 §12): the first matching entry in
	 * configured order, or null when none matches.
	 *
	 * @param string $ip An address, ideally already normalize()'d.
	 *
	 * @return string|null
	 */
	public function matched_entry( string $ip ): ?string {
		foreach ( $this->configured_cidrs as $cidr ) {
			if ( IpUtils::cidr_match( $ip, $cidr ) ) {
				return $cidr;
			}
		}

		return null;
	}

	/**
	 * The number of admin-configured CIDRs, excluding the bundled Cloudflare
	 * ranges — for diagnostics ("configured count", Revision 3 §12).
	 *
	 * @return int
	 */
	public function configured_count(): int {
		return count( $this->configured_cidrs );
	}

	/**
	 * Whether this instance, on its own, trusts nothing at all: no
	 * configured CIDRs and the Cloudflare preset off. Does not account for
	 * CIDRs a consumer adds via the universal_geo_trusted_proxies filter —
	 * that union is ClientIpResolver's own computation (Revision 3 §6).
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->configured_cidrs && ! $this->trust_cloudflare;
	}
}
