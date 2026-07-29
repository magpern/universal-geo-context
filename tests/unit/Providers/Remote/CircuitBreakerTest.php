<?php
/**
 * Unit tests for UniversalGeo\Providers\Remote\CircuitBreaker.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Providers\Remote;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Providers\Remote\CircuitBreaker;

/**
 * Covers the full closed/open/half_open state machine, the fixed policy
 * (FAILURE_THRESHOLD = 3, COOLDOWN_SECONDS = 300), the "no write on a
 * healthy success" invariant, and tolerant handling of corrupt stored
 * state — using an injected callable clock for determinism, never the
 * system clock.
 */
final class CircuitBreakerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['universal_geo_test_options'] = array();
	}

	private function breaker( int $now = 1000000000 ): CircuitBreaker {
		return new CircuitBreaker( static fn (): int => $now );
	}

	// ---- Fresh / closed state ---------------------------------------------------

	public function test_fresh_circuit_permits_attempts(): void {
		$this->assertTrue( $this->breaker()->may_attempt() );
	}

	public function test_fresh_circuit_state_is_closed_with_zero_failures(): void {
		$state = $this->breaker()->state();

		$this->assertSame( 'closed', $state['state'] );
		$this->assertSame( 0, $state['failure_count'] );
		$this->assertNull( $state['opened_at'] );
	}

	public function test_healthy_closed_state_success_performs_no_option_write(): void {
		$this->breaker()->report_success();

		$this->assertFalse( get_option( CircuitBreaker::OPTION_NAME, false ) );
	}

	// ---- Failure accumulation and opening ---------------------------------------

	public function test_one_failure_keeps_the_circuit_closed(): void {
		$breaker = $this->breaker();
		$breaker->report_failure();

		$state = $breaker->state();
		$this->assertSame( 'closed', $state['state'] );
		$this->assertSame( 1, $state['failure_count'] );
		$this->assertTrue( $breaker->may_attempt() );
	}

	public function test_two_failures_keep_the_circuit_closed(): void {
		$breaker = $this->breaker();
		$breaker->report_failure();
		$breaker->report_failure();

		$this->assertSame( 'closed', $breaker->state()['state'] );
		$this->assertTrue( $breaker->may_attempt() );
	}

	public function test_three_consecutive_failures_open_the_circuit(): void {
		$breaker = $this->breaker();
		$breaker->report_failure();
		$breaker->report_failure();
		$breaker->report_failure();

		$state = $breaker->state();
		$this->assertSame( 'open', $state['state'] );
		$this->assertSame( 3, $state['failure_count'] );
		$this->assertFalse( $breaker->may_attempt() );
	}

	public function test_a_success_between_failures_resets_the_streak(): void {
		$breaker = $this->breaker();
		$breaker->report_failure();
		$breaker->report_failure();
		$breaker->report_success();
		$breaker->report_failure();
		$breaker->report_failure();

		// Two failures since the reset — still below threshold, still closed.
		$this->assertSame( 'closed', $breaker->state()['state'] );
	}

	public function test_open_circuit_blocks_attempts_before_cooldown_elapses(): void {
		$breaker = $this->breaker( 1000000000 );
		$breaker->report_failure();
		$breaker->report_failure();
		$breaker->report_failure();

		$still_within_cooldown = new CircuitBreaker( static fn (): int => 1000000000 + 299 );
		$this->assertFalse( $still_within_cooldown->may_attempt() );
	}

	// ---- Cooldown expiry and the half-open trial --------------------------------

	public function test_open_circuit_permits_exactly_one_attempt_after_cooldown_elapses(): void {
		$breaker = $this->breaker( 1000000000 );
		$breaker->report_failure();
		$breaker->report_failure();
		$breaker->report_failure();

		$after_cooldown = new CircuitBreaker( static fn (): int => 1000000000 + 300 );
		$this->assertTrue( $after_cooldown->may_attempt() );
	}

	public function test_circuit_transitions_to_half_open_once_cooldown_elapses(): void {
		$breaker = $this->breaker( 1000000000 );
		$breaker->report_failure();
		$breaker->report_failure();
		$breaker->report_failure();

		$after_cooldown = new CircuitBreaker( static fn (): int => 1000000000 + 300 );
		$after_cooldown->may_attempt();

		$this->assertSame( 'half_open', $after_cooldown->state()['state'] );
	}

	public function test_half_open_state_denies_a_second_concurrent_attempt(): void {
		$breaker = $this->breaker( 1000000000 );
		$breaker->report_failure();
		$breaker->report_failure();
		$breaker->report_failure();

		$after_cooldown = new CircuitBreaker( static fn (): int => 1000000000 + 300 );
		$after_cooldown->may_attempt(); // First trial: permitted, transitions to half_open.

		$second_caller = new CircuitBreaker( static fn (): int => 1000000000 + 300 );
		$this->assertFalse( $second_caller->may_attempt() );
	}

	public function test_half_open_success_closes_and_resets_the_circuit(): void {
		$breaker = $this->breaker( 1000000000 );
		$breaker->report_failure();
		$breaker->report_failure();
		$breaker->report_failure();

		$after_cooldown = new CircuitBreaker( static fn (): int => 1000000000 + 300 );
		$after_cooldown->may_attempt();
		$after_cooldown->report_success();

		$state = $after_cooldown->state();
		$this->assertSame( 'closed', $state['state'] );
		$this->assertSame( 0, $state['failure_count'] );
		$this->assertTrue( $after_cooldown->may_attempt() );
	}

	public function test_half_open_failure_reopens_with_a_fresh_cooldown(): void {
		$breaker = $this->breaker( 1000000000 );
		$breaker->report_failure();
		$breaker->report_failure();
		$breaker->report_failure();

		$after_cooldown = new CircuitBreaker( static fn (): int => 1000000000 + 300 );
		$after_cooldown->may_attempt();
		$after_cooldown->report_failure();

		$state = $after_cooldown->state();
		$this->assertSame( 'open', $state['state'] );
		$this->assertSame( 1000000000 + 300, $state['opened_at'] );

		// Still blocked immediately after reopening.
		$immediately_after = new CircuitBreaker( static fn (): int => 1000000000 + 300 );
		$this->assertFalse( $immediately_after->may_attempt() );

		// And permits exactly one more trial only after the *new* cooldown.
		$after_new_cooldown = new CircuitBreaker( static fn (): int => 1000000000 + 600 );
		$this->assertTrue( $after_new_cooldown->may_attempt() );
	}

	// ---- Tolerant handling of corrupt stored state ------------------------------

	public function test_unrecognized_state_string_is_treated_as_fresh(): void {
		update_option(
			CircuitBreaker::OPTION_NAME,
			array(
				'state'         => 'not-a-real-state',
				'failure_count' => 99,
				'opened_at'     => 123,
			)
		);

		$this->assertSame( 'closed', $this->breaker()->state()['state'] );
		$this->assertTrue( $this->breaker()->may_attempt() );
	}

	public function test_non_array_stored_option_is_treated_as_fresh(): void {
		update_option( CircuitBreaker::OPTION_NAME, 'not-an-array' );

		$this->assertSame( 'closed', $this->breaker()->state()['state'] );
	}

	public function test_non_int_failure_count_is_treated_as_zero(): void {
		update_option(
			CircuitBreaker::OPTION_NAME,
			array(
				'state'         => 'closed',
				'failure_count' => 'not-a-number',
				'opened_at'     => null,
			)
		);

		$this->assertSame( 0, $this->breaker()->state()['failure_count'] );
	}

	public function test_non_int_opened_at_on_an_open_state_is_treated_as_null(): void {
		update_option(
			CircuitBreaker::OPTION_NAME,
			array(
				'state'         => 'open',
				'failure_count' => 3,
				'opened_at'     => 'not-a-timestamp',
			)
		);

		// A null opened_at on an 'open' circuit can never satisfy the cooldown
		// comparison — must degrade to "still blocked", not throw or warn.
		$this->assertFalse( $this->breaker()->may_attempt() );
	}

	public function test_missing_option_is_treated_as_fresh(): void {
		$this->assertSame( 'closed', $this->breaker()->state()['state'] );
	}
}
