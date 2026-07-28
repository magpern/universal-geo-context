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
 * Covers the M1 Step 1A schema: schema_version + default_country only.
 */
final class SettingsTest extends TestCase {

	/**
	 * Resets the in-memory options store before every test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['universal_geo_test_options'] = array();
	}

	public function test_defaults_contain_exactly_two_keys(): void {
		$this->assertCount( 2, Settings::defaults() );
	}

	public function test_defaults_contain_schema_version(): void {
		$this->assertArrayHasKey( 'schema_version', Settings::defaults() );
		$this->assertSame( Settings::SCHEMA_VERSION, Settings::defaults()['schema_version'] );
	}

	public function test_defaults_contain_default_country(): void {
		$this->assertArrayHasKey( 'default_country', Settings::defaults() );
		$this->assertSame( '', Settings::defaults()['default_country'] );
	}

	public function test_defaults_are_deterministic(): void {
		$this->assertSame( Settings::defaults(), Settings::defaults() );
	}

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

	public function test_unknown_keys_are_removed(): void {
		$result = Settings::sanitize(
			array(
				'default_country'  => 'SE',
				'trusted_proxies'  => array( '10.0.0.0/8' ),
				'trust_cloudflare' => true,
				'some_random_key'  => 'whatever',
			)
		);
		$this->assertSame( array( 'schema_version', 'default_country' ), array_keys( $result ) );
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

	public function test_sanitize_returns_exactly_the_two_key_schema(): void {
		$result = Settings::sanitize(
			array(
				'schema_version'    => 999,
				'default_country'   => 'se',
				'trusted_proxies'   => array( '10.0.0.0/8' ),
				'derived_cache_ttl' => 1234,
			)
		);
		$this->assertSame( array( 'schema_version', 'default_country' ), array_keys( $result ) );
		$this->assertSame( Settings::SCHEMA_VERSION, $result['schema_version'] );
		$this->assertSame( 'SE', $result['default_country'] );
	}

	public function test_sanitize_of_non_array_returns_defaults(): void {
		$this->assertSame( Settings::defaults(), Settings::sanitize( 'not an array' ) );
		$this->assertSame( Settings::defaults(), Settings::sanitize( null ) );
		$this->assertSame( Settings::defaults(), Settings::sanitize( 42 ) );
	}

	public function test_install_creates_the_option(): void {
		$this->assertFalse( get_option( Settings::OPTION_NAME, false ) );

		Settings::install();

		$this->assertSame( Settings::defaults(), get_option( Settings::OPTION_NAME, false ) );
	}

	public function test_install_preserves_existing_valid_values(): void {
		update_option(
			Settings::OPTION_NAME,
			array(
				'schema_version'  => 1,
				'default_country' => 'DE',
			)
		);

		Settings::install();

		$stored = get_option( Settings::OPTION_NAME, false );
		$this->assertSame( 'DE', $stored['default_country'] );
	}

	public function test_install_normalizes_malformed_values(): void {
		update_option(
			Settings::OPTION_NAME,
			array(
				'schema_version'  => 1,
				'default_country' => 'germany',
			)
		);

		Settings::install();

		$stored = get_option( Settings::OPTION_NAME, false );
		$this->assertSame( '', $stored['default_country'] );
	}

	public function test_uninstall_deletes_only_universal_geo_settings(): void {
		update_option( Settings::OPTION_NAME, Settings::defaults() );

		Settings::uninstall();

		$this->assertFalse( get_option( Settings::OPTION_NAME, false ) );
	}

	/**
	 * Negative ownership test: uninstall() must not touch any option it
	 * does not own — including options future milestones will introduce.
	 */
	public function test_uninstall_does_not_delete_unrelated_options(): void {
		update_option( Settings::OPTION_NAME, Settings::defaults() );
		update_option( 'universal_geo_cache_salt', 'some-salt-value' );
		update_option( 'universal_geo_cache_epoch', 1 );
		update_option( 'universal_geo_provider_health', array( 'foo' => 'bar' ) );
		update_option( 'some_unrelated_option', 'untouched' );

		Settings::uninstall();

		$this->assertSame( 'some-salt-value', get_option( 'universal_geo_cache_salt', false ) );
		$this->assertSame( 1, get_option( 'universal_geo_cache_epoch', false ) );
		$this->assertSame( array( 'foo' => 'bar' ), get_option( 'universal_geo_provider_health', false ) );
		$this->assertSame( 'untouched', get_option( 'some_unrelated_option', false ) );
	}
}
