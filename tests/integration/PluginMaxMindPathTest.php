<?php
/**
 * Integration tests for UniversalGeo\Plugin's WooCommerce-derived MaxMind
 * path auto-detection, which needs a real wp_upload_dir().
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration;

use ReflectionMethod;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\Plugin;
use UniversalGeo\Settings;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use WP_UnitTestCase;

/**
 * Covers the branches of Plugin::resolved_maxmind_db_path() the unit suite
 * cannot exercise deterministically: WooCommerce auto-detection and the
 * managed-path tier (M6), both only reached when the setting is empty and
 * both needing a real wp_upload_dir() (absent in the WordPress-free unit
 * bootstrap).
 */
final class PluginMaxMindPathTest extends WP_UnitTestCase {

	private const COUNTRY_DB = __DIR__ . '/../fixtures/GeoIP2-Country-Test.mmdb';

	protected function setUp(): void {
		parent::setUp();

		// WooCommerce's own MaxMind integration auto-generates and persists
		// a random database_prefix the first time it bootstraps (already
		// happened once during this test run's WC_Install::install() at
		// setup_theme) — reset to a known, empty prefix so these tests
		// control the candidate filename deterministically rather than
		// fighting WooCommerce's own randomly-generated value.
		update_option( 'woocommerce_maxmind_geolocation_settings', array() );
	}

	protected function tearDown(): void {
		delete_option( 'woocommerce_maxmind_geolocation_settings' );
		parent::tearDown();
	}

	/**
	 * @return array{path: string, source: string}
	 */
	private function resolved_path( array $settings, ?DatabaseManager $database_manager = null ): array {
		$reflection = new ReflectionMethod( Plugin::class, 'resolved_maxmind_db_path' );
		$reflection->setAccessible( true );

		return $reflection->invoke( Plugin::instance(), $settings, $database_manager ?? $this->unused_database_manager() );
	}

	private function unused_database_manager(): DatabaseManager {
		return new DatabaseManager(
			sys_get_temp_dir() . '/ugeo-plugin-maxmind-path-test-unused',
			'',
			'',
			true,
			new FakeHttpTransport(),
			new ArchiveExtractor(),
			new UpdateLock()
		);
	}

	private function base_settings(): array {
		return Settings::sanitize( array() );
	}

	private function managed_dir(): string {
		$upload_dir = wp_upload_dir();

		return rtrim( $upload_dir['basedir'], '/' ) . '/universal-geo-context/maxmind';
	}

	public function test_wc_auto_detect_finds_a_contained_database(): void {
		$upload_dir = wp_upload_dir();
		$target_dir = rtrim( $upload_dir['basedir'], '/' ) . '/woocommerce_uploads';

		if ( ! is_dir( $target_dir ) ) {
			mkdir( $target_dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		$target = $target_dir . '/GeoLite2-Country.mmdb';
		copy( self::COUNTRY_DB, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$result = $this->resolved_path( $this->base_settings() );

		unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( $target, $result['path'] );
		$this->assertSame( 'woocommerce', $result['source'] );
	}

	public function test_wc_auto_detect_honors_the_configured_database_prefix(): void {
		update_option(
			'woocommerce_maxmind_geolocation_settings',
			array( 'database_prefix' => 'CustomPrefix' )
		);

		$upload_dir = wp_upload_dir();
		$target_dir = rtrim( $upload_dir['basedir'], '/' ) . '/woocommerce_uploads';

		if ( ! is_dir( $target_dir ) ) {
			mkdir( $target_dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		$target = $target_dir . '/CustomPrefix-GeoLite2-Country.mmdb';
		copy( self::COUNTRY_DB, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$result = $this->resolved_path( $this->base_settings() );

		unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( $target, $result['path'] );
	}

	public function test_wc_auto_detect_candidate_missing_on_disk_resolves_to_empty(): void {
		// No file was ever created at the auto-detected path.
		$result = $this->resolved_path( $this->base_settings() );

		$this->assertSame( '', $result['path'] );
		$this->assertSame( 'none', $result['source'] );
	}

	public function test_setting_takes_precedence_over_wc_auto_detection(): void {
		$upload_dir = wp_upload_dir();
		$target_dir = rtrim( $upload_dir['basedir'], '/' ) . '/woocommerce_uploads';

		if ( ! is_dir( $target_dir ) ) {
			mkdir( $target_dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		$wc_target = $target_dir . '/GeoLite2-Country.mmdb';
		copy( self::COUNTRY_DB, $wc_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$explicit_target = rtrim( WP_CONTENT_DIR, '/' ) . '/explicit-geo.mmdb';
		copy( self::COUNTRY_DB, $explicit_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$settings                    = $this->base_settings();
		$settings['maxmind_db_path'] = $explicit_target;

		$result = $this->resolved_path( $settings );

		unlink( $wc_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $explicit_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( $explicit_target, $result['path'] );
		$this->assertSame( 'settings', $result['source'] );
	}

	// ---- M6: managed tier vs. WooCommerce auto-detection ---------------------------

	public function test_managed_path_takes_precedence_over_wc_auto_detection(): void {
		$upload_dir = wp_upload_dir();
		$wc_dir     = rtrim( $upload_dir['basedir'], '/' ) . '/woocommerce_uploads';

		if ( ! is_dir( $wc_dir ) ) {
			mkdir( $wc_dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		$wc_target = $wc_dir . '/GeoLite2-Country.mmdb';
		copy( self::COUNTRY_DB, $wc_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$managed_dir = $this->managed_dir();
		mkdir( $managed_dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$managed_target = $managed_dir . '/GeoLite2-Country.mmdb';
		copy( self::COUNTRY_DB, $managed_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$database_manager = new DatabaseManager( $managed_dir, '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() );

		$settings                            = $this->base_settings();
		$settings['maxmind_managed_enabled'] = true;

		$result = $this->resolved_path( $settings, $database_manager );

		unlink( $wc_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $managed_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		rmdir( $managed_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir

		$this->assertSame( $managed_target, $result['path'] );
		$this->assertSame( 'managed', $result['source'] );
	}

	public function test_wc_auto_detect_is_used_when_managed_is_enabled_but_nothing_is_installed(): void {
		$upload_dir = wp_upload_dir();
		$wc_dir     = rtrim( $upload_dir['basedir'], '/' ) . '/woocommerce_uploads';

		if ( ! is_dir( $wc_dir ) ) {
			mkdir( $wc_dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		$wc_target = $wc_dir . '/GeoLite2-Country.mmdb';
		copy( self::COUNTRY_DB, $wc_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$database_manager = new DatabaseManager( $this->managed_dir(), '', '', true, new FakeHttpTransport(), new ArchiveExtractor(), new UpdateLock() );

		$settings                            = $this->base_settings();
		$settings['maxmind_managed_enabled'] = true;

		$result = $this->resolved_path( $settings, $database_manager );

		unlink( $wc_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( $wc_target, $result['path'] );
		$this->assertSame( 'woocommerce', $result['source'] );
	}
}
