<?php
/**
 * Unit test: load_plugin_textdomain() is wired correctly (M5).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The main plugin file (universal-geo-context.php) is a one-shot WordPress
 * bootstrap file — its
 * register_activation_hook() call and several add_action() closures are not
 * idempotent, so re-`require`-ing it inside a test process (to exercise
 * plugins_loaded for real) would double-register hooks and is not a safe
 * test technique here. This test instead does source-level verification —
 * the same technique the guard tests already use throughout this codebase
 * (tests/Support/SourceGuardTrait.php) — that the load_plugin_textdomain()
 * call exists, targets the correct domain, and is registered ahead of the
 * PHP-version guard (so the guard's own admin_notices message can still
 * translate on a PHP version too old for the rest of the file to matter).
 * The actual runtime effect (a real .mo file loading) is verified via
 * manual/live acceptance, the same "not unit-testable beyond X" pattern
 * already established for AdminScreen::redirect_with_notice().
 */
final class TextdomainLoadingTest extends TestCase {

	private function main_plugin_file_contents(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/universal-geo-context.php' );
	}

	public function test_load_plugin_textdomain_is_called_with_the_correct_domain(): void {
		$this->assertMatchesRegularExpression(
			'/load_plugin_textdomain\(\s*\'universal-geo-context\'/',
			$this->main_plugin_file_contents()
		);
	}

	public function test_load_plugin_textdomain_is_registered_before_the_php_version_guard(): void {
		$contents = $this->main_plugin_file_contents();

		$load_position  = strpos( $contents, 'load_plugin_textdomain(' );
		$guard_position = strpos( $contents, "version_compare( PHP_VERSION, '8.1', '<' )" );

		$this->assertNotFalse( $load_position );
		$this->assertNotFalse( $guard_position );
		$this->assertLessThan(
			$guard_position,
			$load_position,
			'load_plugin_textdomain() must be registered before the PHP-version guard, so the guard\'s own notice can translate.'
		);
	}

	public function test_domain_path_header_matches_the_load_call(): void {
		$contents = $this->main_plugin_file_contents();

		$this->assertMatchesRegularExpression( '/^\s*\*\s*Domain Path:\s*\/languages\s*$/m', $contents );
		$this->assertMatchesRegularExpression( "/'\\/languages'/", $contents );
	}
}
