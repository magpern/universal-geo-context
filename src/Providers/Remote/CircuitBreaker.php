<?php
/**
 * Circuit breaker guarding outbound attempts to the remote provider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers\Remote;

/**
 * A dedicated, internal state machine (`closed` → `open` → `half_open` →
 * `closed`/`open`) guarding every attempt `ReferenceRemoteProvider` makes,
 * so a failing or unreachable remote endpoint degrades to "skip this
 * provider" rather than "retry every request forever" (M4 frozen policy:
 * `FAILURE_THRESHOLD = 3`, `COOLDOWN_SECONDS = 300`).
 *
 * Persists to the single, non-autoloaded `universal_geo_remote_circuit`
 * option — `CircuitBreaker` is its sole runtime writer (mirrors
 * `ProviderHealthStore`'s own option-ownership shape); `Settings::uninstall()`
 * owns deleting it (the frozen M4 ownership split, `Settings.php`'s own
 * docblock). No retries, no latency metrics, no `Retry-After` handling —
 * explicitly out of scope.
 *
 * The clock is an injected callable (`fn(): int`, defaulting to `time()`),
 * never a `Clock` interface (frozen decision) — the same "callable, not an
 * interface" shape `ContextResolver`'s own `on_provider_failed` callback
 * already uses, kept for deterministic unit tests.
 *
 * Concurrency is approximate by design (frozen decision): under concurrent
 * requests, the worst race outcome is one extra attempt (e.g. two requests
 * both observing an expired cooldown and both transitioning `open` to
 * `half_open`) — never an unbounded retry storm. This mirrors
 * `ProviderHealthStore`'s own "approximate counters, never write-per-request"
 * philosophy.
 *
 * @internal
 * @final
 */
final class CircuitBreaker {

	/**
	 * The option this class exclusively owns for writing.
	 */
	public const OPTION_NAME = 'universal_geo_remote_circuit';

	/**
	 * Consecutive failures required to open the circuit from closed.
	 */
	private const FAILURE_THRESHOLD = 3;

	/**
	 * Seconds an open circuit blocks attempts before permitting one
	 * half-open trial.
	 */
	private const COOLDOWN_SECONDS = 300;

	/**
	 * The default, fresh state — closed, no recorded failures.
	 *
	 * @var array{state: string, failure_count: int, opened_at: int|null}
	 */
	private const DEFAULT_STATE = array(
		'state'         => 'closed',
		'failure_count' => 0,
		'opened_at'     => null,
	);

	/**
	 * The injected clock.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Stores the injected clock, defaulting to the system clock.
	 *
	 * @param callable(): int|null $clock Returns the current Unix timestamp. Defaults to `static fn(): int => time()`.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Whether an attempt may currently be made.
	 *
	 * `closed` always permits. `open` permits only once the cooldown has
	 * elapsed, at which point this call itself performs the `open` →
	 * `half_open` transition and returns true for that one caller — the
	 * "exactly one half-open trial" frozen policy. `half_open` itself
	 * always denies further attempts (a trial is already outstanding) until
	 * `report_success()`/`report_failure()` resolves it.
	 *
	 * @return bool
	 */
	public function may_attempt(): bool {
		$state = $this->read();

		if ( 'closed' === $state['state'] ) {
			return true;
		}

		if ( 'half_open' === $state['state'] ) {
			return false;
		}

		// 'open'.
		$now = ( $this->clock )();

		if ( null === $state['opened_at'] || $now < $state['opened_at'] + self::COOLDOWN_SECONDS ) {
			return false;
		}

		$this->persist(
			array(
				'state'         => 'half_open',
				'failure_count' => $state['failure_count'],
				'opened_at'     => $state['opened_at'],
			)
		);

		return true;
	}

	/**
	 * Records a successful attempt. A `half_open` success closes and resets
	 * the circuit; a `closed` success resets any accumulated (but
	 * sub-threshold) failure streak. Performs no option write at all when
	 * the circuit is already closed with zero recorded failures — a
	 * healthy, steady-state success must not turn into a write on every
	 * anonymous request.
	 *
	 * @return void
	 */
	public function report_success(): void {
		$state = $this->read();

		if ( 'closed' === $state['state'] && 0 === $state['failure_count'] ) {
			return;
		}

		$this->persist( self::DEFAULT_STATE );
	}

	/**
	 * Records a failed attempt. A `half_open` failure reopens the circuit
	 * with a fresh cooldown, regardless of the failure count it carried in.
	 * A `closed` failure increments the streak, opening the circuit once it
	 * reaches `FAILURE_THRESHOLD`.
	 *
	 * @return void
	 */
	public function report_failure(): void {
		$state = $this->read();

		if ( 'half_open' === $state['state'] ) {
			$this->persist(
				array(
					'state'         => 'open',
					'failure_count' => self::FAILURE_THRESHOLD,
					'opened_at'     => ( $this->clock )(),
				)
			);

			return;
		}

		$failure_count = $state['failure_count'] + 1;

		if ( $failure_count >= self::FAILURE_THRESHOLD ) {
			$this->persist(
				array(
					'state'         => 'open',
					'failure_count' => $failure_count,
					'opened_at'     => ( $this->clock )(),
				)
			);

			return;
		}

		$this->persist(
			array(
				'state'         => 'closed',
				'failure_count' => $failure_count,
				'opened_at'     => null,
			)
		);
	}

	/**
	 * The current, normalized state — for diagnostics and the Site Health
	 * test only; never consulted by `may_attempt()`'s own logic (which calls
	 * the private read() below directly, avoiding a public/private split
	 * that would otherwise do the same normalization twice).
	 *
	 * @return array{state: string, failure_count: int, opened_at: int|null}
	 */
	public function state(): array {
		return $this->read();
	}

	/**
	 * Reads and normalizes the persisted state, tolerating a missing or
	 * corrupt option: an unrecognized state string, a non-int failure
	 * count, or a non-int/non-null opened_at all fall back to the default
	 * fresh (closed) state, never throwing.
	 *
	 * @return array{state: string, failure_count: int, opened_at: int|null}
	 */
	private function read(): array {
		$stored = get_option( self::OPTION_NAME, false );

		if ( ! is_array( $stored ) ) {
			return self::DEFAULT_STATE;
		}

		$state = $stored['state'] ?? null;

		if ( ! in_array( $state, array( 'closed', 'open', 'half_open' ), true ) ) {
			return self::DEFAULT_STATE;
		}

		$failure_count = $stored['failure_count'] ?? null;
		$failure_count = is_int( $failure_count ) && $failure_count >= 0 ? $failure_count : 0;

		$opened_at = $stored['opened_at'] ?? null;
		$opened_at = is_int( $opened_at ) ? $opened_at : null;

		return array(
			'state'         => $state,
			'failure_count' => $failure_count,
			'opened_at'     => $opened_at,
		);
	}

	/**
	 * Persists a state, creating the option non-autoloaded on first write
	 * (mirroring `ProviderHealthStore::persist()`'s identical shape) and
	 * updating it thereafter.
	 *
	 * @param array{state: string, failure_count: int, opened_at: int|null} $state The complete state to persist.
	 *
	 * @return void
	 */
	private function persist( array $state ): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, $state, '', false );
			return;
		}

		update_option( self::OPTION_NAME, $state );
	}
}
