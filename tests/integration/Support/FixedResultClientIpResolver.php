<?php
/**
 * A minimal fixed-result ClientIpResolverInterface double for integration tests.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration\Support;

use UniversalGeo\Contracts\ClientIpResolverInterface;
use UniversalGeo\Model\ResolvedClientIp;

/**
 * Defined locally under tests/integration/ rather than reused from
 * tests/unit/Doubles/ — that directory's PSR-4 mapping
 * (`UniversalGeo\Tests\Unit\Doubles`) doesn't resolve to the lowercase
 * `tests/unit/` path on a case-sensitive filesystem, which is why the unit
 * suite's own bootstrap requires those files manually instead of relying on
 * Composer autoloading; simplest to give the integration suite its own copy.
 */
final class FixedResultClientIpResolver implements ClientIpResolverInterface {

	public function __construct( private readonly ResolvedClientIp $result ) {
	}

	public function resolve(): ?ResolvedClientIp {
		return $this->result;
	}
}
