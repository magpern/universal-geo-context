<?php
/**
 * Unit tests for UniversalGeo\Http\TrustedProxies.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Http\TrustedProxies;

/**
 * Covers the effective trusted set: configured-CIDR matching, the
 * Cloudflare preset merge, is_cloudflare()'s unconditional-of-preset
 * behaviour, and is_empty(). No header reading or trust-gate algorithm
 * belongs here — that is ClientIpResolver's contract.
 */
final class TrustedProxiesTest extends TestCase {

	// ---- Class shape ----------------------------------------------------------

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( TrustedProxies::class ) )->isFinal() );
	}

	// ---- contains(): configured CIDRs ------------------------------------------

	public function test_contains_matches_a_configured_cidr(): void {
		$trusted = new TrustedProxies( array( '172.18.0.0/16' ), false );
		$this->assertTrue( $trusted->contains( '172.18.0.5' ) );
	}

	public function test_contains_rejects_an_address_outside_every_configured_cidr(): void {
		$trusted = new TrustedProxies( array( '172.18.0.0/16' ), false );
		$this->assertFalse( $trusted->contains( '203.0.113.1' ) );
	}

	public function test_contains_matches_a_bare_configured_ip(): void {
		$trusted = new TrustedProxies( array( '127.0.0.1' ), false );
		$this->assertTrue( $trusted->contains( '127.0.0.1' ) );
		$this->assertFalse( $trusted->contains( '127.0.0.2' ) );
	}

	public function test_contains_is_false_with_no_configuration_at_all(): void {
		$trusted = new TrustedProxies( array(), false );
		$this->assertFalse( $trusted->contains( '203.0.113.1' ) );
	}

	public function test_contains_checks_every_configured_cidr(): void {
		$trusted = new TrustedProxies( array( '10.0.0.0/8', '172.18.0.0/16' ), false );
		$this->assertTrue( $trusted->contains( '10.1.2.3' ) );
		$this->assertTrue( $trusted->contains( '172.18.0.9' ) );
		$this->assertFalse( $trusted->contains( '198.51.100.1' ) );
	}

	// ---- contains(): Cloudflare preset ------------------------------------------

	public function test_contains_does_not_match_cloudflare_ranges_when_preset_is_off(): void {
		$trusted = new TrustedProxies( array(), false );
		// A real Cloudflare-owned address (173.245.48.0/20).
		$this->assertFalse( $trusted->contains( '173.245.48.1' ) );
	}

	public function test_contains_matches_cloudflare_ranges_when_preset_is_on(): void {
		$trusted = new TrustedProxies( array(), true );
		$this->assertTrue( $trusted->contains( '173.245.48.1' ) );
	}

	public function test_contains_unions_configured_cidrs_and_cloudflare_preset(): void {
		$trusted = new TrustedProxies( array( '172.18.0.0/16' ), true );
		$this->assertTrue( $trusted->contains( '172.18.0.5' ) );
		$this->assertTrue( $trusted->contains( '173.245.48.1' ) );
		$this->assertFalse( $trusted->contains( '203.0.113.1' ) );
	}

	// ---- is_cloudflare(): unconditional on the preset ---------------------------

	public function test_is_cloudflare_matches_regardless_of_preset_state(): void {
		$preset_off = new TrustedProxies( array(), false );
		$preset_on  = new TrustedProxies( array(), true );

		$this->assertTrue( $preset_off->is_cloudflare( '173.245.48.1' ) );
		$this->assertTrue( $preset_on->is_cloudflare( '173.245.48.1' ) );
	}

	public function test_is_cloudflare_rejects_a_non_cloudflare_address(): void {
		$trusted = new TrustedProxies( array(), true );
		$this->assertFalse( $trusted->is_cloudflare( '203.0.113.1' ) );
	}

	public function test_is_cloudflare_matches_an_ipv6_range(): void {
		$trusted = new TrustedProxies( array(), false );
		$this->assertTrue( $trusted->is_cloudflare( '2606:4700::1' ) );
	}

	public function test_is_cloudflare_ignores_configured_cidrs(): void {
		// A configured CIDR that happens to be trusted must not make
		// is_cloudflare() report true for an address outside CF's own ranges.
		$trusted = new TrustedProxies( array( '203.0.113.0/24' ), false );
		$this->assertFalse( $trusted->is_cloudflare( '203.0.113.1' ) );
	}

	// ---- trusts_cloudflare() ----------------------------------------------------

	public function test_trusts_cloudflare_reflects_the_constructor_flag(): void {
		$this->assertTrue( ( new TrustedProxies( array(), true ) )->trusts_cloudflare() );
		$this->assertFalse( ( new TrustedProxies( array(), false ) )->trusts_cloudflare() );
	}

	// ---- matched_entry() ------------------------------------------------------------

	public function test_matched_entry_returns_the_matching_configured_cidr(): void {
		$trusted = new TrustedProxies( array( '10.0.0.0/8', '172.18.0.0/16' ), false );
		$this->assertSame( '172.18.0.0/16', $trusted->matched_entry( '172.18.0.5' ) );
	}

	public function test_matched_entry_returns_the_first_matching_entry_in_order(): void {
		$trusted = new TrustedProxies( array( '172.18.0.0/16', '172.18.0.0/24' ), false );
		$this->assertSame( '172.18.0.0/16', $trusted->matched_entry( '172.18.0.5' ) );
	}

	public function test_matched_entry_is_null_when_nothing_matches(): void {
		$trusted = new TrustedProxies( array( '10.0.0.0/8' ), false );
		$this->assertNull( $trusted->matched_entry( '203.0.113.1' ) );
	}

	public function test_matched_entry_never_reports_a_cloudflare_range_as_configured(): void {
		$trusted = new TrustedProxies( array(), true );
		$this->assertNull( $trusted->matched_entry( '173.245.48.1' ) );
	}

	// ---- configured_count() ----------------------------------------------------------

	public function test_configured_count_is_zero_with_nothing_configured(): void {
		$this->assertSame( 0, ( new TrustedProxies( array(), false ) )->configured_count() );
	}

	public function test_configured_count_reflects_the_configured_cidrs(): void {
		$this->assertSame( 2, ( new TrustedProxies( array( '10.0.0.0/8', '172.18.0.0/16' ), true ) )->configured_count() );
	}

	public function test_configured_count_ignores_the_cloudflare_preset(): void {
		$this->assertSame( 0, ( new TrustedProxies( array(), true ) )->configured_count() );
	}

	// ---- is_empty() ---------------------------------------------------------------

	public function test_is_empty_is_true_with_nothing_configured(): void {
		$this->assertTrue( ( new TrustedProxies( array(), false ) )->is_empty() );
	}

	public function test_is_empty_is_false_with_a_configured_cidr(): void {
		$this->assertFalse( ( new TrustedProxies( array( '172.18.0.0/16' ), false ) )->is_empty() );
	}

	public function test_is_empty_is_false_with_the_cloudflare_preset_alone(): void {
		$this->assertFalse( ( new TrustedProxies( array(), true ) )->is_empty() );
	}

	// ---- Bundled Cloudflare range constants --------------------------------------

	public function test_cloudflare_ranges_constant_is_non_empty(): void {
		$this->assertNotEmpty( TrustedProxies::CLOUDFLARE_RANGES );
	}

	public function test_cloudflare_ranges_date_is_an_iso_date(): void {
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', TrustedProxies::CLOUDFLARE_RANGES_DATE );
	}
}
