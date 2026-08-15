<?php
/**
 * Immutable resolved visitor geographic context.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Model;

use InvalidArgumentException;

/**
 * The final, resolved geographic context for a visitor — the plugin's
 * public API type.
 *
 * Pure value object: no WordPress dependency, no geography resolution, no
 * policy. Confidence is stored exactly as supplied by the resolver, never
 * computed here. `country_code` is null exactly when the visitor's country
 * is unknown; construct that state with unknown().
 *
 * @final
 */
final class VisitorContext {

	/**
	 * Schema version of the array shape returned by to_array().
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Uppercase ISO 3166-1 alpha-2 country code, or null when unknown.
	 *
	 * @var string|null
	 */
	public readonly ?string $country_code;

	/**
	 * Identifier of the provider, 'default', or 'unknown' that produced this context.
	 *
	 * @var string
	 */
	public readonly string $source;

	/**
	 * Constructs a context, validating structural shape only.
	 *
	 * @param string|null $country_code Two-letter country code (any case), or null for unknown.
	 * @param string|null $region_code  Subdivision/region code (from M13 onwards), or null for unknown.
	 * @param string      $source       Provider id, 'default', or 'unknown'.
	 * @param float       $confidence   Confidence in the country determination, 0.0 to 1.0 inclusive.
	 * @param bool        $is_cached    Whether this context came from the derived-context cache.
	 *
	 * @throws InvalidArgumentException When a non-null country code, the source, or the confidence is malformed.
	 */
	public function __construct(
		?string $country_code,
		public readonly ?string $region_code,
		string $source,
		public readonly float $confidence,
		public readonly bool $is_cached = false
	) {
		if ( null !== $country_code ) {
			$country_code = strtoupper( trim( $country_code ) );

			if ( 1 !== preg_match( '~^[A-Z]{2}$~', $country_code ) ) {
				throw new InvalidArgumentException( 'VisitorContext country code must be null or exactly two ASCII letters.' );
			}
		}

		$trimmed_source = trim( $source );

		if ( '' === $trimmed_source ) {
			throw new InvalidArgumentException( 'VisitorContext source must not be empty.' );
		}

		// is_nan() is required: NAN satisfies neither < nor > against the bounds below.
		if ( is_nan( $confidence ) || $confidence < 0.0 || $confidence > 1.0 ) {
			throw new InvalidArgumentException( 'VisitorContext confidence must be between 0.0 and 1.0 inclusive.' );
		}

		$this->country_code = $country_code;
		$this->source       = $trimmed_source;
	}

	/**
	 * Whether the visitor's country was determined.
	 *
	 * @return bool
	 */
	public function is_known(): bool {
		return null !== $this->country_code;
	}

	/**
	 * Whether a subdivision was resolved.
	 *
	 * @return bool
	 */
	public function has_region(): bool {
		return null !== $this->region_code;
	}

	/**
	 * Returns a new instance with is_cached set as given; every other field is unchanged.
	 *
	 * @param bool $is_cached Whether the new instance represents a cache hit.
	 *
	 * @return self
	 */
	public function with_cached( bool $is_cached ): self {
		return new self( $this->country_code, $this->region_code, $this->source, $this->confidence, $is_cached );
	}

	/**
	 * Exports this context as a plain array, including the schema version.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'country_code'   => $this->country_code,
			'region_code'    => $this->region_code,
			'source'         => $this->source,
			'confidence'     => $this->confidence,
			'is_cached'      => $this->is_cached,
		);
	}

	/**
	 * Tolerantly hydrates a context from a plain array.
	 *
	 * Never throws: missing or malformed fields fall back to safe values
	 * (unknown country, zero confidence) rather than raising, following the
	 * same forgiving-sanitization house convention as Settings::sanitize().
	 *
	 * @param array<string, mixed> $data Arbitrary array, typically produced by to_array().
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$country_code = $data['country_code'] ?? null;

		if ( is_string( $country_code ) ) {
			$country_code = strtoupper( trim( $country_code ) );

			if ( 1 !== preg_match( '~^[A-Z]{2}$~', $country_code ) ) {
				$country_code = null;
			}
		} else {
			$country_code = null;
		}

		$region_code = $data['region_code'] ?? null;
		$region_code = ( is_string( $region_code ) && '' !== $region_code ) ? $region_code : null;

		$source = $data['source'] ?? '';
		$source = is_string( $source ) ? trim( $source ) : '';

		if ( '' === $source ) {
			$source = 'unknown';
		}

		$confidence = $data['confidence'] ?? 0.0;

		if ( ! is_int( $confidence ) && ! is_float( $confidence ) ) {
			$confidence = 0.0;
		}

		$confidence = (float) $confidence;

		if ( is_nan( $confidence ) || $confidence < 0.0 || $confidence > 1.0 ) {
			$confidence = 0.0;
		}

		$is_cached = ! empty( $data['is_cached'] );

		return new self( $country_code, $region_code, $source, $confidence, $is_cached );
	}

	/**
	 * The unknown geographic context: no country, no region, zero confidence.
	 *
	 * @return self
	 */
	public static function unknown(): self {
		return new self( null, null, 'unknown', 0.0, false );
	}
}
