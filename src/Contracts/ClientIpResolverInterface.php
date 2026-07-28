<?php
/**
 * Contract for resolving the visitor's client IP address.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Contracts;

use UniversalGeo\Model\ResolvedClientIp;

/**
 * Decides who is asking — never where they are.
 *
 * An implementation determines the visitor's client IP and returns it as a
 * ResolvedClientIp, or null when no usable request context exists (e.g.
 * CLI/cron, or REMOTE_ADDR missing/malformed). It does not judge trust,
 * geography, or policy — that belongs to whatever calls it.
 */
interface ClientIpResolverInterface {

	/**
	 * Resolves the current request's client IP.
	 *
	 * @return ResolvedClientIp|null
	 */
	public function resolve(): ?ResolvedClientIp;
}
