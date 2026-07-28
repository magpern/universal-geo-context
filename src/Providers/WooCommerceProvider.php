<?php
/**
 * WooCommerce geolocation provider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers;

use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Http\IpUtils;
use UniversalGeo\Model\GeoCandidate;

/**
 * A local-database lookup through WooCommerce's own geolocation
 * infrastructure (Revision 3 §7, ADR-0006). WooCommerce satisfies
 * GeoProviderInterface exactly like any other provider — a parallel
 * integration layer would mean a special-cased code path for one
 * dependency.
 *
 * Calls `WC_Geolocation::geolocate_ip( $ip, false, false )` — the explicit
 * IP with both fallbacks off. Passing the IP stops WooCommerce reading its
 * own spoofable headers (the exact WC_Geolocation::get_ip_address() left-
 * to-right XFF read this plugin's whole trust model exists to avoid); the
 * two `false`s disable `geolocate_via_api()` and `get_external_ip_address()`,
 * so this call performs zero outbound HTTP. Country only — WooCommerce's
 * MaxMind integration leaves `state` empty in every code path this plugin
 * targets, so `region_code` is always null here regardless of what
 * WooCommerce might one day return in that key.
 *
 * @internal
 * @final
 */
final class WooCommerceProvider implements GeoProviderInterface {

	/**
	 * Re-entrancy guard. `woocommerce_geolocate_ip` and
	 * `woocommerce_get_geolocation` are public WooCommerce filters; a site
	 * that wires this plugin's resolution into either of them would recurse
	 * through `WC_Geolocation::geolocate_ip()` back into this exact method.
	 * Set for the duration of the call and always cleared afterward (even
	 * on exception), so a second, nested entry returns null instead of
	 * recursing. Static because the guard must hold across the whole
	 * WC_Geolocation call, not per-instance — one process-wide call stack.
	 *
	 * @var bool
	 */
	private static bool $in_flight = false;

	/**
	 * The stable provider id used for attribution and the confidence table.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'woocommerce';
	}

	/**
	 * Available exactly when WooCommerce's geolocation class is loaded. A
	 * missing MaxMind database inside WooCommerce is not detected here —
	 * that surfaces as an ordinary miss (empty country from geolocate_ip()),
	 * which diagnostics explains rather than this method pre-empting it.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( 'WC_Geolocation' );
	}

	/**
	 * Looks up $ip via WooCommerce's local geolocation database.
	 *
	 * Self-guards against a non-public $ip (the M1/M2 architecture
	 * reconciliation's provider self-guard policy — Revision 3 §6 step 5's
	 * "skip IP-based providers" outcome, implemented here since
	 * GeoProviderInterface has no generic pre-filter mechanism) and against
	 * re-entrancy. Any `Throwable` from WooCommerce's own code is allowed to
	 * propagate — `ContextResolver`'s provider loop already wraps every
	 * `resolve()` call in `try/catch(Throwable)` ("a provider can never
	 * fatal a page view", Revision 3 §7), so this method does not duplicate
	 * that catch; it only guarantees its own `$in_flight` flag is cleared
	 * even when WooCommerce throws, via `finally`.
	 *
	 * @param string $ip The already-resolved, already-normalized client IP.
	 *
	 * @return GeoCandidate|null
	 */
	public function resolve( string $ip ): ?GeoCandidate {
		if ( self::$in_flight ) {
			return null;
		}

		if ( ! IpUtils::is_public( $ip ) ) {
			return null;
		}

		self::$in_flight = true;

		try {
			$result = \WC_Geolocation::geolocate_ip( $ip, false, false );
		} finally {
			self::$in_flight = false;
		}

		if ( ! is_array( $result ) || ! isset( $result['country'] ) || ! is_string( $result['country'] ) || '' === $result['country'] ) {
			return null;
		}

		return new GeoCandidate( $result['country'], null );
	}
}
