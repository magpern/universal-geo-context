<?php
/**
 * Unit tests for UniversalGeo\Http\IpUtils.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Http\IpUtils;

/**
 * Covers normalize(), is_public(), and mask() — the full Revision 3
 * IpUtils contract required by the client-IP model as of M1 Step 1D.
 * cidr_match() and describe() are not implemented (trusted-proxy /
 * diagnostics territory, later milestones) and are not tested here.
 */
final class IpUtilsTest extends TestCase {

	// ---- normalize() -------------------------------------------------

	public function test_normalize_plain_ipv4(): void {
		$this->assertSame( '203.0.113.5', IpUtils::normalize( '203.0.113.5' ) );
	}

	public function test_normalize_plain_ipv6(): void {
		$this->assertSame( '2001:db8::1', IpUtils::normalize( '2001:db8::1' ) );
	}

	public function test_normalize_trims_surrounding_whitespace(): void {
		$this->assertSame( '203.0.113.5', IpUtils::normalize( "  203.0.113.5\t\n" ) );
	}

	public function test_normalize_strips_ipv4_port(): void {
		$this->assertSame( '203.0.113.5', IpUtils::normalize( '203.0.113.5:8080' ) );
	}

	public function test_normalize_unwraps_bracketed_ipv6(): void {
		$this->assertSame( '2001:db8::1', IpUtils::normalize( '[2001:db8::1]' ) );
	}

	public function test_normalize_unwraps_bracketed_ipv6_with_port(): void {
		$this->assertSame( '2001:db8::1', IpUtils::normalize( '[2001:db8::1]:8080' ) );
	}

	public function test_normalize_reduces_ipv4_mapped_ipv6(): void {
		$this->assertSame( '192.0.2.10', IpUtils::normalize( '::ffff:192.0.2.10' ) );
	}

	public function test_normalize_reduces_ipv4_mapped_ipv6_case_insensitively(): void {
		$this->assertSame( '192.0.2.10', IpUtils::normalize( '::FFFF:192.0.2.10' ) );
	}

	public function test_normalize_preserves_ipv6_casing_verbatim(): void {
		// Revision 3's normalize() comment lists exactly three transforms
		// (port, brackets, ::ffff: mapping) — no general case-folding.
		$this->assertSame( '2001:DB8::1', IpUtils::normalize( '2001:DB8::1' ) );
	}

	/**
	 * @dataProvider malformed_normalize_provider
	 */
	public function test_normalize_returns_null_for( ?string $raw ): void {
		$this->assertNull( IpUtils::normalize( $raw ?? '' ) );
	}

	public function malformed_normalize_provider(): array {
		return array(
			'out of range octet' => array( '999.999.999.999' ),
			'too few octets'     => array( '1.2.3' ),
			'trailing garbage'   => array( '203.0.113.5abc' ),
			'invalid hex group'  => array( 'zzzz::1' ),
			'too many groups'    => array( '1:2:3:4:5:6:7:8:9' ),
			'hostname'           => array( 'example.com' ),
			'empty'              => array( '' ),
			'whitespace only'    => array( '   ' ),
		);
	}

	public function test_normalize_does_not_perform_dns_resolution(): void {
		// A hostname that would resolve on a live network must still be
		// rejected — normalize() must never touch the network.
		$this->assertNull( IpUtils::normalize( 'localhost' ) );
	}

	public function test_normalize_handles_one_token_only(): void {
		// A comma-separated list (X-Forwarded-For shape) is not this
		// method's job — the whole string fails as a single address.
		$this->assertNull( IpUtils::normalize( '203.0.113.5, 198.51.100.9' ) );
	}

	// ---- is_public(): IPv4 --------------------------------------------

	public function test_is_public_ordinary_public_ipv4(): void {
		// Google Public DNS — a well-known, independently-verifiable public address.
		$this->assertTrue( IpUtils::is_public( '8.8.8.8' ) );
	}

	public function test_is_public_rejects_rfc1918(): void {
		$this->assertFalse( IpUtils::is_public( '10.1.2.3' ) );
		$this->assertFalse( IpUtils::is_public( '172.16.0.1' ) );
		$this->assertFalse( IpUtils::is_public( '192.168.1.1' ) );
	}

	public function test_is_public_rejects_ipv4_loopback(): void {
		$this->assertFalse( IpUtils::is_public( '127.0.0.1' ) );
	}

	public function test_is_public_rejects_ipv4_link_local(): void {
		$this->assertFalse( IpUtils::is_public( '169.254.1.1' ) );
	}

	public function test_is_public_rejects_cgnat(): void {
		$this->assertFalse( IpUtils::is_public( '100.64.0.1' ) );
		$this->assertFalse( IpUtils::is_public( '100.127.255.254' ) );
	}

