<?php
/**
 * Unit tests for UniversalGeo\Settings.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Settings;

/**
 * Covers the M2 sub-step 2C six-key schema: schema_version, default_country
 * (M1), plus trusted_proxies, trust_cloudflare, derived_cache_enabled, and
 * derived_cache_ttl.
 */
final class SettingsTest extends TestCase {

	/**
	 * Resets the in-memory options store before every test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['universal_geo_test_options'] = array();
	}

	private const EXPECTED_KEYS = array(
		'schema_version',
		'default_country',
		'trusted_proxies',
		'trust_cloudflare',
		'derived_cache_enabled',
		'derived_cache_ttl',
		'maxmind_db_path',
	);

	// ---- defaults() -----------------------------------------------------------

	public function test_defaults_contain_exactly_seven_keys(): void {
		$this->assertSame( self::EXPECTED_KEYS, array_keys( Settings::defaults() ) );
	}

	public function test_defaults_contain_schema_version(): void {
		$this->assertSame( Settings::SCHEMA_VERSION, Settings::defaults()['schema_version'] );
	}

	public function test_defaults_contain_default_country(): void {
		$this->assertSame( '', Settings::defaults()['default_country'] );
	}

	public function test_defaults_trusted_proxies_is_empty(): void {
		$this->assertSame( array(), Settings::defaults()['trusted_proxies'] );
	}

	public function test_defaults_trust_cloudflare_is_false(): void {
		$this->assertFalse( Settings::defaults()['trust_cloudflare'] );
	}

	public function test_defaults_derived_cache_enabled_is_true(): void {
		$this->assertTrue( Settings::defaults()['derived_cache_enabled'] );
	}

	public function test_defaults_derived_cache_ttl_is_900(): void {
		$this->assertSame( 900, Settings::defaults()['derived_cache_ttl'] );
	}

	public function test_defaults_maxmind_db_path_is_empty(): void {
		$this->assertSame( '', Settings::defaults()['maxmind_db_path'] );
	}

	public function test_defaults_are_deterministic(): void {
		$this->assertSame( Settings::defaults(), Settings::defaults() );
	}

	// ---- default_country --------------------------------------------------------

	public function test_lowercase_country_becomes_uppercase(): void {
		$result = Settings::sanitize( array( 'default_country' => 'se' ) );
		$this->assertSame( 'SE', $result['default_country'] );
	}

	public function test_uppercase_country_remains_unchanged(): void {
		$result = Settings::sanitize( array( 'default_country' => 'SE' ) );
		$this->assertSame( 'SE', $result['default_country'] );
	}

	/**
	 * @dataProvider malformed_country_provider
	 */
	public function test_malformed_country_falls_back_safely( $malformed ): void {
		$result = Settings::sanitize( array( 'default_country' => $malformed ) );
		$this->assertSame( '', $result['default_country'] );
	}

	public function malformed_country_provider(): array {
		return array(
			'three letters'   => array( 'SWE' ),
			'one letter'      => array( 'S' ),
			'digits'          => array( '12' ),
			'not a string'    => array( 123 ),
			'array'           => array( array( 'SE' ) ),
			'boolean'         => array( true ),
			'null'            => array( null ),
			'whitespace only' => array( '   ' ),
		);
	}

	public function test_empty_country_is_accepted(): void {
		$result = Settings::sanitize( array( 'default_country' => '' ) );
		$this->assertSame( '', $result['default_country'] );
	}

	// ---- trusted_proxies ----------------------------------------------------------

