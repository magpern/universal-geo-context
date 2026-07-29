<?php
/**
 * Unit test: the version-header parity ritual, automated (M5).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md's release ritual names four places a version must agree:
 * the plugin header's `Version:` line, the `UNIVERSAL_GEO_VERSION` constant,
 * `docs/COMPATIBILITY.md`'s stated "Current plugin version", and (M5)
 * `readme.txt`'s `Stable tag`. Previously checked manually before every
 * tag; this test makes it a release test that runs on every PR/CI build
 * (Revision 3 §16: "maturity checks... arrive in M5 as ordinary release
 * tests, not architectural guards" — this is one of them, not a fifth
 * guard test). Pure file parsing, no WordPress bootstrap needed.
 */
final class VersionParityTest extends TestCase {

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	private function plugin_header_version(): string {
		$contents = (string) file_get_contents( $this->root() . '/universal-geo-context.php' );

		preg_match( '/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/m', $contents, $matches );

		return $matches[1] ?? '';
	}

	private function version_constant(): string {
		$contents = (string) file_get_contents( $this->root() . '/universal-geo-context.php' );

		preg_match( '/define\(\s*\'UNIVERSAL_GEO_VERSION\',\s*\'([0-9]+\.[0-9]+\.[0-9]+)\'\s*\)/', $contents, $matches );

		return $matches[1] ?? '';
	}

	private function compatibility_doc_version(): string {
		$contents = (string) file_get_contents( $this->root() . '/docs/COMPATIBILITY.md' );

		preg_match( '/Current plugin version:\s*`([0-9]+\.[0-9]+\.[0-9]+)`/', $contents, $matches );

		return $matches[1] ?? '';
	}

	private function readme_txt_stable_tag(): string {
		$contents = (string) file_get_contents( $this->root() . '/readme.txt' );

		preg_match( '/^Stable tag:\s*([0-9]+\.[0-9]+\.[0-9]+)/m', $contents, $matches );

		return $matches[1] ?? '';
	}

	public function test_plugin_header_version_is_extracted(): void {
		$this->assertNotSame( '', $this->plugin_header_version(), 'Could not find a Version: line in universal-geo-context.php.' );
	}

	public function test_version_constant_matches_the_plugin_header(): void {
		$this->assertSame( $this->plugin_header_version(), $this->version_constant() );
	}

	public function test_compatibility_doc_matches_the_plugin_header(): void {
		$this->assertSame( $this->plugin_header_version(), $this->compatibility_doc_version() );
	}

	public function test_readme_txt_stable_tag_matches_the_plugin_header(): void {
		$this->assertSame( $this->plugin_header_version(), $this->readme_txt_stable_tag() );
	}

	public function test_readme_txt_requires_at_least_matches_the_plugin_header(): void {
		$plugin_header = (string) file_get_contents( $this->root() . '/universal-geo-context.php' );
		$readme        = (string) file_get_contents( $this->root() . '/readme.txt' );

		preg_match( '/^\s*\*\s*Requires at least:\s*([0-9.]+)/m', $plugin_header, $header_match );
		preg_match( '/^Requires at least:\s*([0-9.]+)/m', $readme, $readme_match );

		$this->assertNotSame( '', $header_match[1] ?? '' );
		$this->assertSame( $header_match[1] ?? '', $readme_match[1] ?? '' );
	}

	public function test_readme_txt_requires_php_matches_the_plugin_header(): void {
		$plugin_header = (string) file_get_contents( $this->root() . '/universal-geo-context.php' );
		$readme        = (string) file_get_contents( $this->root() . '/readme.txt' );

		preg_match( '/^\s*\*\s*Requires PHP:\s*([0-9.]+)/m', $plugin_header, $header_match );
		preg_match( '/^Requires PHP:\s*([0-9.]+)/m', $readme, $readme_match );

		$this->assertNotSame( '', $header_match[1] ?? '' );
		$this->assertSame( $header_match[1] ?? '', $readme_match[1] ?? '' );
	}
}
