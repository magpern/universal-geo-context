<?php
/**
 * Unit tests for UniversalGeo\MaxMind\UpdateScheduler.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\MaxMind;

use PHPUnit\Framework\TestCase;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Tests\Support\FakeHttpTransport;

/**
 * Covers cron registration/reconciliation against the in-memory
 * single-event-per-hook WP-Cron stub (tests/unit/bootstrap.php): scheduling,
 * rescheduling on a frequency change, clearing when disabled, jitter bounds,
 * and idempotent no-op reconciliation.
 */
final class UpdateSchedulerTest extends TestCase {

	private const FIXED_NOW = 1700000000;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['universal_geo_test_options'] = array();
		$GLOBALS['universal_geo_test_cron']    = array();
	}

	private function database_manager(): DatabaseManager {
		return new DatabaseManager(
			sys_get_temp_dir() . '/ugeo-scheduler-test-unused',
			'',
			'',
			true,
			new FakeHttpTransport(),
			new ArchiveExtractor(),
			new UpdateLock( static fn (): int => self::FIXED_NOW )
		);
	}

	private function scheduler( bool $enabled, string $frequency = 'weekly' ): UpdateScheduler {
		return new UpdateScheduler(
			$this->database_manager(),
			$enabled,
			$frequency,
			static fn (): int => self::FIXED_NOW
		);
	}

	// ---- ensure_scheduled(): enabling --------------------------------------------

	public function test_enabled_with_nothing_scheduled_schedules_a_weekly_event(): void {
		$this->scheduler( true, 'weekly' )->ensure_scheduled();

		$event = wp_get_scheduled_event( UpdateScheduler::CRON_HOOK );

		$this->assertNotFalse( $event );
		$this->assertSame( 'universal_geo_weekly', $event->schedule );
	}

	public function test_enabled_with_nothing_scheduled_schedules_a_twice_weekly_event(): void {
		$this->scheduler( true, 'twice_weekly' )->ensure_scheduled();

		$event = wp_get_scheduled_event( UpdateScheduler::CRON_HOOK );

		$this->assertSame( 'universal_geo_twice_weekly', $event->schedule );
	}

	public function test_the_first_run_offset_is_never_before_now(): void {
		$this->scheduler( true, 'weekly' )->ensure_scheduled();

		$next = wp_next_scheduled( UpdateScheduler::CRON_HOOK );

		$this->assertGreaterThanOrEqual( self::FIXED_NOW, $next );
	}

	public function test_the_first_run_offset_is_within_the_jitter_window(): void {
		$this->scheduler( true, 'weekly' )->ensure_scheduled();

		$next   = wp_next_scheduled( UpdateScheduler::CRON_HOOK );
		$offset = $next - self::FIXED_NOW;

		// ±10% of 7 days.
		$this->assertLessThanOrEqual( (int) ( 7 * 86400 * 0.10 ), $offset );
	}

	// ---- ensure_scheduled(): idempotence -----------------------------------------

	public function test_calling_ensure_scheduled_twice_does_not_change_an_already_correct_schedule(): void {
		$scheduler = $this->scheduler( true, 'weekly' );
		$scheduler->ensure_scheduled();

		$first_next = wp_next_scheduled( UpdateScheduler::CRON_HOOK );

		$scheduler->ensure_scheduled();

		$this->assertSame( $first_next, wp_next_scheduled( UpdateScheduler::CRON_HOOK ) );
	}

	// ---- ensure_scheduled(): frequency change reschedules ------------------------

	public function test_a_frequency_change_reschedules_the_event(): void {
		$this->scheduler( true, 'weekly' )->ensure_scheduled();
		$this->assertSame( 'universal_geo_weekly', wp_get_scheduled_event( UpdateScheduler::CRON_HOOK )->schedule );

		$this->scheduler( true, 'twice_weekly' )->ensure_scheduled();

		$this->assertSame( 'universal_geo_twice_weekly', wp_get_scheduled_event( UpdateScheduler::CRON_HOOK )->schedule );
	}

	// ---- ensure_scheduled(): disabling clears -------------------------------------

	public function test_disabled_with_an_event_scheduled_clears_it(): void {
		$this->scheduler( true, 'weekly' )->ensure_scheduled();
		$this->assertNotFalse( wp_next_scheduled( UpdateScheduler::CRON_HOOK ) );

		$this->scheduler( false )->ensure_scheduled();

		$this->assertFalse( wp_next_scheduled( UpdateScheduler::CRON_HOOK ) );
	}

	public function test_disabled_with_nothing_scheduled_is_a_no_op(): void {
		$this->scheduler( false )->ensure_scheduled();

		$this->assertFalse( wp_next_scheduled( UpdateScheduler::CRON_HOOK ) );
	}

	// ---- filter_cron_schedules() ---------------------------------------------------

	public function test_filter_cron_schedules_adds_both_custom_intervals(): void {
		$schedules = $this->scheduler( true )->filter_cron_schedules( array() );

		$this->assertArrayHasKey( 'universal_geo_weekly', $schedules );
		$this->assertArrayHasKey( 'universal_geo_twice_weekly', $schedules );
		$this->assertSame( 7 * 86400, $schedules['universal_geo_weekly']['interval'] );
	}

	public function test_filter_cron_schedules_preserves_existing_entries(): void {
		$schedules = $this->scheduler( true )->filter_cron_schedules(
			array(
				'hourly' => array(
					'interval' => 3600,
					'display'  => 'Once Hourly',
				),
			)
		);

		$this->assertArrayHasKey( 'hourly', $schedules );
	}

	// ---- register() ------------------------------------------------------------------

	public function test_register_hooks_the_cron_action(): void {
		$this->scheduler( true )->register();

		$this->assertArrayHasKey( UpdateScheduler::CRON_HOOK, $GLOBALS['universal_geo_test_actions'] );
	}

	public function test_register_hooks_the_cron_schedules_filter(): void {
		$this->scheduler( true )->register();

		$this->assertArrayHasKey( 'cron_schedules', $GLOBALS['universal_geo_test_filters'] );
	}

	public function test_register_schedules_the_event_when_enabled(): void {
		$this->scheduler( true )->register();

		$this->assertNotFalse( wp_next_scheduled( UpdateScheduler::CRON_HOOK ) );
	}

	public function test_register_does_not_schedule_when_disabled(): void {
		$this->scheduler( false )->register();

		$this->assertFalse( wp_next_scheduled( UpdateScheduler::CRON_HOOK ) );
	}

	// ---- uninstall() -----------------------------------------------------------------

	public function test_uninstall_clears_the_scheduled_event(): void {
		$this->scheduler( true )->ensure_scheduled();
		$this->assertNotFalse( wp_next_scheduled( UpdateScheduler::CRON_HOOK ) );

		UpdateScheduler::uninstall();

		$this->assertFalse( wp_next_scheduled( UpdateScheduler::CRON_HOOK ) );
	}
}
