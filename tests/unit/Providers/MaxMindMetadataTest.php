<?php
/**
 * Unit tests for UniversalGeo\Providers\MaxMindMetadata.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Providers\MaxMindMetadata;

/**
 * Covers construction and build_age_days() — the model-level coverage the
 * M3 architecture report §6 3C requires for this value object.
 */
final class MaxMindMetadataTest extends TestCase {

	private function metadata( int $build_epoch = 1000000000 ): MaxMindMetadata {
		return new MaxMindMetadata( 'GeoIP2-Country', $build_epoch, 4, '/vendor/maxmind-db/reader/src/MaxMind/Db/Reader.php' );
	}

	public function test_construction_stores_every_field(): void {
		$metadata = $this->metadata();

		$this->assertSame( 'GeoIP2-Country', $metadata->database_type );
		$this->assertSame( 1000000000, $metadata->build_epoch );
		$this->assertSame( 4, $metadata->ip_version );
		$this->assertSame( '/vendor/maxmind-db/reader/src/MaxMind/Db/Reader.php', $metadata->reader_class_file );
	}

	public function test_build_age_days_zero_when_built_now(): void {
		$now = 1000000000;
		$this->assertSame( 0, $this->metadata( $now )->build_age_days( $now ) );
	}

	public function test_build_age_days_computes_whole_days_elapsed(): void {
		$build_epoch = 1000000000;
		$now         = $build_epoch + ( 45 * 86400 );

		$this->assertSame( 45, $this->metadata( $build_epoch )->build_age_days( $now ) );
	}

	public function test_build_age_days_rounds_down_a_partial_day(): void {
		$build_epoch = 1000000000;
		$now         = $build_epoch + ( 45 * 86400 ) + 3600;

		$this->assertSame( 45, $this->metadata( $build_epoch )->build_age_days( $now ) );
	}

	public function test_build_age_days_handles_a_now_before_build_epoch(): void {
		// Clock skew / a database dated in the future relative to $now — not
		// expected in practice, but must not throw or behave nonsensically.
		$build_epoch = 1000000000;
		$now         = $build_epoch - 86400;

		$this->assertSame( -1, $this->metadata( $build_epoch )->build_age_days( $now ) );
	}
}
