<?php
/**
 * Immutable snapshot of $_SERVER — the plugin's one trust-boundary file.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Http;

/**
 * The only file in `src/` that reads a client-supplied forwarding header
 * (Revision 3 §2's `TrustBoundaryGuardTest` layering rule, M2). Captures
 * `$_SERVER` once, at `plugins_loaded` 10 (Plugin::init()), as a plain,
 * immutable value: no normalization, no trust judgement, no validation
 * beyond typing — those are `ClientIpResolver`'s job. `RemoteAddrOnlyResolver`
 * (M1), the only other direct `$_SERVER['REMOTE_ADDR']` reader in `src/`, is
 * deleted in this same sub-step, so this class becomes the sole one.
 *
 * @internal
 * @final
 */
final class ServerRequest {

	/**
	 * Every forwarding/CDN header name this plugin ever recognizes, in any
	 * capacity — trusted use (CF-Connecting-IP, X-Forwarded-For, X-Real-IP,
	 * per Revision 3 §6 step 4) or name-only diagnostics presence reporting
	 * (Forwarded, True-Client-IP, Client-IP — never read for trust, per §7).
	 */
	private const FORWARDING_HEADERS = array(
		'CF-Connecting-IP',
		'X-Forwarded-For',
		'X-Real-IP',
		'Forwarded',
		'True-Client-IP',
		'Client-IP',
	);

	/**
	 * Stores the captured snapshot verbatim.
	 *
	 * @param array<string, mixed> $server A $_SERVER-shaped array.
	 */
	private function __construct(
		private readonly array $server
	) {
	}

	/**
	 * Captures a snapshot from a $_SERVER-shaped array.
	 *
	 * @param array<string, mixed> $server Typically the real $_SERVER superglobal.
	 *
	 * @return self
	 */
	public static function capture( array $server ): self {
		return new self( $server );
	}

	/**
	 * Reads one forwarding header by its canonical name (e.g.
	 * 'X-Forwarded-For'), case-insensitively mapped to $_SERVER's
	 * `HTTP_X_FORWARDED_FOR` form. Returns null when absent, non-string, or
	 * empty/whitespace-only — never a fabricated value.
	 *
	 * @param string $name Canonical header name, any case, hyphen-separated.
	 *
	 * @return string|null
	 */
	public function header( string $name ): ?string {
		$key   = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		$value = $this->server[ $key ] ?? null;

		return ( is_string( $value ) && '' !== trim( $value ) ) ? $value : null;
	}

	/**
	 * The raw REMOTE_ADDR value, exactly as captured. Not normalized or
	 * validated — ClientIpResolver's job, via IpUtils.
	 *
	 * @return string|null
	 */
	public function remote_addr(): ?string {
		$value = $this->server['REMOTE_ADDR'] ?? null;

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Names only (never values) of every recognized forwarding header
	 * actually present in this snapshot, in FORWARDING_HEADERS' fixed order —
	 * for diagnostics ("which forwarding headers arrived"), never for trust
	 * decisions.
	 *
	 * @return string[]
	 */
	public function present_forwarding_headers(): array {
		$present = array();

		foreach ( self::FORWARDING_HEADERS as $name ) {
			if ( null !== $this->header( $name ) ) {
				$present[] = $name;
			}
		}

		return $present;
	}

	/**
	 * Names of every field (REMOTE_ADDR plus each recognized forwarding
	 * header) whose value differs between this snapshot and $live — e.g. an
	 * mu-plugin or hosting shim rewriting REMOTE_ADDR after this snapshot was
	 * taken. Values themselves never appear in the result, only field names.
	 *
	 * @param self $live A fresh capture to compare against.
	 *
	 * @return string[]
	 */
	public function drift( self $live ): array {
		$changed = array();

		if ( $this->remote_addr() !== $live->remote_addr() ) {
			$changed[] = 'REMOTE_ADDR';
		}

		foreach ( self::FORWARDING_HEADERS as $name ) {
			if ( $this->header( $name ) !== $live->header( $name ) ) {
				$changed[] = $name;
			}
		}

		return $changed;
	}
}
