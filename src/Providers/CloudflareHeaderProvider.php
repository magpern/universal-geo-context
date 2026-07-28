<?php
/**
 * Cloudflare CF-IPCountry header provider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers;

use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Model\GeoCandidate;

/**
 * Reads the CF-IPCountry header Cloudflare's edge attaches when the *Add
 * visitor location headers* Managed Transform is enabled on the zone
 * (Revision 3 §7 — runtime presence is an M2 acceptance item, not something
 * this class can control or detect beyond "header absent this request").
 *
 * `is_available()` delegates entirely to `ClientIpResolver::cloudflare_header_trusted()`
 * — the same direct/chained trust verdict `resolve()`'s own header walk
 * uses, "decided once by ClientIpResolver and injected, not re-derived"
 * (Revision 3 §7). This provider never re-derives or duplicates any part of
 * the trust decision itself.
 *
 * No rejection of Cloudflare's own "don't know" sentinel values (`XX`,
 * `T1`, `EU`, `AP`) happens here: `GeoValidator::country()` — applied
 * uniformly to every provider's candidate by `ContextResolver`'s loop —
 * already rejects all four (plus `ZZ`, `A1`, `A2`, `O1`). Duplicating that
 * list here would diverge from GeoValidator's single source of truth,
 * exactly the reasoning `DefaultCountryProvider` (M1) already established
 * for this codebase.
 *
 * Country only: Cloudflare's region-equivalent header (`CF-Region`) is an
 * Enterprise-only feature and is not part of v1 — `region_code` is always
 * null.
 *
 * @internal
 * @final
 */
final class CloudflareHeaderProvider implements GeoProviderInterface {

	/**
	 * Stores the injected dependencies.
	 *
	 * @param ServerRequest    $request    Reads CF-IPCountry via header(), never $_SERVER directly.
	 * @param ClientIpResolver $ip_resolver Supplies the trust verdict this provider itself never derives.
	 */
	public function __construct(
		private readonly ServerRequest $request,
		private readonly ClientIpResolver $ip_resolver
	) {
	}

	/**
	 * The stable provider id used for attribution and the confidence table.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'cloudflare';
	}

	/**
	 * Available exactly when the CF-Connecting-IP/CF-IPCountry trust
	 * verdict is true: trust_cloudflare is enabled and the peer is
	 * trusted — either Cloudflare itself (direct mode) or a configured
	 * trusted proxy in front of which the admin has asserted Cloudflare
	 * fronts the chain (chained mode). Without a verified chain this
	 * provider can never fire, on any topology.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->ip_resolver->cloudflare_header_trusted();
	}

	/**
	 * Reads CF-IPCountry. $ip is ignored entirely — this provider answers
	 * from the request header, never the network or the resolved address.
	 *
	 * @param string $ip Ignored; required only to satisfy GeoProviderInterface.
	 *
	 * @return GeoCandidate|null
	 */
	public function resolve( string $ip ): ?GeoCandidate { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$raw = $this->request->header( 'CF-IPCountry' );

		if ( null === $raw ) {
			return null;
		}

		return new GeoCandidate( $raw, null );
	}
}
