<?php
/**
 * MaxMind local database geolocation provider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers;

use MaxMind\Db\Reader;
use ReflectionClass;
use Throwable;
use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Http\IpUtils;
use UniversalGeo\Model\GeoCandidate;

/**
 * A local-database lookup through a soft dependency on `MaxMind\Db\Reader`
 * (`maxmind-db/reader`, dev-only — never bundled at runtime; a reader-
 * bearing plugin such as WooCommerce's own MaxMind integration, or a future
 * companion plugin, is what actually provides the class at runtime).
 * Reads `country.iso_code` always, and `subdivisions[0].iso_code` when the
 * open database happens to carry one (City-edition databases only) — this
 * class never knows or cares which edition is open, it only reads whatever
 * keys the raw record happens to have (M13). `GeoValidator::region()` —
 * applied uniformly by `ContextResolver` — is the sole place normalization
 * and validation happen; this class never uppercases, trims, or validates.
 *
 * Knows nothing about settings, constants, filters, WooCommerce, or admin
 * forms: `Plugin::resolved_maxmind_db_path()` decides the effective path
 * through the four-source precedence chain and hands this class only the
 * final, already-resolved scalar — the same `DefaultCountryProvider`
 * pattern every settings-derived provider follows.
 *
 * The `Reader` is opened lazily, at most once per request (memoized,
 * including a failed open — a corrupt or unreadable file costs exactly one
 * attempt, never retried within the same instance's lifetime). A failed
 * open is remembered and re-thrown (never silently swallowed) from
 * `resolve()`, reaching `ContextResolver`'s existing failure boundary —
 * the `WooCommerceProvider` precedent of not double-catching. `metadata()`
 * is the one place a failed/absent reader is reported as `null` rather than
 * thrown, since it exists purely for the diagnostics surface.
 *
 * @internal
 * @final
 */
final class MaxMindProvider implements GeoProviderInterface {

	/**
	 * The memoized Reader instance, or null once a failed open has been recorded.
	 *
	 * @var Reader|null
	 */
	private ?Reader $reader = null;

	/**
	 * Whether open() has already run this request — distinguishes "never
	 * tried" from "tried and failed" (open_failure alone cannot, since a
	 * successful-but-not-yet-attempted state would look identical to a
	 * successful open otherwise).
	 *
	 * @var bool
	 */
	private bool $open_attempted = false;

	/**
	 * The exception a failed open raised, memoized so resolve() can re-throw
	 * it on every call within this request without reopening the file.
	 *
	 * @var Throwable|null
	 */
	private ?Throwable $open_failure = null;

	/**
	 * Stores the single, already-resolved effective database path.
	 *
	 * @param string $db_path The effective MaxMind database path, or '' when none is configured.
	 */
	public function __construct(
		private readonly string $db_path
	) {
	}

	/**
	 * The stable provider id used for attribution and the confidence table.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'maxmind';
	}

	/**
	 * The effective database path this instance was constructed with — for
	 * diagnostics only (M3 §6 3D). Not part of GeoProviderInterface: server
	 * configuration, not a geographic fact.
	 *
	 * @return string
	 */
	public function db_path(): string {
		return $this->db_path;
	}

