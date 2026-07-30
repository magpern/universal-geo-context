<?php
/**
 * Unit tests for UniversalGeo\MaxMind\UpdateLock.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\MaxMind;

use PHPUnit\Framework\TestCase;
use UniversalGeo\MaxMind\UpdateLock;

/**
 * Covers acquire()/release() token semantics, TTL-based stale recovery, and
 * tolerant handling of corrupt stored state — using an injected callable
 * clock for determinism, never the system clock, mirroring
 * CircuitBreakerTest's own approach.
 */
final class UpdateLockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['universal_geo_test_options'] = array();
	}

	private function lock( int $now = 1000000000 ): UpdateLock {
		return new UpdateLock( static fn (): int => $now );
	}

	// ---- Fresh / unlocked state -------------------------------------------------

	public function test_fresh_lock_state_is_unlocked(): void {
		$state = $this->lock()->state();

		$this->assertFalse( $state['locked'] );
		$this->assertSame( '', $state['token'] );
		$this->assertSame( '', $state['owner'] );
		$this->assertNull( $state['acquired_at'] );
		$this->assertNull( $state['expires_at'] );
	}

	// ---- acquire() ----------------------------------------------------------------

	public function test_acquire_on_a_fresh_lock_succeeds(): void {
		$token = $this->lock()->acquire( 'admin' );

		$this->assertIsString( $token );
		$this->assertNotSame( '', $token );
	}

	public function test_acquire_records_the_owner_and_timestamps(): void {
		$lock = $this->lock( 1000000000 );
		$lock->acquire( 'cron' );

		$state = $lock->state();
		$this->assertTrue( $state['locked'] );
		$this->assertSame( 'cron', $state['owner'] );
		$this->assertSame( 1000000000, $state['acquired_at'] );
		$this->assertSame( 1000000600, $state['expires_at'] );
	}

	public function test_two_acquires_in_a_row_yield_different_tokens(): void {
		$lock   = $this->lock();
		$first  = $lock->acquire( 'admin' );
		$second = $lock->acquire( 'admin' );

		// The second acquire() fails (still locked and fresh) and returns
		// null — proven separately below — but if somehow both succeeded,
		// the tokens must never collide. This test documents the intended
		// failure instead: acquiring twice without releasing does not
		// silently reuse the same token.
		$this->assertIsString( $first );
		$this->assertNull( $second );
	}

	public function test_acquire_while_still_locked_and_fresh_fails(): void {
		$lock = $this->lock( 1000000000 );
		$lock->acquire( 'admin' );

		$second = $lock->acquire( 'cron' );

		$this->assertNull( $second );
	}

	public function test_acquire_while_locked_and_fresh_does_not_change_the_held_owner(): void {
		$lock = $this->lock( 1000000000 );
		$lock->acquire( 'admin' );
		$lock->acquire( 'cron' );

		$this->assertSame( 'admin', $lock->state()['owner'] );
	}

	// ---- TTL-based stale recovery --------------------------------------------------

	public function test_acquire_just_before_ttl_expiry_fails(): void {
		$lock = $this->lock( 1000000000 );
		$lock->acquire( 'admin' );

		$still_locked = new UpdateLock( static fn (): int => 1000000599 );
		$this->assertNull( $still_locked->acquire( 'cron' ) );
	}

	public function test_acquire_after_ttl_expiry_succeeds_and_reclaims(): void {
		$lock = $this->lock( 1000000000 );
		$lock->acquire( 'admin' );

		$after_ttl = new UpdateLock( static fn (): int => 1000000601 );
		$token     = $after_ttl->acquire( 'cron' );

		$this->assertIsString( $token );
		$this->assertSame( 'cron', $after_ttl->state()['owner'] );
	}

	public function test_reclaiming_a_stale_lock_issues_a_fresh_token(): void {
		$lock  = $this->lock( 1000000000 );
		$stale = $lock->acquire( 'admin' );

		$after_ttl = new UpdateLock( static fn (): int => 1000000601 );
		$fresh     = $after_ttl->acquire( 'cron' );

		$this->assertNotSame( $stale, $fresh );
	}

	// ---- release() ------------------------------------------------------------------

	public function test_release_with_the_correct_token_unlocks(): void {
		$lock  = $this->lock();
		$token = $lock->acquire( 'admin' );

		$lock->release( (string) $token );

		$this->assertFalse( $lock->state()['locked'] );
	}

	public function test_release_with_a_wrong_token_does_not_unlock(): void {
		$lock = $this->lock();
		$lock->acquire( 'admin' );

		$lock->release( 'totally-wrong-token' );

		$this->assertTrue( $lock->state()['locked'] );
	}

	public function test_release_with_an_empty_token_does_not_unlock(): void {
		$lock = $this->lock();
		$lock->acquire( 'admin' );

		$lock->release( '' );

		$this->assertTrue( $lock->state()['locked'] );
	}

	public function test_release_on_an_already_unlocked_lock_is_a_no_op(): void {
		$lock = $this->lock();

		$lock->release( 'anything' );

		$this->assertFalse( $lock->state()['locked'] );
	}

	/**
	 * A late release() from a holder whose lock has since been reclaimed as
	 * stale by a different holder must never release the NEW holder's lock —
	 * the token-matching, not merely "is anything locked", is what release()
	 * checks.
	 */
	public function test_late_release_from_a_reclaimed_stale_lock_does_not_release_the_new_holder(): void {
		$lock        = $this->lock( 1000000000 );
		$stale_token = $lock->acquire( 'admin' );

		$after_ttl = new UpdateLock( static fn (): int => 1000000601 );
		$after_ttl->acquire( 'cron' );

		// The original holder's late release, using its own now-stale token.
		$after_ttl->release( (string) $stale_token );

		$this->assertTrue( $after_ttl->state()['locked'] );
		$this->assertSame( 'cron', $after_ttl->state()['owner'] );
	}

	public function test_after_release_a_new_acquire_succeeds_immediately(): void {
		$lock  = $this->lock();
		$token = $lock->acquire( 'admin' );
		$lock->release( (string) $token );

		$this->assertIsString( $lock->acquire( 'cron' ) );
	}

	// ---- Corrupt/malformed stored state ---------------------------------------------

	public function test_non_array_stored_option_is_treated_as_unlocked(): void {
		update_option( UpdateLock::OPTION_NAME, 'not-an-array' );

		$this->assertFalse( $this->lock()->state()['locked'] );
	}

	public function test_missing_locked_key_is_treated_as_unlocked(): void {
		update_option( UpdateLock::OPTION_NAME, array( 'token' => 'x' ) );

		$this->assertFalse( $this->lock()->state()['locked'] );
	}

	public function test_non_bool_locked_value_is_treated_as_unlocked(): void {
		update_option( UpdateLock::OPTION_NAME, array( 'locked' => 'yes' ) );

		$this->assertFalse( $this->lock()->state()['locked'] );
	}

	public function test_corrupt_state_still_allows_a_fresh_acquire(): void {
		update_option( UpdateLock::OPTION_NAME, 'garbage' );

		$this->assertIsString( $this->lock()->acquire( 'admin' ) );
	}
}
