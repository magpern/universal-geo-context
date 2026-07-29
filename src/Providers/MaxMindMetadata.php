<?php
/**
 * Immutable MaxMind database metadata, for diagnostics only.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Providers;

/**
 * A read-only snapshot of a MaxMind database's own metadata block, surfaced
 * exclusively through `MaxMindProvider::metadata()` for the admin
 * Diagnostics tab and the `universal_geo_maxmind` Site Health test — never
 * consumed by resolution itself. A value object for the same reason
 * `ResolvedClientIp` is one: a fixed, typed, immutable shape crossing a
 * class boundary, joined to the `ImmutabilityGuardTest` allowlist.
 *
 * @internal
 * @final
 */
final class MaxMindMetadata {

	/**
	 * Constructs a metadata snapshot from an already-opened Reader's own
	 * metadata block.
	 *
	 * @param string $database_type     The database's declared structure identifier, e.g. 'GeoLite2-Country'.
	 * @param int    $build_epoch       Unix epoch timestamp of the database build.
	 * @param int    $ip_version        4 or 6.
	 * @param string $reader_class_file The origin file of the loaded `MaxMind\Db\Reader` class (which vendored copy loaded — diagnostics only).
	 */
	public function __construct(
		public readonly string $database_type,
		public readonly int $build_epoch,
		public readonly int $ip_version,
		public readonly string $reader_class_file
	) {
	}

	/**
	 * The database's age in whole days, relative to a caller-supplied "now"
	 * (never reads the system clock itself — keeps this a pure value object).
	 *
	 * @param int $now A Unix timestamp, typically time().
	 *
	 * @return int
	 */
	public function build_age_days( int $now ): int {
		return (int) floor( ( $now - $this->build_epoch ) / 86400 );
	}
}
