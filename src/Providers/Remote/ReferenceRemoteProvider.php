<?php
/**
 * Remote geolocation provider: MaxMind GeoLite2 Country Web Service.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers\Remote;

use RuntimeException;
use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Http\IpUtils;
use UniversalGeo\Model\GeoCandidate;

/**
 * The M4 reference remote provider, filling the previously-empty `'remote'`
 * slot in `ContextResolver::PROVIDER_ORDER` (confidence `0.85`, unchanged —
 * `ContextResolver` itself has no diff for M4). Disabled by default,
 * requiring both a credential pair and the structural transfer
 * acknowledgement (both decided once, by `Plugin::build_graph()`, and
 * handed here as plain scalars — the same "settings-derived values arrive
 * pre-decided" pattern every other settings-derived provider follows).
 *
 * Endpoint is hardcoded — no administrator-configurable URL exists anywhere
 * in this class. Performs at most one outbound attempt per `resolve()` call,
 * no retries, and degrades to a miss (`null`) for every failure mode: a
 * non-public IP, a closed-to-attempts circuit breaker, a transport failure,
 * an unexpected HTTP status, or a malformed response all either return
 * `null` directly or throw — and `ContextResolver`'s existing
 * `catch(Throwable)` boundary already treats a thrown provider exactly like
 * a miss, continuing the chain.
 *
 * Responsibility split with `HttpTransport` (frozen, M4): this class builds
 * the request URL, adds HTTP Basic authentication, classifies the response
 * status code, and parses the JSON body. The transport executes the
 * request and converts the raw result into a `TransportResponse` or a
 * scrubbed `TransportException` — nothing more.
 *
 * @internal
 * @final
 */
final class ReferenceRemoteProvider implements GeoProviderInterface {

	/**
	 * The hardcoded MaxMind GeoLite2 Country Web Service endpoint. No
	 * administrator-configurable override exists — appending the already
	 * IP-shaped, already-validated $ip is the only variable part of the
	 * request URL.
	 */
	private const ENDPOINT = 'https://geolite.info/geoip/v2.1/country/';

	/**
	 * The endpoint's host only — public for `DiagnosticsService`'s own
	 * "endpoint_host" transparency/firewall-operability field (M4 frozen
	 * decision: this field is deliberately kept, unlike the account id and
	 * license key, since a host name is server configuration an admin needs
	 * to allowlist outbound, never personal data).
	 */
	public const ENDPOINT_HOST = 'geolite.info';

	/**
	 * The fixed request timeout, in seconds — an internal policy constant,
	 * not an administrator-configurable setting (the M4 report names no
	 * settings key for it); `WordPressHttpTransport` itself additionally
	 * bounds whatever value it is given. Public so `DiagnosticsService` can
	 * surface it without duplicating the value.
	 */
	public const TIMEOUT_SECONDS = 3;

	/**
	 * A healthy "no data for this address" response.
	 */
	private const STATUS_NOT_FOUND = 404;

	/**
	 * A healthy, parseable response.
	 */
	private const STATUS_OK = 200;

	/**
	 * Stores the injected dependencies. Every settings-derived value
	 * (`$enabled`, `$account_id`, `$license_key`) arrives already resolved
	 * by `Plugin::build_graph()` — this class never reads settings, a
	 * constant, or `get_option()` itself.
	 *
	 * @param bool           $enabled       The fully-resolved "is the remote provider on" decision (`remote_enabled`, itself already forced false by `Settings::sanitize()` unless the transfer acknowledgement was also true).
	 * @param string         $account_id    The effective account id, or '' when none is configured.
	 * @param string         $license_key   The effective license key, or '' when none is configured.
	 * @param HttpTransport  $http_transport Performs the outbound request.
	 * @param CircuitBreaker $circuit_breaker Gates whether an attempt may be made at all.
	 */
	public function __construct(
		private readonly bool $enabled,
		private readonly string $account_id,
		private readonly string $license_key,
		private readonly HttpTransport $http_transport,
		private readonly CircuitBreaker $circuit_breaker
	) {
	}

	/**
	 * The stable provider id used for attribution and the confidence table.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'remote';
	}

	/**
	 * Available only when explicitly enabled and both credentials are
	 * present — configuration readiness only, never the live circuit
	 * breaker state (that gate lives inside resolve() itself, so a
	 * diagnostics probe() row can distinguish "misconfigured" from
	 * "temporarily circuit-open" instead of reporting both identically as
	 * unavailable).
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->enabled && '' !== $this->account_id && '' !== $this->license_key;
	}

	/**
	 * Looks up $ip's country via the remote web service.
	 *
	 * @param string $ip The already-resolved, already-normalized client IP.
	 *
	 * @return GeoCandidate|null
	 *
	 * @throws TransportException When the transport itself fails — propagates to ContextResolver's existing catch, never swallowed here.
	 * @throws RuntimeException When the response has an unexpected HTTP status or a malformed body.
	 */
	public function resolve( string $ip ): ?GeoCandidate {
		// Defense in depth, mirroring MaxMindProvider's own precedent: even
		// if resolve() is ever called without the resolver loop's own
		// is_available() check having gated it first, a disabled or
		// credential-incomplete instance must still make zero outbound
		// attempts, never merely rely on the caller having checked first.
		if ( ! $this->is_available() ) {
			return null;
		}

		if ( ! IpUtils::is_public( $ip ) ) {
			return null;
		}

		if ( ! $this->circuit_breaker->may_attempt() ) {
			// A skipped attempt is not itself a new failure — the breaker
			// already recorded the state that caused this skip.
			return null;
		}

		$headers = array(
			// Not obfuscation: HTTP Basic authentication's own wire format,
			// per RFC 7617 — the same justification GeoCache::salt() already
			// documents for its own base64_encode() call.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'Authorization' => 'Basic ' . base64_encode( $this->account_id . ':' . $this->license_key ),
		);

		try {
			$response = $this->http_transport->get( self::ENDPOINT . $ip, $headers, self::TIMEOUT_SECONDS );
		} catch ( TransportException $e ) {
			$this->circuit_breaker->report_failure();
			throw $e;
		}

		if ( self::STATUS_NOT_FOUND === $response->status_code ) {
			// A healthy miss: the service is reachable and answered
			// correctly — it simply has no data for this address.
			$this->circuit_breaker->report_success();
			return null;
		}

		if ( self::STATUS_OK !== $response->status_code ) {
			$this->circuit_breaker->report_failure();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an HTTP status code is a small int, never user-facing output, never containing the URL, credentials, or response body.
			throw new RuntimeException( sprintf( 'Remote provider returned an unexpected HTTP status: %d.', $response->status_code ) );
		}

		$country = $this->parsed_country_code( $response->body );

		if ( null === $country ) {
			$this->circuit_breaker->report_failure();
			throw new RuntimeException( 'Remote provider returned a malformed response.' );
		}

		$this->circuit_breaker->report_success();

		return new GeoCandidate( $country, null );
	}

	/**
	 * Parses a successful response body's country.iso_code, tolerantly:
	 * malformed JSON, a non-array shape, or a missing/non-string field all
	 * return null (a malformed-response failure, handled by the caller) —
	 * never a raw JSON-decode warning, never a raw body echoed anywhere.
	 *
	 * @param string $body The raw response body.
	 *
	 * @return string|null
	 */
	private function parsed_country_code( string $body ): ?string {
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['country']['iso_code'] ) || ! is_string( $decoded['country']['iso_code'] ) ) {
			return null;
		}

		return $decoded['country']['iso_code'];
	}
}
