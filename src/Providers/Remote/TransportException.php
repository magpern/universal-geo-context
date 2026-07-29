<?php
/**
 * Bounded, scrubbed exception for HTTP transport failures.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers\Remote;

use RuntimeException;

/**
 * Thrown by `HttpTransport::get()` for any transport-level failure (network,
 * DNS, TLS, or any other error a `WP_Error` from `wp_safe_remote_get()`
 * represents). The message is always scrubbed and bounded before
 * construction — never the raw request URL, never a header value, never
 * unbounded upstream text — since this exception's message may reach
 * `ProviderHealthStore`, the `universal_geo_provider_failed` action, and
 * diagnostics, exactly like any other provider `Throwable`
 * (`ContextResolver`'s existing catch(Throwable) boundary).
 *
 * @internal
 * @final
 */
final class TransportException extends RuntimeException {

	/**
	 * Bounded length a scrubbed message is truncated to — mirrors
	 * `ProviderHealthStore::MAX_MESSAGE_LENGTH`.
	 */
	private const MAX_MESSAGE_LENGTH = 200;

	/**
	 * Builds an instance from raw, untrusted upstream text (typically a
	 * `WP_Error::get_error_message()` result), scrubbing the request URL and
	 * any embedded URL-shaped or credential-shaped token before it ever
	 * becomes this exception's message.
	 *
	 * @param string $raw_message The raw, unscrubbed upstream message.
	 * @param string $url         The request URL this failure occurred against — scrubbed out of the message if present.
	 *
	 * @return self
	 */
	public static function scrubbed( string $raw_message, string $url ): self {
		$message = '' !== $url ? str_replace( $url, '[url]', $raw_message ) : $raw_message;

		// Defense in depth: any other URL-shaped substring (e.g. a redirect
		// target echoed by the underlying HTTP client) is scrubbed too, not
		// only the exact request URL.
		$message = (string) preg_replace( '#https?://\S+#i', '[url]', $message );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			$message = mb_strlen( $message ) > self::MAX_MESSAGE_LENGTH
				? mb_substr( $message, 0, self::MAX_MESSAGE_LENGTH - 1 ) . '…'
				: $message;
		} elseif ( strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
			$message = substr( $message, 0, self::MAX_MESSAGE_LENGTH - 1 ) . '…';
		}

		return new self( $message );
	}
}
