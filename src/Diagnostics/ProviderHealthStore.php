<?php
/**
 * Bounded, scrubbed, throttled provider-failure visibility.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Diagnostics;

/**
 * Owns the single `universal_geo_provider_health` option (M3 architecture
 * report §3.3 F2): the corrected owner of provider-health data. Revision 3
 * §9 describes this record as owned by `DiagnosticsService`, but that class
 * is constructed only on real wp-admin requests, while provider failures
 * happen on front-end requests — this store is instead constructed on
 * every request (`Plugin::build_graph()`), a leaf service with no
 * constructor dependencies of its own, touching only its own option.
 *
 * Privacy (ADR-0005): never writes on a successful resolution (the call
 * site — `Plugin`'s injected failure callback — only ever calls `record()`
 * from inside the same closure that already fires the
 * `universal_geo_provider_failed` action, i.e. only on a provider
 * `Throwable`). Every persisted message is scrubbed of IPv4/IPv6-shaped
 * tokens and truncated (F5) before it ever reaches storage — the live
 * action's own payload is unchanged (existing public behavior), only the
 * persisted copy is scrubbed. Writes are throttled (F6): only when the
 * scrubbed error signature (class + message) changed, or at least
 * `THROTTLE_SECONDS` have elapsed since the last persisted write for that
 * provider — `approx_count` is therefore approximate by design. The option
 * is single, non-autoloaded (`add_option(..., '', false)`), and holds a
 * fixed, bounded shape: exactly four keys per known provider id, with
 * stale entries (untouched longer than `STALE_AFTER_SECONDS`) pruned on
 * every write.
 *
 * @internal
 * @final
 */
final class ProviderHealthStore {

	/**
	 * The option this class exclusively owns.
	 */
	public const OPTION_NAME = 'universal_geo_provider_health';

	/**
	 * Minimum seconds between persisted writes for the same provider when
	 * the error signature hasn't changed — matches GeoCache's own negative-
	 * result TTL, the same "don't hammer wp_options on every anonymous
	 * request" reasoning (F6).
	 */
	private const THROTTLE_SECONDS = 300;

	/**
	 * Bounded length a persisted error message is truncated to.
	 */
	private const MAX_MESSAGE_LENGTH = 200;

	/**
	 * A provider entry untouched for longer than this is pruned on the next
	 * write — keeps the option bounded even across provider id churn (a
	 * removed/renamed provider's stale entry doesn't linger forever).
	 */
	private const STALE_AFTER_SECONDS = 7 * 86400;

	/**
	 * Records one provider failure, scrubbed, bounded, and throttled.
	 *
	 * Never called on a successful resolution — the failure callback is the
	 * only call site, and it only fires from inside ContextResolver's own
	 * catch(Throwable) branches.
	 *
	 * @param string $provider_id The failing provider's get_id().
	 * @param string $reason      The exception's class and message (ContextResolver's "get_class($e) . ': ' . $e->getMessage()" format), never a client IP by contract — scrubbed defensively anyway, since a provider's own exception text is not otherwise validated.
	 *
	 * @return void
	 */
	public function record( string $provider_id, string $reason ): void {
		[ $class, $message ] = $this->split_reason( $reason );
		$scrubbed_message    = $this->truncate( $this->scrub( $message ) );

		$records  = $this->raw_read();
		$now      = time();
		$existing = $records[ $provider_id ] ?? null;

		$existing_signature = null !== $existing
			? ( (string) ( $existing['last_error_class'] ?? '' ) ) . '|' . ( (string) ( $existing['last_error_message'] ?? '' ) )
			: null;
		$new_signature      = $class . '|' . $scrubbed_message;

		$signature_changed = $new_signature !== $existing_signature;
		$throttle_elapsed  = null === $existing || ( $now - (int) ( $existing['last_seen_at'] ?? 0 ) ) >= self::THROTTLE_SECONDS;

		if ( ! $signature_changed && ! $throttle_elapsed ) {
			return;
		}

		$approx_count = ( null !== $existing ? (int) ( $existing['approx_count'] ?? 0 ) : 0 ) + 1;

		$records[ $provider_id ] = array(
			'last_error_class'   => $class,
			'last_error_message' => $scrubbed_message,
			'approx_count'       => $approx_count,
			'last_seen_at'       => $now,
		);

		$this->persist( $this->prune_stale( $records, $now ) );
	}