	public function test_is_public_rejects_ipv4_documentation_ranges(): void {
		$this->assertFalse( IpUtils::is_public( '192.0.2.1' ) );
		$this->assertFalse( IpUtils::is_public( '198.51.100.1' ) );
		$this->assertFalse( IpUtils::is_public( '203.0.113.1' ) );
	}

	public function test_is_public_rejects_ipv4_multicast(): void {
		$this->assertFalse( IpUtils::is_public( '224.0.0.1' ) );
	}

	public function test_is_public_rejects_ipv4_unspecified(): void {
		$this->assertFalse( IpUtils::is_public( '0.0.0.0' ) );
	}

	public function test_is_public_rejects_ipv4_reserved_future_use(): void {
		$this->assertFalse( IpUtils::is_public( '240.0.0.1' ) );
	}

	// ---- is_public(): IPv6 --------------------------------------------

	public function test_is_public_ordinary_public_ipv6(): void {
		// Google Public DNS IPv6 — well-known, independently verifiable.
		$this->assertTrue( IpUtils::is_public( '2001:4860:4860::8888' ) );
	}

	public function test_is_public_rejects_ipv6_loopback(): void {
		$this->assertFalse( IpUtils::is_public( '::1' ) );
	}

	public function test_is_public_rejects_ipv6_unspecified(): void {
		$this->assertFalse( IpUtils::is_public( '::' ) );
	}

	public function test_is_public_rejects_ipv6_link_local(): void {
		$this->assertFalse( IpUtils::is_public( 'fe80::1' ) );
	}

	public function test_is_public_rejects_ipv6_ula(): void {
		$this->assertFalse( IpUtils::is_public( 'fc00::1' ) );
		$this->assertFalse( IpUtils::is_public( 'fd12:3456:789a::1' ) );
	}

	public function test_is_public_rejects_ipv6_multicast(): void {
		$this->assertFalse( IpUtils::is_public( 'ff02::1' ) );
	}

	public function test_is_public_rejects_ipv6_documentation(): void {
		$this->assertFalse( IpUtils::is_public( '2001:db8::1' ) );
	}

	// ---- is_public(): IPv4-mapped IPv6 --------------------------------

	public function test_is_public_ipv4_mapped_public_address(): void {
		$this->assertTrue( IpUtils::is_public( '::ffff:8.8.8.8' ) );
	}

	public function test_is_public_ipv4_mapped_non_public_address(): void {
		$this->assertFalse( IpUtils::is_public( '::ffff:10.0.0.5' ) );
	}

	// ---- is_public(): malformed input ----------------------------------

	public function test_is_public_rejects_malformed_input(): void {
		$this->assertFalse( IpUtils::is_public( 'not-an-ip' ) );
	}

	// ---- mask() ---------------------------------------------------------

	public function test_mask_ipv4_replaces_last_octet(): void {
		$this->assertSame( '203.0.113.x', IpUtils::mask( '203.0.113.55' ) );
	}

	public function test_mask_ipv6_keeps_first_three_groups(): void {
		$this->assertSame( '2001:db8:1234:…', IpUtils::mask( '2001:db8:1234:5678::1' ) );
	}

	public function test_mask_is_deterministic(): void {
		$this->assertSame( IpUtils::mask( '203.0.113.55' ), IpUtils::mask( '203.0.113.55' ) );
		$this->assertSame( IpUtils::mask( '2001:db8:1234:5678::1' ), IpUtils::mask( '2001:db8:1234:5678::1' ) );
	}

	public function test_mask_ipv4_does_not_leak_the_full_address(): void {
		$this->assertStringNotContainsString( '55', IpUtils::mask( '203.0.113.55' ) );
	}

	public function test_mask_ipv6_does_not_leak_the_full_address(): void {
		$masked = IpUtils::mask( '2001:db8:1234:5678::1' );
		$this->assertStringNotContainsString( '5678', $masked );
		$this->assertStringNotContainsString( '::1', $masked );
	}

	public function test_mask_returns_invalid_marker_for_malformed_input(): void {
		$this->assertSame( 'invalid', IpUtils::mask( 'not-an-ip' ) );
	}

	// ---- Class shape ------------------------------------------------------

	public function test_class_is_final(): void {
		$reflection = new ReflectionClass( IpUtils::class );
		$this->assertTrue( $reflection->isFinal() );
	}

	public function test_public_api_is_exactly_normalize_is_public_and_mask(): void {
		$reflection = new ReflectionClass( IpUtils::class );
		$public     = array();

		foreach ( $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			$public[] = $method->getName();
		}

		sort( $public );
		$this->assertSame( array( 'is_public', 'mask', 'normalize' ), $public );
	}
}
