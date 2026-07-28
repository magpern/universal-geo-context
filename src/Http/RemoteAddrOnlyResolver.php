<?php
/**
 * Baseline client-IP resolver: REMOTE_ADDR only, no forwarding headers.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Http;

use UniversalGeo\Contracts\ClientIpResolverInterface;
use UniversalGeo\Model\ResolvedClientIp;

/**
 * Resolves the client IP exclusively from $_SERVER['REMOTE_ADDR'] — the TCP
 * peer PHP itself accepted the connection from. Every forwarding or CDN
 * header (X-Forwarded-For, X-Real-IP, CF-Connecting-IP, Forwarded,
 * True-Client-IP, Client-IP, or any other) is ignored unconditionally:
 * there is no trusted-proxy configuration here to ever enable them.
 *
 * All address handling — normalization and public/private classification —
 * delegates entirely to IpUtils; nothing is duplicated here.
 *
 * This is the fail-closed default the full trust-boundary-aware
 * ClientIpResolver (M2, src/Http/ClientIpResolver.php) layers on top of
 * once ServerRequest and TrustedProxies exist. It replaces this class
 * outright (Revision 3 §23); it does not extend it.
 *
 * @final
 */
final class RemoteAddrOnlyResolver implements ClientIpResolverInterface {

	/**
	 * Reads $_SERVER['REMOTE_ADDR'] and returns the resolved peer.
	 *
	 * Returns null when the key is missing, non-string, or IpUtils::normalize()
	 * rejects the value (empty, whitespace-only, or not a syntactically
	 * valid IPv4 or IPv6 address). Never mutates $_SERVER and never reads
	 * any other key.
	 *
	 * @return ResolvedClientIp|null
	 */
	public function resolve(): ?ResolvedClientIp {
		// wp_unslash() is a WordPress function this class must not depend on
		// (framework independence is architecturally required here);
		// IpUtils::normalize() is the real validation/sanitization step,
		// applied immediately below, before $raw is used for anything else.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = $_SERVER['REMOTE_ADDR'] ?? null;

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$ip = IpUtils::normalize( $raw );

		if ( null === $ip ) {
			return null;
		}

		return new ResolvedClientIp( $ip, 'REMOTE_ADDR', true, IpUtils::is_public( $ip ) );
	}
}