	/**
	 * The bounded, already-scrubbed record — for diagnostics only.
	 *
	 * @return array<string, array{last_error_class: string, last_error_message: string, approx_count: int, last_seen_at: int}>
	 */
	public function read(): array {
		$records = array();

		foreach ( $this->raw_read() as $provider_id => $record ) {
			if ( ! is_string( $provider_id ) || ! is_array( $record ) ) {
				continue;
			}

			$records[ $provider_id ] = array(
				'last_error_class'   => is_string( $record['last_error_class'] ?? null ) ? $record['last_error_class'] : '',
				'last_error_message' => is_string( $record['last_error_message'] ?? null ) ? $record['last_error_message'] : '',
				'approx_count'       => (int) ( $record['approx_count'] ?? 0 ),
				'last_seen_at'       => (int) ( $record['last_seen_at'] ?? 0 ),
			);
		}

		return $records;
	}

	/**
	 * Splits ContextResolver's "ClassName: message" reason format into its
	 * two parts. A reason with no ": " separator is treated as message-only
	 * with an empty class — tolerant, never throws.
	 *
	 * @param string $reason The raw (unscrubbed) reason string.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function split_reason( string $reason ): array {
		$position = strpos( $reason, ': ' );

		if ( false === $position ) {
			return array( '', $reason );
		}

		return array( substr( $reason, 0, $position ), substr( $reason, $position + 2 ) );
	}

	/**
	 * Replaces IPv4- and IPv6-shaped tokens with a fixed placeholder (F5) —
	 * a provider's own exception message is not otherwise validated, and a
	 * reader exception could plausibly echo the address it was looking up.
	 * Deliberately conservative: over-scrubbing a hex-heavy non-IP token is
	 * an acceptable false positive; leaking a real address is not.
	 *
	 * @param string $text Unscrubbed text.
	 *
	 * @return string
	 */
	private function scrub( string $text ): string {
		$text = (string) preg_replace(
			'/\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/',
			'[ip]',
			$text
		);

		$not_hex_or_colon_before = '(?<![0-9A-Fa-f:])';
		$not_hex_or_colon_after  = '(?![0-9A-Fa-f:])';

		$text = (string) preg_replace(
			'/' . $not_hex_or_colon_before . '(?:[0-9A-Fa-f]{1,4}:){1,7}:(?:[0-9A-Fa-f]{1,4})?(?::[0-9A-Fa-f]{1,4}){0,6}' . $not_hex_or_colon_after
			. '|' . $not_hex_or_colon_before . '(?:[0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}' . $not_hex_or_colon_after
			. '|' . $not_hex_or_colon_before . '::(?:[0-9A-Fa-f]{1,4}:){0,6}[0-9A-Fa-f]{1,4}' . $not_hex_or_colon_after . '/',
			'[ip]',
			$text
		);

		return $text;
	}

	/**
	 * Truncates a message to a fixed bounded length.
	 *
	 * @param string $message Already-scrubbed message text.
	 *
	 * @return string
	 */
	private function truncate( string $message ): string {
		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $message ) <= self::MAX_MESSAGE_LENGTH ) {
				return $message;
			}

			return mb_substr( $message, 0, self::MAX_MESSAGE_LENGTH - 1 ) . '…';
		}

		if ( strlen( $message ) <= self::MAX_MESSAGE_LENGTH ) {
			return $message;
		}

		return substr( $message, 0, self::MAX_MESSAGE_LENGTH - 1 ) . '…';
	}

	/**
	 * Removes any provider entry not written to in over STALE_AFTER_SECONDS
	 * — keeps the option bounded across provider id churn, independent of
	 * how many distinct provider ids have ever existed.
	 *
	 * @param array<string, array<string, mixed>> $records The records about to be persisted.
	 * @param int                                 $now     The current timestamp.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function prune_stale( array $records, int $now ): array {
		foreach ( $records as $provider_id => $record ) {
			$last_seen_at = (int) ( $record['last_seen_at'] ?? 0 );

			if ( ( $now - $last_seen_at ) > self::STALE_AFTER_SECONDS ) {
				unset( $records[ $provider_id ] );
			}
		}

		return $records;
	}

	/**
	 * Reads the raw stored option, tolerantly.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function raw_read(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Persists the records, creating the option non-autoloaded on first
	 * write (never eagerly at construction — this class has no constructor
	 * work at all) and updating it thereafter.
	 *
	 * @param array<string, array<string, mixed>> $records The complete records to persist.
	 *
	 * @return void
	 */
	private function persist( array $records ): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, $records, '', false );
			return;
		}

		update_option( self::OPTION_NAME, $records );
	}
}
