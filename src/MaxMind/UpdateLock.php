<?php
/**
 * Cooperative lock guarding concurrent managed-database update attempts.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\MaxMind;

use Exception;

/**
 * A single, option-backed lock shared by `MaxMind\DatabaseManager`'s three
 * action methods (download, remove, restore) — admin, cron, and WP-CLI all
 * contend for the same one lock. The same ownership shape
 * `Providers\Remote\CircuitBreaker` already established: a dedicated class
 * owning one small option, token-based, tolerant of a missing/corrupt
 * stored value.
 *
 * Concurrency is explicitly approximate here too — the real safety net
 * against a corrupted install is `DatabaseManager`'s atomic-rename install
 * algorithm, not lock perfection (the same philosophy `CircuitBreaker`'s own
 * docblock already documents for its own option). This lock exists to make
 * the *common* case (two triggers firing close together) skip cleanly rather
 * than both proceeding, not to provide a hard mutual-exclusion guarantee
 * under adversarial timing.
 *
 * TTL-based stale recovery: a lock older than `TTL_SECONDS` is treated as
 * abandoned (e.g. a crashed or killed process that never called `release()`)
 * and a fresh `acquire()` call is allowed to reclaim it — the same
 * "recovery over deadlock" preference `CircuitBreaker`'s cooldown-based
 * `open` → `half_open` transition already embodies.
 *
 * @internal
 * @final
 */
final class UpdateLock {

	/**
	 * The option this class exclusively owns for writing.
	 */
	public const OPTION_NAME = 'universal_geo_maxmind_update_lock';

	/**
	 * Seconds after which a held lock is considered stale and reclaimable,
	 * regardless of whether it was ever released — bounds how long a
	 * crashed/killed holder can block every future attempt.
	 */
	private const TTL_SECONDS = 600;

	/**
	 * The default, fresh (unlocked) state.
	 *
	 * @var array{locked: bool, token: string, owner: string, acquired_at: int|null, expires_at: int|null}
	 */
	private const DEFAULT_STATE = array(
		'locked'      => false,
		'token'       => '',
		'owner'       => '',
		'acquired_at' => null,
		'expires_at'  => null,
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
	 * Attempts to acquire the lock.
	 *
	 * Succeeds when the lock is currently free, or held but past its TTL
	 * (stale). Fails when another, still-fresh holder has it. A successful
	 * acquisition always issues a brand-new random token, even when
	 * reclaiming a stale lock — a late `release()` call from the original,
	 * stale holder must not be able to release the new holder's lock.
	 *
	 * @param string $owner A short, human-readable label for who is acquiring the lock ('admin', 'cron', or 'cli') — diagnostics only, never used for matching.
	 *
	 * @return string|null The fresh token on success, or null when another fresh lock is already held.
	 */
	public function acquire( string $owner ): ?string {
		$state = $this->read();
		$now   = ( $this->clock )();

		if ( $state['locked'] && null !== $state['expires_at'] && $now < $state['expires_at'] ) {
			return null;
		}

		$token = $this->generated_token();

		$this->persist(
			array(
				'locked'      => true,
				'token'       => $token,
				'owner'       => $owner,
				'acquired_at' => $now,
				'expires_at'  => $now + self::TTL_SECONDS,
			)
		);

		return $token;
	}

	/**
	 * Releases the lock, only when $token matches the currently held one —
	 * a caller that lost a race (its acquire() returned null) or whose lock
	 * has since been reclaimed as stale by someone else can never release a
	 * lock it does not actually hold.
	 *
	 * @param string $token The token returned by this instance's own successful acquire() call.
	 *
	 * @return void
	 */
	public function release( string $token ): void {
		$state = $this->read();

		if ( ! $state['locked'] || '' === $token || ! hash_equals( $state['token'], $token ) ) {
			return;
		}

		$this->persist( self::DEFAULT_STATE );
	}

	/**
	 * The current, normalized state — for diagnostics only; never consulted
	 * by acquire()/release()'s own logic (which call the private read()
	 * directly).
	 *
	 * @return array{locked: bool, token: string, owner: string, acquired_at: int|null, expires_at: int|null}
	 */
	public function state(): array {
		return $this->read();
	}

	/**
	 * Generates a fresh, unpredictable lock token.
	 *
	 * @return string
	 */
	private function generated_token(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			// random_bytes() failing entirely (no CSPRNG source available) is
			// an extraordinary environment failure; uniqid() with more
			// entropy is a safe enough fallback for a cooperative,
			// approximate lock that is never a security boundary.
			return uniqid( '', true );
		}
	}

	/**
	 * Reads and normalizes the persisted state, tolerating a missing or
	 * corrupt option: malformed shapes fall back to the default (unlocked)
	 * state, never throwing.
	 *
	 * @return array{locked: bool, token: string, owner: string, acquired_at: int|null, expires_at: int|null}
	 */
	private function read(): array {
		$stored = get_option( self::OPTION_NAME, false );

		if ( ! is_array( $stored ) ) {
			return self::DEFAULT_STATE;
		}

		$locked = $stored['locked'] ?? null;

		if ( ! is_bool( $locked ) ) {
			return self::DEFAULT_STATE;
		}

		$token = $stored['token'] ?? '';
		$token = is_string( $token ) ? $token : '';

		$owner = $stored['owner'] ?? '';
		$owner = is_string( $owner ) ? $owner : '';

		$acquired_at = $stored['acquired_at'] ?? null;
		$acquired_at = is_int( $acquired_at ) ? $acquired_at : null;

		$expires_at = $stored['expires_at'] ?? null;
		$expires_at = is_int( $expires_at ) ? $expires_at : null;

		return array(
			'locked'      => $locked,
			'token'       => $token,
			'owner'       => $owner,
			'acquired_at' => $acquired_at,
			'expires_at'  => $expires_at,
		);
	}

	/**
	 * Persists a state, creating the option non-autoloaded on first write
	 * (mirroring `CircuitBreaker::persist()`'s identical shape) and updating
	 * it thereafter.
	 *
	 * @param array{locked: bool, token: string, owner: string, acquired_at: int|null, expires_at: int|null} $state The complete state to persist.
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
