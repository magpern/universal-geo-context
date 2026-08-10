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
 * `$enabled`/`$frequency` are deliberately **not** constructor state:
 * `register()` is called once, in `Plugin::init()`, but `ensure_scheduled()`
 * must also run again, in the same request, from
 * `AdminScreen::handle_save_settings()` — immediately after a settings save
 * — using the just-sanitized values, not the ones `Plugin::build_graph()`
 * resolved at the start of the request. Taking them as call-time parameters
 * instead avoids a second `UpdateScheduler` construction (which would
 * violate the composition-root invariant, `Plugin` being the exclusive
 * constructor of internal services) while still always reconciling against
 * current, not stale, configuration.
 *
 * `register()` is called unconditionally from `Plugin::init()` — cron
 * requests are neither `is_admin()` nor WP-CLI, so this cannot live inside
 * either existing gated construction branch. `ensure_scheduled()` is
 * self-healing reconciliation: safe to call repeatedly, since it only ever
 * schedules/reschedules/clears based on the arguments given, never
 * accumulating duplicate events.
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
	 * Stores the injected dependencies.
	 *
	 * @param DatabaseManager      $database_manager The manager run() delegates to.
	 * @param callable(): int|null $clock             Returns the current Unix timestamp. Defaults to `static fn(): int => time()`.
	 */
	public function __construct(
		private readonly DatabaseManager $database_manager,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Registers the custom cron_schedules intervals and the cron hook's
	 * callback, then reconciles the scheduled event against the given
	 * configuration. Called unconditionally from Plugin::init().
	 *
	 * @param bool   $enabled   Whether auto-update is enabled (maxmind_managed_auto_update_enabled, already AND-gated against maxmind_managed_enabled by Settings::sanitize()).
	 * @param string $frequency 'weekly' or 'twice_weekly'.
	 *
	 * @return void
	 */
	public function register( bool $enabled, string $frequency ): void {
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- registers this plugin's own new intervals (SCHEDULE_WEEKLY/SCHEDULE_TWICE_WEEKLY), never modifies an existing one; the sniff cannot statically see filter_cron_schedules()'s own interval values.
		add_filter( 'cron_schedules', array( $this, 'filter_cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'run' ) );

		$this->ensure_scheduled( $enabled, $frequency );
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
	 * first-run offset) when $enabled is true and none is currently
	 * scheduled, reschedules when the currently scheduled recurrence no
	 * longer matches $frequency, and clears any scheduled event when
	 * $enabled is false. Idempotent and safe to call repeatedly — never
	 * accumulates duplicate events. Called both from register() (once per
	 * request, at graph build) and directly from
	 * AdminScreen::handle_save_settings() (with the just-sanitized values,
	 * immediately after a save).
	 *
	 * @param bool   $enabled   Whether auto-update is enabled.
	 * @param string $frequency 'weekly' or 'twice_weekly'.
	 *
	 * @return void
	 */
	public function ensure_scheduled( bool $enabled, string $frequency ): void {
		if ( ! $enabled ) {
			$this->clear_scheduled();
			return;
		}

		$schedule_name = $this->schedule_name( $frequency );
		$next          = wp_next_scheduled( self::CRON_HOOK );

		if ( false === $next ) {
			wp_schedule_event( $this->jittered_first_run_offset( $frequency ), $schedule_name, self::CRON_HOOK );
			return;
		}

		$event = wp_get_scheduled_event( self::CRON_HOOK );

		if ( false !== $event && $event->schedule !== $schedule_name ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
			wp_schedule_event( $this->jittered_first_run_offset( $frequency ), $schedule_name, self::CRON_HOOK );
		}
	}

	/**
	 * Passive scheduler snapshot for diagnostics/CLI — never mutates cron state.
	 *
	 * @return array{scheduler_registered: bool, next_scheduled_at: int|null}
	 */
	public function status(): array {
		$next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( self::CRON_HOOK ) : false;

		return array(
			'scheduler_registered' => false !== $next,
			'next_scheduled_at'    => false !== $next ? (int) $next : null,
		);
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
	 * The custom interval name matching $frequency.
	 *
	 * @param string $frequency 'weekly' or 'twice_weekly'.
	 *
	 * @return string
	 */
	private function schedule_name( string $frequency ): string {
		return 'twice_weekly' === $frequency ? self::SCHEDULE_TWICE_WEEKLY : self::SCHEDULE_WEEKLY;
	}

	/**
	 * A first-run timestamp jittered by up to ±10% of $frequency's
	 * interval, never before "now" — only ever computed when scheduling a
	 * brand-new event (no event currently exists), so this naturally
	 * applies exactly once per site until the schedule is later cleared.
	 *
	 * @param string $frequency 'weekly' or 'twice_weekly'.
	 *
	 * @return int
	 */
	private function jittered_first_run_offset( string $frequency ): int {
		$now      = ( $this->clock )();
		$interval = 'twice_weekly' === $frequency ? self::TWICE_WEEKLY_SECONDS : self::WEEKLY_SECONDS;
		$range    = (int) ( $interval * self::JITTER_FRACTION );

		if ( $range <= 0 ) {
			return $now;
		}

		return $now + random_int( 0, $range );
	}
}
