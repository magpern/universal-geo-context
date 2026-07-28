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
 * performs, including CIDR trust matching: `cidr_match()` is the plugin's
 * single canonical matcher, used both internally (by `is_public()`, against
 * its own hardcoded range table) and externally (by `TrustedProxies`,
 * against admin-configured CIDRs and the bundled Cloudflare ranges, M2).
 * No WordPress dependency.
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
	 * Human-readable classification labels for describe(), aligned index-for
	 * -index with IPV4_NON_PUBLIC_RANGES. Purely cosmetic — is_public()'s own
	 * true/false verdict never consults this table.
	 */
	private const IPV4_RANGE_LABELS = array(
		'unspecified',
		'private',
		'CGNAT',
		'loopback',
		'link-local',
		'private',
		'reserved',
		'documentation',
		'private',
		'benchmarking',
		'documentation',
		'documentation',
		'multicast',
		'reserved',
	);

	/**
	 * Human-readable classification labels for describe(), aligned index-for
	 * -index with IPV6_NON_PUBLIC_RANGES.
	 */
	private const IPV6_RANGE_LABELS = array(
		'unspecified',
		'loopback',
		'link-local',
		'unique-local',
		'multicast',
		'documentation',
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
	 * uses an explicit range table (matched via cidr_match(), the plugin's
	 * one CIDR matcher) rather than PHP's FILTER_FLAG_NO_PRIV_RANGE /
	 * FILTER_FLAG_NO_RES_RANGE alone, since those do not reliably cover
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
			if ( self::cidr_match( $ip, $range ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether $ip falls within $cidr — the plugin's single CIDR matcher
	 * (Revision 3 §4.3), used both internally by is_public() and externally
	 * by TrustedProxies (M2) against admin-configured and bundled ranges.
	 *
	 * Handles IPv4 and IPv6. A bare address with no "/prefix" is treated as
	 * a /32 (IPv4) or /128 (IPv6) — an exact-address match. $ip and $cidr's
	 * subnet are each reduced from IPv4-mapped IPv6 form
	 * ("::ffff:a.b.c.d") to plain IPv4 before comparison, so a mapped
	 * address matches a plain IPv4 CIDR and vice versa. A family mismatch
	 * (one side IPv4, the other IPv6), a malformed address on either side,
	 * or an out-of-range prefix (> 32 for IPv4, > 128 for IPv6) all return
	 * false rather than throwing — this method never fails closed by
	 * exception, only by returning false.
	 *
	 * @param string $ip   An address, ideally already normalize()'d.
	 * @param string $cidr A CIDR, e.g. '10.0.0.0/8', or a bare address.
	 *
	 * @return bool
	 */
	public static function cidr_match( string $ip, string $cidr ): bool {
		$ip = self::reduce_ipv4_mapped( trim( $ip ) );

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$slash_position = strrpos( $cidr, '/' );
		$subnet         = self::reduce_ipv4_mapped( trim( false === $slash_position ? $cidr : substr( $cidr, 0, $slash_position ) ) );

		if ( false === filter_var( $subnet, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$ip_is_ipv4     = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		$subnet_is_ipv4 = false !== filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );

		if ( $ip_is_ipv4 !== $subnet_is_ipv4 ) {
			return false;
		}

		$max_prefix = $subnet_is_ipv4 ? 32 : 128;

		if ( false === $slash_position ) {
			$prefix = $max_prefix;
		} else {
			$raw_prefix = trim( substr( $cidr, $slash_position + 1 ) );

			if ( 1 !== preg_match( '/^\d{1,3}$/', $raw_prefix ) || (int) $raw_prefix > $max_prefix ) {
				return false;
			}

			$prefix = (int) $raw_prefix;
		}

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

	/**
	 * A short human-readable classification for diagnostics (Revision 3
	 * §10): family, non-public classification (or 'public'), and the masked
	 * address — e.g. "IPv4 private (172.18.0.x)". Never returns the
	 * complete address; malformed input returns the literal string
	 * 'invalid', matching mask()'s own convention.
	 *
	 * @param string $ip An address, ideally already normalize()'d.
	 *
	 * @return string
	 */
	public static function describe( string $ip ): string {
		$reduced = self::reduce_ipv4_mapped( trim( $ip ) );

		$is_ipv4 = false !== filter_var( $reduced, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		$is_ipv6 = false !== filter_var( $reduced, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );

		if ( ! $is_ipv4 && ! $is_ipv6 ) {
			return 'invalid';
		}

		$ranges = $is_ipv4 ? self::IPV4_NON_PUBLIC_RANGES : self::IPV6_NON_PUBLIC_RANGES;
		$labels = $is_ipv4 ? self::IPV4_RANGE_LABELS : self::IPV6_RANGE_LABELS;

		$classification = 'public';

		foreach ( $ranges as $index => $range ) {
			if ( self::cidr_match( $reduced, $range ) ) {
				$classification = $labels[ $index ];
				break;
			}
		}

		return sprintf( '%s %s (%s)', $is_ipv4 ? 'IPv4' : 'IPv6', $classification, self::mask( $reduced ) );
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
}
