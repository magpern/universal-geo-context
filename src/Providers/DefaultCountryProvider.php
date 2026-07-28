<?php
/**
 * Fallback provider: the site operator's configured default country.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers;

use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Model\GeoCandidate;

/**
 * The terminal provider in Revision 3's fixed chain (provider id 'default',
 * confidence 0.10 — assigned by ContextResolver, a later milestone, not
 * here): when every other provider misses, this one answers from the site
 * operator's configured default country instead of leaving the visitor
 * unknown.
 *
 * A pure fact carrier, like every provider: it assigns no confidence and no
 * source (ContextResolver's job, keyed by get_id()), performs no I/O, never
 * inspects $ip, and does not validate or normalize the configured country
 * beyond trusting its constructor argument. Structural/ISO-3166 validation
 * is GeoValidator's job, applied uniformly to every provider's candidate by
 * the resolver loop (Revision 3 §7) — duplicating it here would be
 * redundant and could diverge from that single source of truth.
 *
 * @internal
 * @final
 */
final class DefaultCountryProvider implements GeoProviderInterface {

	/**
	 * Stores the configured default country as given, without validating it.
	 *
	 * @param string $default_country The configured default country, exactly as Settings stores it: '' (unconfigured) or an uppercase two-letter code.
	 */
	public function __construct(
		private readonly string $default_country
	) {
	}

	/**
	 * The stable provider id used for attribution and the confidence table.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'default';
	}

	/**
	 * Available whenever a default country is configured. An empty string
	 * means "unconfigured", not "invalid" — Settings::default_country's own
	 * documented contract (empty produces unknown, not a guess).
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return '' !== $this->default_country;
	}

	/**
	 * Returns the configured default country. $ip is ignored entirely:
	 * this provider answers from configuration, never from the network or
	 * the request.
	 *
	 * @param string $ip Ignored; required only to satisfy GeoProviderInterface.
	 *
	 * @return GeoCandidate|null
	 */
	public function resolve( string $ip ): ?GeoCandidate { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( '' === $this->default_country ) {
			return null;
		}

		return new GeoCandidate( $this->default_country, null );
	}
}