	public function test_valid_ipv4_cidr_is_kept(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( '172.18.0.0/16' ) ) );
		$this->assertSame( array( '172.18.0.0/16' ), $result['trusted_proxies'] );
	}

	public function test_valid_ipv6_cidr_is_kept(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( '2001:db8::/32' ) ) );
		$this->assertSame( array( '2001:db8::/32' ), $result['trusted_proxies'] );
	}

	public function test_bare_ip_without_prefix_is_kept_as_is(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( '127.0.0.1' ) ) );
		$this->assertSame( array( '127.0.0.1' ), $result['trusted_proxies'] );
	}

	public function test_ipv4_slash_0_is_rejected(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( '0.0.0.0/0' ) ) );
		$this->assertSame( array(), $result['trusted_proxies'] );
	}

	public function test_ipv6_slash_0_is_rejected(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( '::/0' ) ) );
		$this->assertSame( array(), $result['trusted_proxies'] );
	}

	public function test_ipv4_prefix_above_32_is_rejected(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( '10.0.0.0/33' ) ) );
		$this->assertSame( array(), $result['trusted_proxies'] );
	}

	public function test_ipv6_prefix_above_128_is_rejected(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( '2001:db8::/129' ) ) );
		$this->assertSame( array(), $result['trusted_proxies'] );
	}

	public function test_non_numeric_prefix_is_rejected(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( '10.0.0.0/abc' ) ) );
		$this->assertSame( array(), $result['trusted_proxies'] );
	}

	public function test_malformed_subnet_address_is_rejected(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( 'not-an-ip/8' ) ) );
		$this->assertSame( array(), $result['trusted_proxies'] );
	}

	public function test_traversal_style_string_is_rejected(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( '../../etc/passwd' ) ) );
		$this->assertSame( array(), $result['trusted_proxies'] );
	}

	public function test_non_string_entries_are_dropped(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( 123, null, array( 'x' ), true ) ) );
		$this->assertSame( array(), $result['trusted_proxies'] );
	}

	public function test_invalid_entries_are_dropped_but_valid_entries_survive(): void {
		$result = Settings::sanitize(
			array(
				'trusted_proxies' => array( '172.18.0.0/16', '0.0.0.0/0', 'garbage', '10.0.0.0/8' ),
			)
		);
		$this->assertSame( array( '172.18.0.0/16', '10.0.0.0/8' ), $result['trusted_proxies'] );
	}

	public function test_duplicate_entries_are_deduplicated(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => array( '10.0.0.0/8', '10.0.0.0/8' ) ) );
		$this->assertSame( array( '10.0.0.0/8' ), $result['trusted_proxies'] );
	}

	public function test_non_array_trusted_proxies_becomes_empty(): void {
		$result = Settings::sanitize( array( 'trusted_proxies' => 'not-an-array' ) );
		$this->assertSame( array(), $result['trusted_proxies'] );
	}

	public function test_ipv4_slash_1_is_accepted(): void {
		// Only /0 is the specifically-rejected value; /1 is unusual but
		// syntactically valid and not the plugin's concern to police further.
		$result = Settings::sanitize( array( 'trusted_proxies' => array( '128.0.0.0/1' ) ) );
		$this->assertSame( array( '128.0.0.0/1' ), $result['trusted_proxies'] );
	}

	// ---- trust_cloudflare -----------------------------------------------------------

	public function test_trust_cloudflare_true_is_kept(): void {
		$this->assertTrue( Settings::sanitize( array( 'trust_cloudflare' => true ) )['trust_cloudflare'] );
	}

	public function test_trust_cloudflare_false_is_kept(): void {
		$this->assertFalse( Settings::sanitize( array( 'trust_cloudflare' => false ) )['trust_cloudflare'] );
	}

	public function test_trust_cloudflare_truthy_string_becomes_true(): void {
		$this->assertTrue( Settings::sanitize( array( 'trust_cloudflare' => '1' ) )['trust_cloudflare'] );
	}

	public function test_trust_cloudflare_missing_defaults_to_false(): void {
		$this->assertFalse( Settings::sanitize( array() )['trust_cloudflare'] );
	}

	// ---- derived_cache_enabled --------------------------------------------------------

	public function test_derived_cache_enabled_false_is_kept(): void {
		$this->assertFalse( Settings::sanitize( array( 'derived_cache_enabled' => false ) )['derived_cache_enabled'] );
	}

	public function test_derived_cache_enabled_missing_defaults_to_true(): void {
		$this->assertTrue( Settings::sanitize( array() )['derived_cache_enabled'] );
	}

	// ---- derived_cache_ttl --------------------------------------------------------------

	public function test_valid_ttl_is_kept(): void {
		$this->assertSame( 1234, Settings::sanitize( array( 'derived_cache_ttl' => 1234 ) )['derived_cache_ttl'] );
	}

	public function test_negative_ttl_falls_back_to_default(): void {
		$this->assertSame( 900, Settings::sanitize( array( 'derived_cache_ttl' => -1 ) )['derived_cache_ttl'] );
	}

	public function test_zero_ttl_falls_back_to_default(): void {
		$this->assertSame( 900, Settings::sanitize( array( 'derived_cache_ttl' => 0 ) )['derived_cache_ttl'] );
	}

	public function test_non_numeric_ttl_falls_back_to_default(): void {
		$this->assertSame( 900, Settings::sanitize( array( 'derived_cache_ttl' => 'not-a-number' ) )['derived_cache_ttl'] );
	}

	public function test_ttl_below_floor_is_clamped_up(): void {
		$this->assertSame( 60, Settings::sanitize( array( 'derived_cache_ttl' => 5 ) )['derived_cache_ttl'] );
	}

	public function test_ttl_above_ceiling_is_clamped_down(): void {
		$this->assertSame( 86400, Settings::sanitize( array( 'derived_cache_ttl' => 999999999 ) )['derived_cache_ttl'] );
	}

	public function test_numeric_string_ttl_is_accepted(): void {
		$this->assertSame( 500, Settings::sanitize( array( 'derived_cache_ttl' => '500' ) )['derived_cache_ttl'] );
	}

	// ---- maxmind_db_path (M3) --------------------------------------------------

	public function test_maxmind_db_path_accepts_an_absolute_path(): void {
		$result = Settings::sanitize( array( 'maxmind_db_path' => '/var/www/html/wp-content/uploads/GeoLite2-Country.mmdb' ) );
		$this->assertSame( '/var/www/html/wp-content/uploads/GeoLite2-Country.mmdb', $result['maxmind_db_path'] );
	}

	public function test_maxmind_db_path_empty_string_is_accepted_as_auto_detect(): void {
		$result = Settings::sanitize( array( 'maxmind_db_path' => '' ) );
		$this->assertSame( '', $result['maxmind_db_path'] );
	}

	public function test_maxmind_db_path_missing_defaults_to_empty(): void {
		$this->assertSame( '', Settings::sanitize( array() )['maxmind_db_path'] );
	}

	public function test_maxmind_db_path_trims_surrounding_whitespace(): void {
		$result = Settings::sanitize( array( 'maxmind_db_path' => '  /var/www/html/wp-content/uploads/geo.mmdb  ' ) );
		$this->assertSame( '/var/www/html/wp-content/uploads/geo.mmdb', $result['maxmind_db_path'] );
	}

	public function test_maxmind_db_path_whitespace_only_becomes_empty(): void {
		$this->assertSame( '', Settings::sanitize( array( 'maxmind_db_path' => '   ' ) )['maxmind_db_path'] );
	}

	public function test_maxmind_db_path_relative_path_is_rejected(): void {
		$result = Settings::sanitize( array( 'maxmind_db_path' => 'relative/path/geo.mmdb' ) );
		$this->assertSame( '', $result['maxmind_db_path'] );
	}

	public function test_maxmind_db_path_bare_filename_is_rejected(): void {
		$result = Settings::sanitize( array( 'maxmind_db_path' => 'geo.mmdb' ) );
		$this->assertSame( '', $result['maxmind_db_path'] );
	}

	public function test_maxmind_db_path_dot_dot_relative_is_rejected(): void {
		$result = Settings::sanitize( array( 'maxmind_db_path' => '../../etc/passwd' ) );
		$this->assertSame( '', $result['maxmind_db_path'] );
	}

	/**
	 * A traversal-containing but still absolute path is a syntactically
	 * valid absolute path (sanitize() is filesystem-free) — realpath()
	 * containment against WP_CONTENT_DIR is AdminScreen's job, not this
	 * one's. This test documents that boundary rather than asserting a
	 * rejection sanitize() must not perform.
	 */
	public function test_maxmind_db_path_absolute_path_with_traversal_is_syntactically_accepted(): void {
		$result = Settings::sanitize( array( 'maxmind_db_path' => '/var/www/html/wp-content/../../../etc/passwd' ) );
		$this->assertSame( '/var/www/html/wp-content/../../../etc/passwd', $result['maxmind_db_path'] );
	}

	public function test_maxmind_db_path_null_byte_is_rejected(): void {
		$result = Settings::sanitize( array( 'maxmind_db_path' => "/var/www/html/wp-content/geo.mmdb\0.jpg" ) );
		$this->assertSame( '', $result['maxmind_db_path'] );
	}

	public function test_maxmind_db_path_array_is_rejected(): void {
		$result = Settings::sanitize( array( 'maxmind_db_path' => array( '/var/www/geo.mmdb' ) ) );
		$this->assertSame( '', $result['maxmind_db_path'] );
	}

	public function test_maxmind_db_path_non_string_is_rejected(): void {
		$this->assertSame( '', Settings::sanitize( array( 'maxmind_db_path' => 123 ) )['maxmind_db_path'] );
		$this->assertSame( '', Settings::sanitize( array( 'maxmind_db_path' => true ) )['maxmind_db_path'] );
		$this->assertSame( '', Settings::sanitize( array( 'maxmind_db_path' => null ) )['maxmind_db_path'] );
	}

	// ---- v2 -> v3 migration -----------------------------------------------------

	public function test_v2_settings_migrate_to_v3_with_maxmind_db_path_defaulted(): void {
		$result = Settings::sanitize(
			array(
				'schema_version'        => 2,
				'default_country'       => 'SE',
				'trusted_proxies'       => array( '10.0.0.0/8' ),
				'trust_cloudflare'      => true,
				'derived_cache_enabled' => true,
				'derived_cache_ttl'     => 900,
			)
		);

		$this->assertSame( 3, $result['schema_version'] );
		$this->assertSame( 'SE', $result['default_country'] );
		$this->assertSame( '', $result['maxmind_db_path'] );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );
	}

	// ---- Overall schema shape -----------------------------------------------------------

	public function test_unknown_keys_are_removed(): void {
		$result = Settings::sanitize(
			array(
				'default_country' => 'SE',
				'some_random_key' => 'whatever',
			)
		);
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );
	}

	public function test_missing_keys_receive_defaults(): void {
		$result = Settings::sanitize( array() );
		$this->assertSame( Settings::defaults(), $result );
	}

	public function test_schema_version_cannot_be_overridden(): void {
		$result = Settings::sanitize(
			array(
				'schema_version'  => 999,
				'default_country' => 'SE',
			)
		);
		$this->assertSame( Settings::SCHEMA_VERSION, $result['schema_version'] );
	}

	public function test_sanitize_returns_exactly_the_seven_key_schema(): void {
		$result = Settings::sanitize(
			array(
				'schema_version'        => 999,
				'default_country'       => 'se',
				'trusted_proxies'       => array( '10.0.0.0/8' ),
				'trust_cloudflare'      => true,
				'derived_cache_enabled' => false,
				'derived_cache_ttl'     => 1234,
				'maxmind_db_path'       => '/var/www/html/wp-content/uploads/geo.mmdb',
			)
		);

		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );
		$this->assertSame( Settings::SCHEMA_VERSION, $result['schema_version'] );
		$this->assertSame( 'SE', $result['default_country'] );
		$this->assertSame( array( '10.0.0.0/8' ), $result['trusted_proxies'] );
		$this->assertTrue( $result['trust_cloudflare'] );
		$this->assertFalse( $result['derived_cache_enabled'] );
		$this->assertSame( 1234, $result['derived_cache_ttl'] );
		$this->assertSame( '/var/www/html/wp-content/uploads/geo.mmdb', $result['maxmind_db_path'] );
	}

	public function test_sanitize_of_non_array_returns_defaults(): void {
		$this->assertSame( Settings::defaults(), Settings::sanitize( 'not an array' ) );
		$this->assertSame( Settings::defaults(), Settings::sanitize( null ) );
		$this->assertSame( Settings::defaults(), Settings::sanitize( 42 ) );
	}

	// ---- install() / uninstall() -----------------------------------------------------------

	public function test_install_creates_the_option(): void {
		$this->assertFalse( get_option( Settings::OPTION_NAME, false ) );

		Settings::install();

		$this->assertSame( Settings::defaults(), get_option( Settings::OPTION_NAME, false ) );
	}

	public function test_install_preserves_existing_valid_values(): void {
		update_option(
			Settings::OPTION_NAME,
			array(
				'schema_version'   => 2,
				'default_country'  => 'DE',
				'trusted_proxies'  => array( '172.18.0.0/16' ),
				'trust_cloudflare' => true,
			)
		);

		Settings::install();

		$stored = get_option( Settings::OPTION_NAME, false );
		$this->assertSame( 'DE', $stored['default_country'] );
		$this->assertSame( array( '172.18.0.0/16' ), $stored['trusted_proxies'] );
		$this->assertTrue( $stored['trust_cloudflare'] );
	}

	public function test_install_normalizes_malformed_values(): void {
		update_option(
			Settings::OPTION_NAME,
			array(
				'schema_version'  => 1,
				'default_country' => 'germany',
				'trusted_proxies' => array( '0.0.0.0/0' ),
			)
		);

		Settings::install();

		$stored = get_option( Settings::OPTION_NAME, false );
		$this->assertSame( '', $stored['default_country'] );
		$this->assertSame( array(), $stored['trusted_proxies'] );
	}

	public function test_uninstall_deletes_universal_geo_settings(): void {
		update_option( Settings::OPTION_NAME, Settings::defaults() );

		Settings::uninstall();

		$this->assertFalse( get_option( Settings::OPTION_NAME, false ) );
	}

	/**
	 * M3: universal_geo_provider_health (ProviderHealthStore's option) joins
	 * Settings::uninstall()'s deletions — the all-or-nothing retention
	 * behavior this class's uninstall() now owns for both its own option and
	 * ProviderHealthStore's.
	 */
	public function test_uninstall_deletes_universal_geo_provider_health(): void {
		update_option( 'universal_geo_provider_health', array( 'maxmind' => array( 'approx_count' => 1 ) ) );

		Settings::uninstall();

		$this->assertFalse( get_option( 'universal_geo_provider_health', false ) );
	}

	/**
	 * Negative ownership test: uninstall() must not touch any option it
	 * does not own — including options future milestones will introduce.
	 */
	public function test_uninstall_does_not_delete_unrelated_options(): void {
		update_option( Settings::OPTION_NAME, Settings::defaults() );
		update_option( 'universal_geo_cache_salt', 'some-salt-value' );
		update_option( 'universal_geo_cache_epoch', 1 );
		update_option( 'some_unrelated_option', 'untouched' );

		Settings::uninstall();

		$this->assertSame( 'some-salt-value', get_option( 'universal_geo_cache_salt', false ) );
		$this->assertSame( 1, get_option( 'universal_geo_cache_epoch', false ) );
		$this->assertSame( 'untouched', get_option( 'some_unrelated_option', false ) );
	}
}
