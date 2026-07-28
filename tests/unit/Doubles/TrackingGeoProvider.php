<?php
/**
 * Call-counting, optionally-throwing GeoProviderInterface double.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Doubles;

use RuntimeException;
use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Model\GeoCandidate;

/**
 * A GeoProviderInterface double that records how many times each method was
 * called and can be configured to throw from resolve() — needed to test
 * ContextResolver's memoization (providers called at most once per
 * resolution) and provider-failure handling. No mocking framework (Revision
 * 3 §16); hand-written like FakeGeoProvider, with tracking added.
 */
final class TrackingGeoProvider implements GeoProviderInterface {

	/**
	 * @var int
	 */
	public int $is_available_calls = 0;

	/**
	 * @var int
	 */
	public int $resolve_calls = 0;

	/**
	 * @var string|null
	 */
	public ?string $last_resolve_ip = null;

	/**
	 * @param string            $id        Value returned by get_id().
	 * @param bool              $available Value returned by is_available().
	 * @param GeoCandidate|null $candidate Value returned by resolve(), when not configured to throw.
	 * @param bool              $throws    Whether resolve() throws instead of returning.
	 */
	public function __construct(
		private readonly string $id,
		private readonly bool $available = true,
		private readonly ?GeoCandidate $candidate = null,
		private readonly bool $throws = false
	) {
	}

	public function get_id(): string {
		return $this->id;
	}

	public function is_available(): bool {
		++$this->is_available_calls;

		return $this->available;
	}

	public function resolve( string $ip ): ?GeoCandidate {
		++$this->resolve_calls;
		$this->last_resolve_ip = $ip;

		if ( $this->throws ) {
			throw new RuntimeException( 'TrackingGeoProvider configured to throw.' );
		}

		return $this->candidate;
	}
}
