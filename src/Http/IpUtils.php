<?php
/**
 * Pure IP address utilities: normalization, public/private classification, masking.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Http;

/**
 * One pure static class for every IP address manipulation the plugin
 * performs, outside of CIDR trust matching (TrustedProxies' job, M2). No
 * WordPress dependency.
 *
 * Every method handles a single address token per call — never a
 * comma-separated forwarding-header list, never a hostname, never a
 * network lookup.
 *
 * @final
 */
final class IpUtils {

	/**
	 * IPv4 ranges excluded from is_public(): unspecified, RFC 1918 private
	 * space, CGNAT (RFC 6598), loopback, link-local, IETF protocol
	 * assignments, documentation (TEST-NET-1/2/3), benchmarking, multicast,
	 * and reserved/future-use (which includes the broadcast address).
	 */
	private const IPV4_NON_PUBLIC_RANGES = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
	);

	/**
	 * IPv6 ranges excluded from is_public(): unspecified, loopback,
	 * link-local, unique local (ULA), multicast, and documentation.
	 */
	private const IPV6_NON_PUBLIC_RANGES = array(
		'::/128',
		'::1/128',
		'fe80::/10',
		'fc00::/7',
		'ff00::/8',
		'2001:db8::/32',
	);

	/**
	 * Normalizes one address token to a bare IPv4 or IPv6 address.
	 *
	 * Trims surrounding whitespace, strips a ":port" suffix from IPv4,
	 * unwraps bracketed IPv6 (with or without a trailing ":port"), and
	 * reduces an IPv4-mapped IPv6 address ("::ffff:a.b.c.d") to its plain
	 * IPv4 form — the three transformations Revision 3 assigns to this
	 * method. It does not otherwise canonicalize: casing and IPv6
	 * compression are preserved exactly as given. It performs no DNS
	 * resolution, accepts no hostname, and does not address zone
	 * identifiers (e.g. "fe80::1%eth0") — Revision 3 does not describe
	 * that form, so none is invented here.
	 *
	 * @param string $raw One address token, in any of the forms above.
	 *
	 * @return string|null The bare address, or null if $raw is empty or not a syntactically valid IP.
	 */
	public static function normalize( string $raw ): ?string {
		$value = trim( $raw );

		if ( '' === $value ) {
			return null;
		}

		if ( 1 === preg_match( '/^\[(?<addr>[0-9A-Fa-f:]+)\](?::\d+)?$/', $value, $matches ) ) {
			$value = $matches['addr'];
		} elseif ( 1 === preg_match( '/^(?<addr>\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}):\d+$/', $value, $matches ) ) {
			$value = $matches['addr'];
		}

		$value = self::reduce_ipv4_mapped( $value );

		return false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : null;
	}

	/**
	 * Whether $ip is publicly routable.
	 *
	 * Rejects RFC 1918 private space, CGNAT, loopback, link-local,
	 * documentation/benchmarking ranges, multicast, and reserved/future-use
	 * space for IPv4; unspecified, loopback, link-local, unique local
	 * (ULA), multicast, and documentation space for IPv6. Classification
	 * uses an explicit range table rather than PHP's FILTER_FLAG_NO_PRIV_RANGE
	 * / FILTER_FLAG_NO_RES_RANGE alone, since those do not reliably cover
	 * every range Revision 3 names (notably CGNAT and IPv6 ULA). An
	 * IPv4-mapped IPv6 address is classified by its underlying IPv4
	 * address. Malformed input is never public.
	 *
	 * @param string $ip An address, ideally already normalize()'d.
	 *
	 * @return bool
	 */
	public static function is_public( string $ip ): bool {
		$ip = self::reduce_ipv4_mapped( trim( $ip ) );

		$is_ipv4 = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		$is_ipv6 = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );

		if ( ! $is_ipv4 && ! $is_ipv6 ) {
			return false;
		}

		foreach ( ( $is_ipv4 ? self::IPV4_NON_PUBLIC_RANGES : self::IPV6_NON_PUBLIC_RANGES ) as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Masks $ip for safe display.
	 *
	 * IPv4 → the last octet replaced with 'x' (e.g. "203.0.113.x"). IPv6 →
	 * the first three colon-separated groups followed by an ellipsis (e.g.
	 * "2001:db8:1234:…"), matching Revision 3 §10's examples exactly.
	 * Never returns the complete address. Malformed input returns the
	 * literal string 'invalid' — deterministic, and it leaks nothing about
	 * whatever was passed in.
	 *
	 * @param string $ip An address, ideally already normalize()'d.
	 *
	 * @return string
	 */
	public static function mask( string $ip ): string {
		$ip = trim( $ip );

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$octets    = explode( '.', $ip );
			$octets[3] = 'x';

			return implode( '.', $octets );
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$groups = array_slice(
				array_values( array_filter( explode( ':', $ip ), static fn( string $group ): bool => '' !== $group ) ),
				0,
				3
			);

			return implode( ':', $groups ) . ':…';
		}

		return 'invalid';
	}

	/**
	 * Reduces an IPv4-mapped IPv6 address ("::ffff:a.b.c.d") to its plain
	 * IPv4 form. Any other input is returned unchanged.
	 *
	 * @param string $value An address, possibly IPv4-mapped.
	 *
	 * @return string
	 */
	private static function reduce_ipv4_mapped( string $value ): string {
		if ( 1 === preg_match( '/^::ffff:(?<addr>\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/i', $value, $matches ) ) {
			return $matches['addr'];
		}

		return $value;
	}

	/**
	 * Whether $ip falls within $cidr.
	 *
	 * Internal only: this is is_public()'s own range-membership check
	 * against a fixed, hardcoded table, not a general-purpose CIDR matcher.
	 * Admin-configured trust-list matching is TrustedProxies' job (M2) and
	 * is not exposed here.
	 *
	 * @param string $ip   An already-validated IPv4 or IPv6 address.
	 * @param string $cidr A CIDR of the same address family, e.g. '10.0.0.0/8'.
	 *
	 * @return bool
	 */
	private static function in_range( string $ip, string $cidr ): bool {
		[ $subnet, $prefix ] = explode( '/', $cidr );
		$prefix              = (int) $prefix;

		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );

		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$whole_bytes = intdiv( $prefix, 8 );
		$extra_bits  = $prefix % 8;

		if ( $whole_bytes > 0 && 0 !== substr_compare( $ip_bin, $subnet_bin, 0, $whole_bytes ) ) {
			return false;
		}

		if ( 0 === $extra_bits ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $extra_bits ) ) & 0xFF;

		return ( ord( $ip_bin[ $whole_bytes ] ) & $mask ) === ( ord( $subnet_bin[ $whole_bytes ] ) & $mask );
	}
}
