<?php
/**
 * Unit tests for UniversalGeo\Http\ClientIpResolver — the spoofing matrix.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\Tests\Support\ServerRequestFactory;

/**
 * Covers Revision 3 §6's complete trust-gate algorithm: the fail-closed
 * default, peer verification, fixed header precedence, the right-to-left
 * X-Forwarded-For walk, both Cloudflare modes, IPv4-mapped peer matching,
 * public/private classification, and the universal_geo_trusted_proxies
 * filter. Row numbers in test names/comments refer to Revision 3 §16's
 * 15-row spoofing matrix table.
 */
final class ClientIpResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['universal_geo_test_filters'] = array();
	}

	private function trusted( array $cidrs = array(), bool $trust_cloudflare = false ): TrustedProxies {
		return new TrustedProxies( $cidrs, $trust_cloudflare );
	}

	// ---- Row 1: no trusted proxies at all ---------------------------------------

	public function test_row1_no_trusted_proxies_ignores_x_forwarded_for(): void {
		$request  = ServerRequestFactory::make( '203.0.113.1', array( 'X-Forwarded-For' => '1.2.3.4' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted() );

		$result = $resolver->resolve();

		$this->assertSame( '203.0.113.1', $result->ip );
		$this->assertSame( 'REMOTE_ADDR', $result->header );
	}

	public function test_row1_no_trusted_proxies_ignores_every_forwarding_header(): void {
		$request  = ServerRequestFactory::make(
			'203.0.113.1',
			array(
				'CF-Connecting-IP' => '1.2.3.4',
				'X-Forwarded-For'  => '1.2.3.4',
				'X-Real-IP'        => '1.2.3.4',
			)
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array(), true ) );

		// Cloudflare preset alone, with no configured CIDR and no matching
		// peer, still leaves the effective set governed by peer trust below.
		$result = $resolver->resolve();
		$this->assertSame( 'REMOTE_ADDR', $result->header );
	}

	public function test_row1_chain_verified_true_when_no_trust_is_configured(): void {
		// No trusted proxies configured at all is the shipped default: the
		// peer (REMOTE_ADDR itself) is trivially "the answer", so
		// chain_verified is true — there is no chain to fail verifying.
		$request  = ServerRequestFactory::make( '203.0.113.1' );
		$resolver = new ClientIpResolver( $request, $this->trusted() );

		$this->assertTrue( $resolver->resolve()->chain_verified );
	}

	// ---- Row 2: peer untrusted -----------------------------------------------------

	public function test_row2_untrusted_peer_with_forwarding_header_falls_back_to_remote_addr(): void {
		$request  = ServerRequestFactory::make( '198.51.100.9', array( 'X-Forwarded-For' => '1.2.3.4' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$result = $resolver->resolve();

		$this->assertSame( '198.51.100.9', $result->ip );
		$this->assertSame( 'REMOTE_ADDR', $result->header );
	}

	public function test_row2_untrusted_peer_chain_verified_is_false(): void {
		$request  = ServerRequestFactory::make( '198.51.100.9', array( 'X-Real-IP' => '1.2.3.4' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$this->assertFalse( $resolver->resolve()->chain_verified );
	}

	// ---- Row 3: XFF with intermediate proxies, both trusted ------------------------

	public function test_row3_xff_client_and_two_trusted_proxies_resolves_the_client(): void {
		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array( 'X-Forwarded-For' => '198.51.100.1, 172.18.0.9, 172.18.0.5' )
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$result = $resolver->resolve();

		$this->assertSame( '198.51.100.1', $result->ip );
		$this->assertSame( 'X-Forwarded-For', $result->header );
		$this->assertTrue( $result->chain_verified );
	}

	// ---- Row 4: every XFF entry trusted → leftmost entry ---------------------------

	public function test_row4_all_xff_entries_trusted_returns_the_leftmost_entry(): void {
		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array( 'X-Forwarded-For' => '172.18.0.1, 172.18.0.9, 172.18.0.5' )
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$this->assertSame( '172.18.0.1', $resolver->resolve()->ip );
	}

	// ---- Row 5: the walk stops at the first untrusted entry, right-to-left ---------

	public function test_row5_walk_stops_at_the_rightmost_untrusted_entry_not_the_leftmost(): void {
		// '1.2.3.4' is leftmost, attacker-controlled, and must NOT be picked
		// blindly (unlike WooCommerce's left-to-right reader). '6.6.6.6'
		// represents the attacker-supplied, untrusted entry the walk must
		// land on; '172.18.0.5' is the one genuinely trusted hop.
		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array( 'X-Forwarded-For' => '1.2.3.4, 6.6.6.6, 172.18.0.5' )
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$this->assertSame( '6.6.6.6', $resolver->resolve()->ip );
	}

	// ---- Row 6: > 20 entries rejects the whole header ------------------------------

	public function test_row6_more_than_twenty_xff_entries_rejects_the_header(): void {
		$entries  = array_merge(
			array_fill( 0, 21, '198.51.100.1' ),
		);
		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array(
				'X-Forwarded-For' => implode( ', ', $entries ),
				'X-Real-IP'       => '198.51.100.50',
			)
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$result = $resolver->resolve();

		// Falls through to X-Real-IP, not the (rejected) XFF header.
		$this->assertSame( '198.51.100.50', $result->ip );
		$this->assertSame( 'X-Real-IP', $result->header );
	}

	// ---- Row 7: unparseable XFF entries reject the header --------------------------

	public function test_row7_xff_value_unknown_rejects_the_header(): void {
		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array(
				'X-Forwarded-For' => 'unknown',
				'X-Real-IP'       => '198.51.100.50',
			)
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$this->assertSame( '198.51.100.50', $resolver->resolve()->ip );
	}

	public function test_row7_empty_xff_header_is_treated_as_absent(): void {
		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array(
				'X-Forwarded-For' => '',
				'X-Real-IP'       => '198.51.100.50',
			)
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$this->assertSame( '198.51.100.50', $resolver->resolve()->ip );
	}

	public function test_row7_whitespace_only_xff_header_is_treated_as_absent(): void {
		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array(
				'X-Forwarded-For' => '   ',
				'X-Real-IP'       => '198.51.100.50',
			)
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$this->assertSame( '198.51.100.50', $resolver->resolve()->ip );
	}

	// ---- Row 8: CF preset on, peer neither CF nor trusted ---------------------------

	public function test_row8_cf_preset_on_but_peer_untrusted_ignores_cf_header(): void {
		$request  = ServerRequestFactory::make( '198.51.100.9', array( 'CF-Connecting-IP' => '1.2.3.4' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array(), true ) );

		$result = $resolver->resolve();

		$this->assertSame( '198.51.100.9', $result->ip );
		$this->assertSame( 'REMOTE_ADDR', $result->header );
		$this->assertFalse( $result->chain_verified );
	}

	// ---- Row 9: CF preset on, peer IS a Cloudflare address (direct mode) -----------

	public function test_row9_direct_mode_cf_connecting_ip_wins_over_xff(): void {
		$request  = ServerRequestFactory::make(
			'173.245.48.1',
			array(
				'CF-Connecting-IP' => '198.51.100.7',
				'X-Forwarded-For'  => '198.51.100.99',
			)
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array(), true ) );

		$result = $resolver->resolve();

		$this->assertSame( '198.51.100.7', $result->ip );
		$this->assertSame( 'CF-Connecting-IP', $result->header );
	}

	// ---- Row 10: only X-Real-IP present (chained reverse-proxy case) ----------------

	public function test_row10_only_x_real_ip_present_is_used(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '198.51.100.2' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$result = $resolver->resolve();

		$this->assertSame( '198.51.100.2', $result->ip );
		$this->assertSame( 'X-Real-IP', $result->header );
	}

	// ---- Row 11: IPv4-mapped peer matches a plain IPv4 trusted CIDR -----------------

	public function test_row11_ipv4_mapped_peer_matches_configured_ipv4_cidr(): void {
		$request  = ServerRequestFactory::make( '::ffff:172.18.0.5', array( 'X-Real-IP' => '198.51.100.2' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$result = $resolver->resolve();

		$this->assertSame( '198.51.100.2', $result->ip );
		$this->assertTrue( $result->chain_verified );
	}

	// ---- Row 12: resolved client is private → is_public false -----------------------

	public function test_row12_private_resolved_client_reports_is_public_false(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '172.18.0.7' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$result = $resolver->resolve();

		$this->assertSame( '172.18.0.7', $result->ip );
		$this->assertFalse( $result->is_public );
	}

	public function test_public_resolved_client_reports_is_public_true(): void {
		// 198.51.100.0/24 (used elsewhere in this file as arbitrary header
		// fixture data) is itself an RFC 5737 documentation range, i.e. NOT
		// public — a genuinely public address (Google Public DNS) is needed
		// here specifically to exercise is_public() truthfully.
		$request  = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '8.8.8.8' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$this->assertTrue( $resolver->resolve()->is_public );
	}

	// ---- Row 13: no REMOTE_ADDR at all (CLI/cron) ------------------------------------

	public function test_row13_missing_remote_addr_returns_null(): void {
		$request  = ServerRequestFactory::make( null );
		$resolver = new ClientIpResolver( $request, $this->trusted() );

		$this->assertNull( $resolver->resolve() );
	}

	// ---- Row 14: CF preset on, peer trusted but not itself Cloudflare (chained) ------

	public function test_row14_chained_mode_cf_connecting_ip_is_accepted(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5', array( 'CF-Connecting-IP' => '198.51.100.7' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ), true ) );

		$result = $resolver->resolve();

		$this->assertSame( '198.51.100.7', $result->ip );
		$this->assertSame( 'CF-Connecting-IP', $result->header );
	}

	// ---- Row 15: CF preset off, peer trusted, CF header present but ignored ---------

	public function test_row15_cf_preset_off_ignores_cf_header_but_xff_still_applies(): void {
		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array(
				'CF-Connecting-IP' => '198.51.100.7',
				'X-Forwarded-For'  => '198.51.100.99',
			)
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ), false ) );

		$result = $resolver->resolve();

		$this->assertSame( '198.51.100.99', $result->ip );
		$this->assertSame( 'X-Forwarded-For', $result->header );
	}

	public function test_row15_cf_preset_off_ignores_cf_header_and_x_real_ip_still_applies(): void {
		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array(
				'CF-Connecting-IP' => '198.51.100.7',
				'X-Real-IP'        => '198.51.100.50',
			)
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ), false ) );

		$result = $resolver->resolve();

		$this->assertSame( '198.51.100.50', $result->ip );
		$this->assertSame( 'X-Real-IP', $result->header );
	}

	// ---- Fallback when peer is trusted but no header yields anything ---------------

	public function test_trusted_peer_with_no_headers_falls_back_to_peer(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5' );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$result = $resolver->resolve();

		$this->assertSame( '172.18.0.5', $result->ip );
		$this->assertSame( 'REMOTE_ADDR', $result->header );
		$this->assertTrue( $result->chain_verified );
	}

	// ---- cloudflare_header_trusted() -------------------------------------------------

	public function test_cloudflare_header_trusted_true_in_direct_mode(): void {
		$request  = ServerRequestFactory::make( '173.245.48.1' );
		$resolver = new ClientIpResolver( $request, $this->trusted( array(), true ) );

		$this->assertTrue( $resolver->cloudflare_header_trusted() );
	}

	public function test_cloudflare_header_trusted_true_in_chained_mode(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5' );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ), true ) );

		$this->assertTrue( $resolver->cloudflare_header_trusted() );
	}

	public function test_cloudflare_header_trusted_false_when_preset_off(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5' );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ), false ) );

		$this->assertFalse( $resolver->cloudflare_header_trusted() );
	}

	public function test_cloudflare_header_trusted_false_when_peer_untrusted(): void {
		$request  = ServerRequestFactory::make( '198.51.100.9' );
		$resolver = new ClientIpResolver( $request, $this->trusted( array(), true ) );

		$this->assertFalse( $resolver->cloudflare_header_trusted() );
	}

	public function test_cloudflare_header_trusted_false_with_no_remote_addr(): void {
		$request  = ServerRequestFactory::make( null );
		$resolver = new ClientIpResolver( $request, $this->trusted( array(), true ) );

		$this->assertFalse( $resolver->cloudflare_header_trusted() );
	}

	// ---- universal_geo_trusted_proxies filter (additive union) -----------------------

	public function test_filter_added_cidr_extends_trust(): void {
		add_filter( 'universal_geo_trusted_proxies', static fn() => array( '198.51.100.0/24' ) );

		$request  = ServerRequestFactory::make( '198.51.100.9', array( 'X-Real-IP' => '203.0.113.5' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted() );

		$result = $resolver->resolve();

		$this->assertSame( '203.0.113.5', $result->ip );
		$this->assertTrue( $result->chain_verified );
	}

	public function test_filter_cannot_shrink_the_admins_own_configuration(): void {
		// The filter returning an empty array must not un-trust an
		// admin-configured CIDR — filter_cidrs() is unioned with
		// TrustedProxies, never substituted for it.
		add_filter( 'universal_geo_trusted_proxies', static fn() => array() );

		$request  = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '203.0.113.5' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$this->assertTrue( $resolver->resolve()->chain_verified );
	}

	public function test_filter_is_fetched_at_most_once_per_instance(): void {
		$calls = 0;
		add_filter(
			'universal_geo_trusted_proxies',
			static function ( $cidrs ) use ( &$calls ) {
				++$calls;
				return $cidrs;
			}
		);

		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array( 'X-Forwarded-For' => '198.51.100.1, 172.18.0.5' )
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$resolver->resolve();
		$resolver->cloudflare_header_trusted();
		$resolver->explain();

		$this->assertSame( 1, $calls );
	}

	public function test_non_array_filter_result_is_treated_as_empty(): void {
		// A non-empty admin configuration that does NOT cover this peer
		// isolates the claim: the filter's garbage return must contribute
		// nothing (not even accidentally granting trust), so the peer stays
		// untrusted exactly as it would with no filter registered at all.
		add_filter( 'universal_geo_trusted_proxies', static fn() => 'not-an-array' );

		$request  = ServerRequestFactory::make( '198.51.100.9', array( 'X-Real-IP' => '203.0.113.5' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$this->assertFalse( $resolver->resolve()->chain_verified );
	}

	// ---- explain() ----------------------------------------------------------------

	public function test_explain_reports_no_trusted_proxies_configured(): void {
		$request  = ServerRequestFactory::make( '203.0.113.1', array( 'X-Real-IP' => '198.51.100.2' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted() );

		$rows        = $resolver->explain();
		$real_ip_row = array_values( array_filter( $rows, static fn( $row ) => 'X-Real-IP' === $row['header'] ) )[0];

		$this->assertFalse( $real_ip_row['trusted'] );
		$this->assertSame( 'no_trusted_proxies_configured', $real_ip_row['reason'] );
	}

	public function test_explain_reports_peer_not_trusted(): void {
		$request  = ServerRequestFactory::make( '198.51.100.9', array( 'X-Real-IP' => '198.51.100.2' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$rows        = $resolver->explain();
		$real_ip_row = array_values( array_filter( $rows, static fn( $row ) => 'X-Real-IP' === $row['header'] ) )[0];

		$this->assertFalse( $real_ip_row['trusted'] );
		$this->assertSame( 'peer_not_trusted', $real_ip_row['reason'] );
	}

	public function test_explain_reports_cloudflare_preset_disabled(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5', array( 'CF-Connecting-IP' => '198.51.100.7' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ), false ) );

		$rows   = $resolver->explain();
		$cf_row = array_values( array_filter( $rows, static fn( $row ) => 'CF-Connecting-IP' === $row['header'] ) )[0];

		$this->assertFalse( $cf_row['trusted'] );
		$this->assertSame( 'cloudflare_preset_disabled', $cf_row['reason'] );
		$this->assertTrue( $cf_row['present'] );
	}

	public function test_explain_reports_peer_trusted_for_x_real_ip(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '198.51.100.2' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$rows        = $resolver->explain();
		$real_ip_row = array_values( array_filter( $rows, static fn( $row ) => 'X-Real-IP' === $row['header'] ) )[0];

		$this->assertTrue( $real_ip_row['trusted'] );
		$this->assertSame( 'peer_trusted', $real_ip_row['reason'] );
		$this->assertTrue( $real_ip_row['present'] );
	}

	public function test_explain_reports_absent_header_as_not_present(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5' );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$rows    = $resolver->explain();
		$xff_row = array_values( array_filter( $rows, static fn( $row ) => 'X-Forwarded-For' === $row['header'] ) )[0];

		$this->assertFalse( $xff_row['present'] );
		$this->assertNull( $xff_row['masked_value'] );
	}

	public function test_explain_masks_the_x_real_ip_value(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => '198.51.100.2' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$rows        = $resolver->explain();
		$real_ip_row = array_values( array_filter( $rows, static fn( $row ) => 'X-Real-IP' === $row['header'] ) )[0];

		$this->assertSame( '198.51.100.x', $real_ip_row['masked_value'] );
	}

	public function test_explain_masks_every_x_forwarded_for_entry(): void {
		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array( 'X-Forwarded-For' => '198.51.100.1, 172.18.0.5' )
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$rows    = $resolver->explain();
		$xff_row = array_values( array_filter( $rows, static fn( $row ) => 'X-Forwarded-For' === $row['header'] ) )[0];

		$this->assertSame( '198.51.100.x, 172.18.0.x', $xff_row['masked_value'] );
	}

	public function test_explain_never_contains_a_raw_ip_value(): void {
		$request  = ServerRequestFactory::make(
			'172.18.0.5',
			array(
				'CF-Connecting-IP' => '198.51.100.7',
				'X-Forwarded-For'  => '198.51.100.1, 172.18.0.5',
				'X-Real-IP'        => '198.51.100.2',
			)
		);
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ), true ) );

		foreach ( $resolver->explain() as $row ) {
			$this->assertStringNotContainsString( '198.51.100.7', (string) $row['masked_value'] );
			$this->assertStringNotContainsString( '198.51.100.1', (string) $row['masked_value'] );
			$this->assertStringNotContainsString( '198.51.100.2', (string) $row['masked_value'] );
		}
	}

	public function test_explain_reports_invalid_marker_for_an_unparseable_header_value(): void {
		$request  = ServerRequestFactory::make( '172.18.0.5', array( 'X-Real-IP' => 'not-an-ip' ) );
		$resolver = new ClientIpResolver( $request, $this->trusted( array( '172.18.0.0/16' ) ) );

		$rows        = $resolver->explain();
		$real_ip_row = array_values( array_filter( $rows, static fn( $row ) => 'X-Real-IP' === $row['header'] ) )[0];

		$this->assertSame( 'invalid', $real_ip_row['masked_value'] );
	}
}
