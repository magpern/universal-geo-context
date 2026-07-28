<?php
/**
 * Contract for a single geo-lookup provider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Contracts;

use UniversalGeo\Model\GeoCandidate;

/**
 * A provider attempts one geographic lookup and returns facts only.
 *
 * Implementations must not cache, validate, normalize, know about other
 * providers, or read WordPress settings — those are resolver
 * responsibilities. A provider may ignore $ip entirely (e.g. a header-based
 * provider) and answer from its own constructor-injected state instead.
 */
interface GeoProviderInterface {

	/**
	 * A stable identifier used for attribution and the confidence table.
	 *
	 * @return string Lowercase identifier matching [a-z0-9_-]+.
	 */
	public function get_id(): string;

	/**
	 * Whether this provider can currently attempt a lookup.
	 *
	 * Must be cheap: no I/O beyond class_exists() / is_readable() checks.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Attempts a lookup and returns raw facts, or null on a miss.
	 *
	 * @param string $ip Advisory client IP; header-based providers may ignore it.
	 *
	 * @return GeoCandidate|null
	 */
	public function resolve( string $ip ): ?GeoCandidate;
}
