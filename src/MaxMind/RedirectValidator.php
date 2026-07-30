<?php
/**
 * Pure validator for the redirect-safe download flow's Location header.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\MaxMind;

/**
 * Stateless, static-only validator (no DI, no constructor — the same
 * posture as `Http\IpUtils`/`Resolver\GeoValidator`) for the raw `Location`
 * header value `HttpTransport::get_redirect_location()` reports. This is the
 * gate that makes the M6 redirect-safe download flow safe: only a URL this
 * class accepts is ever handed to `HttpTransport::download()` — the second
 * hop that carries no credentials at all
 * (`docs/ARCHITECTURE_FREEZE.md` §12.3).
 *
 * Rejects, in order: a relative or otherwise unparseable URL, a non-`https`
 * scheme, a URL carrying a userinfo component (`user:pass@host` — a
 * malicious or compromised redirect target could otherwise smuggle
 * credentials into the URL itself), a malformed/empty host, and any host not
 * matching the documented allowlist of expected storage-backend hosts. Pure
 * and WordPress-free: no I/O, no network access, no WordPress function call.
 *
 * @internal
 * @final
 */
final class RedirectValidator {

	/**
	 * Host suffixes the validated redirect target's host must match (exact
	 * match or a subdomain of one of these). Currently just MaxMind's
	 * confirmed live storage backend (Cloudflare R2) — reviewed research,
	 * not guessed. If MaxMind changes storage providers, downloads fail
	 * closed (CODE_UNSAFE_REDIRECT) rather than silently trusting an
	 * unreviewed host; updating this list is then a deliberate, reviewed
	 * change, not a silent break.
	 */
	private const ALLOWED_HOST_SUFFIXES = array(
		'r2.cloudflarestorage.com',
	);

	/**
	 * Private constructor: this class is never instantiated.
	 */
	private function __construct() {
	}

	/**
	 * Validates a raw Location header value.
	 *
	 * @param string $location The raw, untrusted Location header value.
	 *
	 * @return array{ok: bool, url: ?string, reason: ?string} `url` is set only when `ok` is true; `reason` is a short, stable machine-readable code, never containing the raw URL (never logged/surfaced verbatim, per the redirect-target redaction rule).
	 */
	public static function validate( string $location ): array {
		$location = trim( $location );

		if ( '' === $location ) {
			return self::rejected( 'empty' );
		}

		// Native parse_url(), not wp_parse_url(): this class is pure and
		// WordPress-free, the same posture as IpUtils/GeoValidator.
		$parts = parse_url( $location ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return self::rejected( 'unparseable' );
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return self::rejected( 'not_https' );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::rejected( 'userinfo_present' );
		}

		$host = strtolower( trim( $parts['host'] ) );

		if ( '' === $host ) {
			return self::rejected( 'empty_host' );
		}

		if ( ! self::host_allowed( $host ) ) {
			return self::rejected( 'host_not_allowed' );
		}

		return array(
			'ok'     => true,
			'url'    => $location,
			'reason' => null,
		);
	}

	/**
	 * Whether $host is exactly one of the allowed suffixes, or a subdomain
	 * of one.
	 *
	 * @param string $host The already-lowercased, already-trimmed host.
	 *
	 * @return bool
	 */
	private static function host_allowed( string $host ): bool {
		foreach ( self::ALLOWED_HOST_SUFFIXES as $suffix ) {
			if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds a rejected result.
	 *
	 * @param string $reason A short, stable machine-readable rejection code.
	 *
	 * @return array{ok: bool, url: ?string, reason: ?string}
	 */
	private static function rejected( string $reason ): array {
		return array(
			'ok'     => false,
			'url'    => null,
			'reason' => $reason,
		);
	}
}
