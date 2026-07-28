<?php
/**
 * Immutable result of client-IP resolution.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Model;

use UniversalGeo\Http\IpUtils;

/**
 * The IP a ClientIpResolverInterface implementation resolved, plus the
 * metadata later trust-boundary logic needs to judge it.
 *
 * Pure value object: no WordPress dependency, no validation beyond typing
 * (the resolver that constructs one is responsible for handing it an
 * already-validated IP). Per Revision 3 §4.2 this type is @internal: it is
 * created inside resolution, consumed by the resolver, and never handed to
 * a consumer of the plugin's public API.
 *
 * @internal
 * @final
 */
final class ResolvedClientIp {

	/**
	 * Constructs a resolved client IP, storing its fields as supplied.
	 *
	 * @param string $ip             The resolved client IP, exactly as validated by the caller.
	 * @param string $header         Which source produced $ip: 'REMOTE_ADDR' | 'CF-Connecting-IP' | 'X-Forwarded-For' | 'X-Real-IP'.
	 * @param bool   $chain_verified Whether the proxy chain (if any) between the visitor and PHP was verified.
	 * @param bool   $is_public      Whether $ip is a publicly routable address.
	 */
	public function __construct(
		public readonly string $ip,
		public readonly string $header,
		public readonly bool $chain_verified,
		public readonly bool $is_public
	) {
	}

	/**
	 * Masks $ip for safe display — the only escaping form Revision 3
	 * defines for this type (§4.2). Delegates entirely to IpUtils::mask();
	 * no masking logic is duplicated here.
	 *
	 * @return string
	 */
	public function masked(): string {
		return IpUtils::mask( $this->ip );
	}
}