	/**
	 * Cheap availability check: a non-empty path, the soft dependency
	 * actually present, and the file readable at the OS level. Does not
	 * attempt to open or parse the database — a corrupt file is
	 * `is_readable() === true` and only surfaces as a `resolve()` failure.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return '' !== $this->db_path
			&& class_exists( Reader::class )
			&& is_readable( $this->db_path );
	}

	/**
	 * Looks up $ip's country (and, when available, first-level subdivision)
	 * in the local database.
	 *
	 * Self-guards against a non-public $ip before ever touching the reader
	 * (ADR-0002 decision 8, the `WooCommerceProvider` precedent) — a
	 * private-IP probe never attempts an open, never becomes a "failed"
	 * result. Reads `country.iso_code` always; reads `subdivisions[0].iso_code`
	 * too, when the record happens to carry one (M13) — a Country-edition
	 * database simply has no `subdivisions` key, so this naturally yields
	 * `null` there without this method ever needing to know which edition
	 * is open.
	 *
	 * @param string $ip The already-resolved, already-normalized client IP.
	 *
	 * @return GeoCandidate|null
	 *
	 * @throws Throwable When the reader fails to open or the lookup itself fails — propagates to ContextResolver's existing catch, never swallowed here.
	 */
	public function resolve( string $ip ): ?GeoCandidate {
		if ( ! IpUtils::is_public( $ip ) ) {
			return null;
		}

		$this->open();

		if ( null !== $this->open_failure ) {
			throw $this->open_failure;
		}

		if ( null === $this->reader ) {
			return null;
		}

		$record = $this->reader->get( $ip );

		if ( ! is_array( $record ) || ! isset( $record['country']['iso_code'] ) || ! is_string( $record['country']['iso_code'] ) ) {
			return null;
		}

		return new GeoCandidate( $record['country']['iso_code'], self::region_from_record( $record ) );
	}

	/**
	 * Extracts a raw record's first subdivision code, when present.
	 *
	 * Defensive against every malformed shape the raw `MaxMind\Db\Reader::get()`
	 * record could offer: an absent `subdivisions` key (a Country-edition
	 * database, the ordinary case today), a non-array value, an empty array,
	 * a non-array first element, or a missing/non-string `iso_code` all
	 * resolve identically to `null` — never a warning, never a fatal, and
	 * never itself a reason to treat the whole candidate as a miss (country
	 * resolution above this call already succeeded independently). The
	 * returned value is raw and unvalidated on purpose — normalization and
	 * validation belong to `GeoValidator::region()` alone, applied uniformly
	 * by `ContextResolver` regardless of which provider supplied it.
	 *
	 * @param array<string, mixed> $record The raw `MaxMind\Db\Reader::get()` record.
	 *
	 * @return string|null
	 */
	private static function region_from_record( array $record ): ?string {
		if ( ! isset( $record['subdivisions'] ) || ! is_array( $record['subdivisions'] ) || ! isset( $record['subdivisions'][0] ) ) {
			return null;
		}

		$first = $record['subdivisions'][0];

		if ( ! is_array( $first ) || ! isset( $first['iso_code'] ) || ! is_string( $first['iso_code'] ) ) {
			return null;
		}

		return $first['iso_code'];
	}

	/**
	 * Read-only metadata for diagnostics (M3 architecture report §6 3C /
	 * F8): null when unavailable or the reader fails to open, never thrown —
	 * the one place a failed open is reported rather than propagated, since
	 * this method exists purely for the diagnostics surface. Reuses the same
	 * memoized reader `resolve()` uses; never opens a second one.
	 *
	 * @return MaxMindMetadata|null
	 */
	public function metadata(): ?MaxMindMetadata {
		$this->open();

		if ( null === $this->reader ) {
			return null;
		}

		$meta = $this->reader->metadata();

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- vendor library property names, not this codebase's own.
		return new MaxMindMetadata(
			$meta->databaseType,
			$meta->buildEpoch,
			$meta->ipVersion,
			(string) ( new ReflectionClass( Reader::class ) )->getFileName()
		);
		// phpcs:enable
	}

	/**
	 * Lazily opens the reader, at most once per instance (request). Absence
	 * of the soft dependency or an empty path resolves to "no reader, no
	 * failure" (nothing was misconfigured; the feature is simply off) —
	 * only an actual `new Reader()` exception becomes a memoized failure.
	 *
	 * @return void
	 */
	private function open(): void {
		if ( $this->open_attempted ) {
			return;
		}

		$this->open_attempted = true;

		if ( '' === $this->db_path || ! class_exists( Reader::class ) ) {
			return;
		}

		try {
			$this->reader = new Reader( $this->db_path );
		} catch ( Throwable $e ) {
			$this->reader       = null;
			$this->open_failure = $e;
		}
	}
}
