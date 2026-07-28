<?php
/**
 * Call-counting, configurable ClientIpResolverInterface double.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Doubles;

use UniversalGeo\Contracts\ClientIpResolverInterface;
use UniversalGeo\Model\ResolvedClientIp;

/**
 * A ClientIpResolverInterface double returning a fixed, configured result
 * and recording how many times resolve() was called — needed to test
 * ContextResolver's request-level memoization (the client-IP resolver must
 * be consulted at most once per resolution). No mocking framework (Revision
 * 3 §16); ClientIpResolverInterface is one method, cheap to hand-write.
 */
final class FakeClientIpResolver implements ClientIpResolverInterface {

	/**
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * @param ResolvedClientIp|null $result Value returned by resolve().
	 */
	public function __construct(
		private readonly ?ResolvedClientIp $result = null
	) {
	}

	public function resolve(): ?ResolvedClientIp {
		++$this->calls;

		return $this->result;
	}
}
