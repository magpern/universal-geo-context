<?php
/**
 * Hand-rolled GeoProviderInterface double for unit tests.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Doubles;

use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Model\GeoCandidate;

/**
 * A configurable GeoProviderInterface double. No mocking framework is used
 * in this project (Revision 3 §16): GeoProviderInterface is three methods,
 * cheap enough to hand-write.
 */
final class FakeGeoProvider implements GeoProviderInterface {

	/**
	 * @param string            $id        Value returned by get_id().
	 * @param bool              $available Value returned by is_available().
	 * @param GeoCandidate|null $candidate Value returned by resolve().
	 */
	public function __construct(
		private readonly string $id,
		private readonly bool $available = true,
		private readonly ?GeoCandidate $candidate = null
	) {
	}

	public function get_id(): string {
		return $this->id;
	}

	public function is_available(): bool {
		return $this->available;
	}

	public function resolve( string $ip ): ?GeoCandidate { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->candidate;
	}
}
