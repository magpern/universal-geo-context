<?php
/**
 * WP-Cron registration and reconciliation for managed GeoLite2 database updates.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\MaxMind;

/**
 * Registers the two custom `cron_schedules` intervals GeoLite2 Country's
 * real publication rhythm calls for (`weekly`, `twice_weekly` — no `daily`,
 * since MaxMind publishes at most twice a week and a daily check would just
 * poll for no new data most of the time), and reconciles the single
 * `universal_geo_maxmind_update` cron event against the configured
 * enabled/frequency settings.
 *
 * `register()` is called unconditionally from `Plugin::init()` — cron
 * requests are neither `is_admin()` nor WP-CLI, so this cannot live inside
 * either existing gated construction branch. `ensure_scheduled()` is
 * self-healing reconciliation: safe to call on every admin page load and
 * again explicitly from `AdminScreen::handle_save_settings()`, since it only
 * ever schedules/reschedules/clears based on the current configured state,
 * never accumulating duplicate events.
 *
 * @internal
 * @final
 */
final class UpdateScheduler {

	/**
	 * The single cron hook this class owns.
	 */
	public const CRON_HOOK = 'universal_geo_maxmind_update';

	/**
	 * Custom `cron_schedules` interval name for the weekly cadence.
	 */
	private const SCHEDULE_WEEKLY = 'universal_geo_weekly';

	/**
	 * Custom `cron_schedules` interval name for the twice-weekly cadence.
	 * WP-Cron has no native "specific weekdays" primitive, so this
	 * approximates MaxMind's real Tue/Fri rhythm as a fixed-interval
	 * recurrence rather than pinning exact weekdays.
	 */
	private const SCHEDULE_TWICE_WEEKLY = 'universal_geo_twice_weekly';

	/**
	 * Seconds between weekly runs.
	 */
	private const WEEKLY_SECONDS = 7 * DAY_IN_SECONDS;

	/**
	 * Seconds between twice-weekly runs (~3.5 days).
	 */
	private const TWICE_WEEKLY_SECONDS = 3 * DAY_IN_SECONDS + 43200;

	/**
	 * The jitter window, as a fraction of the configured interval, applied
	 * only to a brand-new first-run offset — avoids a synchronized
	 * thundering herd across many installs whose admins all activate this
	 * feature around the same moment.
	 */
	private const JITTER_FRACTION = 0.10;

	/**
	 * The injected clock.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Stores the injected dependencies. $enabled and $frequency are
	 * settings-derived values Plugin::build_graph() has already resolved —
	 * this class never reads settings itself.
	 *
	 * @param DatabaseManager      $database_manager The manager run() delegates to.
	 * @param bool                 $enabled          Whether auto-update is enabled (maxmind_managed_auto_update_enabled, already AND-gated against maxmind_managed_enabled by Settings::sanitize()).
	 * @param string               $frequency        'weekly' or 'twice_weekly'.
	 * @param callable(): int|null $clock            Returns the current Unix timestamp. Defaults to `static fn(): int => time()`.
	 */
	public function __construct(
		private readonly DatabaseManager $database_manager,
		private readonly bool $enabled,
		private readonly string $frequency,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Registers the custom cron_schedules intervals and the cron hook's
	 * callback, then reconciles the scheduled event against the current
	 * configuration. Called unconditionally from Plugin::init().
	 *
	 * @return void
	 */
	public function register(): void {
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- registers this plugin's own new intervals (SCHEDULE_WEEKLY/SCHEDULE_TWICE_WEEKLY), never modifies an existing one; the sniff cannot statically see filter_cron_schedules()'s own interval values.
		add_filter( 'cron_schedules', array( $this, 'filter_cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'run' ) );

		$this->ensure_scheduled();
	}

	/**
	 * Adds this plugin's two custom recurrence intervals.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules The existing schedules.
	 *
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function filter_cron_schedules( array $schedules ): array {
		$schedules[ self::SCHEDULE_WEEKLY ] = array(
			'interval' => self::WEEKLY_SECONDS,
			'display'  => __( 'Once weekly (Universal Geo Context)', 'universal-geo-context' ),
		);

		$schedules[ self::SCHEDULE_TWICE_WEEKLY ] = array(
			'interval' => self::TWICE_WEEKLY_SECONDS,
			'display'  => __( 'Twice weekly (Universal Geo Context)', 'universal-geo-context' ),
		);

		return $schedules;
	}

	/**
	 * Self-healing reconciliation: schedules a fresh event (with a jittered
	 * first-run offset) when auto-update is enabled and none is currently
	 * scheduled, reschedules when the currently scheduled recurrence no
	 * longer matches the configured frequency, and clears any scheduled
	 * event when auto-update is disabled. Idempotent and safe to call
	 * repeatedly — never accumulates duplicate events.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! $this->enabled ) {
			$this->clear_scheduled();
			return;
		}

		$schedule_name = $this->schedule_name();
		$next          = wp_next_scheduled( self::CRON_HOOK );

		if ( false === $next ) {
			wp_schedule_event( $this->jittered_first_run_offset(), $schedule_name, self::CRON_HOOK );
			return;
		}

		$event = wp_get_scheduled_event( self::CRON_HOOK );

		if ( false !== $event && $event->schedule !== $schedule_name ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
			wp_schedule_event( $this->jittered_first_run_offset(), $schedule_name, self::CRON_HOOK );
		}
	}

	/**
	 * The cron callback: triggers a managed database update. Never surfaces
	 * a return value — cron has no caller to report to; DatabaseManager's
	 * own persisted state (`status()`) is the durable record of what
	 * happened, consumed by diagnostics and Site Health.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->database_manager->download_now( 'cron' );
	}

	/**
	 * Clears the scheduled cron hook and removes this plugin's custom
	 * intervals entirely — called from uninstall.php.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Removes the scheduled event, if any, without touching registered
	 * hooks — the "auto-update disabled" branch of ensure_scheduled().
	 *
	 * @return void
	 */
	private function clear_scheduled(): void {
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	/**
	 * The custom interval name matching the configured frequency.
	 *
	 * @return string
	 */
	private function schedule_name(): string {
		return 'twice_weekly' === $this->frequency ? self::SCHEDULE_TWICE_WEEKLY : self::SCHEDULE_WEEKLY;
	}

	/**
	 * A first-run timestamp jittered by up to ±10% of the configured
	 * interval, never before "now" — only ever computed when scheduling a
	 * brand-new event (no event currently exists), so this naturally
	 * applies exactly once per site until the schedule is later cleared.
	 *
	 * @return int
	 */
	private function jittered_first_run_offset(): int {
		$now      = ( $this->clock )();
		$interval = 'twice_weekly' === $this->frequency ? self::TWICE_WEEKLY_SECONDS : self::WEEKLY_SECONDS;
		$range    = (int) ( $interval * self::JITTER_FRACTION );

		if ( $range <= 0 ) {
			return $now;
		}

		return $now + random_int( 0, $range );
	}
}
