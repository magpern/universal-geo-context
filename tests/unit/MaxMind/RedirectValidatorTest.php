<?php
/**
 * Unit tests for UniversalGeo\MaxMind\RedirectValidator.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\MaxMind;

use PHPUnit\Framework\TestCase;
use UniversalGeo\MaxMind\RedirectValidator;

/**
 * Covers the redirect-safety gate the M6 download flow depends on: https-only,
 * userinfo rejection, and the exact-suffix (never substring/prefix) host
 * allowlist match.
 */
final class RedirectValidatorTest extends TestCase {

	public function test_accepts_a_valid_r2_url(): void {
		$result = RedirectValidator::validate( 'https://abc123.r2.cloudflarestorage.com/geolite2/GeoLite2-Country.tar.gz?sig=abc' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'https://abc123.r2.cloudflarestorage.com/geolite2/GeoLite2-Country.tar.gz?sig=abc', $result['url'] );
		$this->assertNull( $result['reason'] );
	}

	public function test_accepts_the_bare_allowed_host_with_no_subdomain(): void {
		$result = RedirectValidator::validate( 'https://r2.cloudflarestorage.com/x' );

		$this->assertTrue( $result['ok'] );
	}

	public function test_rejects_empty_string(): void {
		$result = RedirectValidator::validate( '' );

		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['url'] );
		$this->assertSame( 'empty', $result['reason'] );
	}

	public function test_rejects_whitespace_only(): void {
		$result = RedirectValidator::validate( '   ' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'empty', $result['reason'] );
	}

	public function test_rejects_a_relative_path(): void {
		$result = RedirectValidator::validate( '/some/path' );

		$this->assertFalse( $result['ok'] );
	}

	public function test_rejects_http_scheme(): void {
		$result = RedirectValidator::validate( 'http://r2.cloudflarestorage.com/x' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_https', $result['reason'] );
	}

	public function test_rejects_ftp_scheme(): void {
		$result = RedirectValidator::validate( 'ftp://r2.cloudflarestorage.com/x' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_https', $result['reason'] );
	}

	public function test_rejects_userinfo_in_the_url(): void {
		$result = RedirectValidator::validate( 'https://user:pass@r2.cloudflarestorage.com/x' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'userinfo_present', $result['reason'] );
	}

	public function test_rejects_userinfo_with_only_a_user_component(): void {
		$result = RedirectValidator::validate( 'https://attacker@r2.cloudflarestorage.com/x' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'userinfo_present', $result['reason'] );
	}

	public function test_rejects_an_unlisted_host(): void {
		$result = RedirectValidator::validate( 'https://evil.example.com/x' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'host_not_allowed', $result['reason'] );
	}

	public function test_rejects_a_host_that_merely_contains_the_allowed_suffix(): void {
		// "r2.cloudflarestorage.com.evil.com" must not pass a naive
		// substring/str_contains check.
		$result = RedirectValidator::validate( 'https://r2.cloudflarestorage.com.evil.com/x' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'host_not_allowed', $result['reason'] );
	}

	public function test_rejects_a_host_that_merely_prefix_matches_the_allowed_suffix(): void {
		// "evilr2.cloudflarestorage.com" must not pass a naive
		// str_ends_with($host, $suffix) check without the leading dot.
		$result = RedirectValidator::validate( 'https://evilr2.cloudflarestorage.com/x' );

		$this->assertFalse( $result['ok'] );
	}

	public function test_accepts_a_genuine_subdomain_of_the_allowed_suffix(): void {
		$result = RedirectValidator::validate( 'https://nested.abc123.r2.cloudflarestorage.com/x' );

		$this->assertTrue( $result['ok'] );
	}

	public function test_rejects_a_missing_host(): void {
		$result = RedirectValidator::validate( 'https:///path-only' );

		$this->assertFalse( $result['ok'] );
	}

	public function test_rejection_reason_never_contains_the_raw_url(): void {
		$malicious_url = 'https://evil.example.com/secret-path?token=abc123';
		$result        = RedirectValidator::validate( $malicious_url );

		$this->assertFalse( $result['ok'] );
		$this->assertStringNotContainsString( 'evil.example.com', (string) $result['reason'] );
		$this->assertStringNotContainsString( 'secret-path', (string) $result['reason'] );
		$this->assertStringNotContainsString( 'token', (string) $result['reason'] );
	}

	public function test_host_matching_is_case_insensitive(): void {
		$result = RedirectValidator::validate( 'https://ABC123.R2.CLOUDFLARESTORAGE.COM/x' );

		$this->assertTrue( $result['ok'] );
	}

	public function test_scheme_matching_is_case_insensitive(): void {
		$result = RedirectValidator::validate( 'HTTPS://r2.cloudflarestorage.com/x' );

		$this->assertTrue( $result['ok'] );
	}
}
