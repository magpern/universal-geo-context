<?php
/**
 * Unit tests for UniversalGeo\Http\RemoteAddrOnlyResolver.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Http\RemoteAddrOnlyResolver;
use UniversalGeo\Model\ResolvedClientIp;

/**
 * Covers the M1 Step 1D baseline resolver: REMOTE_ADDR only, delegating all
 * normalization and public/private classification to IpUtils, every
 * forwarding/CDN header ignored, no trusted-proxy behaviour.
 */
final class RemoteAddrOnlyResolverTest extends TestCase {

	/**
	 * Every $_SERVER key any test in this file touches. Snapshotted before
	 * and restored after every test, whether it passes or fails.
	 *
	 * @var string[]
	 */
	private const TOUCHED_KEYS = array(
		'REMOTE_ADDR',
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
		'HTTP_FORWARDED',
		'HTTP_TRUE_CLIENT_IP',
		'HTTP_CLIENT_IP',
	);

	/**
	 * @var array<string, mixed>
	 */
	private array $server_snapshot = array();

	protected function setUp(): void {
		parent::setUp();

		$this->server_snapshot = array();

		foreach ( self::TOUCHED_KEYS as $key ) {
			if ( array_key_exists( $key, $_SERVER ) ) {
				$this->server_snapshot[ $key ] = $_SERVER[ $key ];
			}
			unset( $_SERVER[ $key ] );
		}
	}

	protected function tearDown(): void {
		foreach ( self::TOUCHED_KEYS as $key ) {
			unset( $_SERVER[ $key ] );

			if ( array_key_exists( $key, $this->server_snapshot ) ) {
				$_SERVER[ $key ] = $this->server_snapshot[ $key ];
			}
		}

		parent::tearDown();
	}

	public function test_valid_ipv4_remote_addr_resolves(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$resolved = ( new RemoteAddrOnlyResolver() )->resolve();

		$this->assertInstanceOf( ResolvedClientIp::class, $resolved );
		$this->assertSame( '203.0.113.5', $resolved->ip );
	}

	public function test_valid_ipv6_remote_addr_resolves(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';

		$resolved = ( new RemoteAddrOnlyResolver() )->resolve();

		$this->assertInstanceOf( ResolvedClientIp::class, $resolved );
		$this->assertSame( '2001:db8::1', $resolved->ip );
	}

	public function test_header_is_always_remote_addr(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$this->assertSame( 'REMOTE_ADDR', ( new RemoteAddrOnlyResolver() )->resolve()->header );
	}

	public function test_chain_verified_is_always_true(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$this->assertTrue( ( new RemoteAddrOnlyResolver() )->resolve()->chain_verified );
	}

	public function test_surrounding_whitespace_is_trimmed(): void {
		$_SERVER['REMOTE_ADDR'] = "  203.0.113.5\t\n";
		$this->assertSame( '203.0.113.5', ( new RemoteAddrOnlyResolver() )->resolve()->ip );
	}

	public function test_valid_address_is_preserved_verbatim_not_canonicalized(): void {
		// IpUtils::normalize() strips ports/brackets/::ffff: mapping only —
		// it does not otherwise reformat casing, so this must come back
		// exactly as given.
		$_SERVER['REMOTE_ADDR'] = '2001:DB8::1';
		$this->assertSame( '2001:DB8::1', ( new RemoteAddrOnlyResolver() )->resolve()->ip );
	}

	public function test_ipv4_with_port_is_stripped_and_resolves(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5:8080';
		$this->assertSame( '203.0.113.5', ( new RemoteAddrOnlyResolver() )->resolve()->ip );
	}

	public function test_bracketed_ipv6_is_unwrapped_and_resolves(): void {
		$_SERVER['REMOTE_ADDR'] = '[2001:db8::1]';
		$this->assertSame( '2001:db8::1', ( new RemoteAddrOnlyResolver() )->resolve()->ip );
	}

	public function test_bracketed_ipv6_with_port_is_unwrapped_and_resolves(): void {
		$_SERVER['REMOTE_ADDR'] = '[2001:db8::1]:8080';
		$this->assertSame( '2001:db8::1', ( new RemoteAddrOnlyResolver() )->resolve()->ip );
	}

	public function test_ipv4_mapped_ipv6_is_reduced_and_resolves(): void {
		$_SERVER['REMOTE_ADDR'] = '::ffff:192.0.2.10';
		$this->assertSame( '192.0.2.10', ( new RemoteAddrOnlyResolver() )->resolve()->ip );
	}

	public function test_missing_remote_addr_returns_null(): void {
		// setUp() already unset it.
		$this->assertNull( ( new RemoteAddrOnlyResolver() )->resolve() );
	}

	public function test_empty_remote_addr_returns_null(): void {
		$_SERVER['REMOTE_ADDR'] = '';
		$this->assertNull( ( new RemoteAddrOnlyResolver() )->resolve() );
	}

	public function test_whitespace_only_remote_addr_returns_null(): void {
		$_SERVER['REMOTE_ADDR'] = "   \t  ";
		$this->assertNull( ( new RemoteAddrOnlyResolver() )->resolve() );
	}

	/**
	 * @dataProvider malformed_ipv4_provider
	 */
	public function test_malformed_ipv4_returns_null( string $value ): void {
		$_SERVER['REMOTE_ADDR'] = $value;
		$this->assertNull( ( new RemoteAddrOnlyResolver() )->resolve() );
	}

	public function malformed_ipv4_provider(): array {
		return array(
			'out of range octet' => array( '999.999.999.999' ),
			'too few octets'     => array( '1.2.3' ),
			'trailing garbage'   => array( '203.0.113.5abc' ),
		);
	}

	/**
	 * @dataProvider malformed_ipv6_provider
	 */
	public function test_malformed_ipv6_returns_null( string $value ): void {
		$_SERVER['REMOTE_ADDR'] = $value;
		$this->assertNull( ( new RemoteAddrOnlyResolver() )->resolve() );
	}

	public function malformed_ipv6_provider(): array {
		return array(
			'invalid hex group' => array( 'zzzz::1' ),
			'too many groups'   => array( '1:2:3:4:5:6:7:8:9' ),
		);
	}

	/**
	 * @dataProvider non_string_provider
	 */
	public function test_non_string_remote_addr_returns_null( $value ): void {
		$_SERVER['REMOTE_ADDR'] = $value;
		$this->assertNull( ( new RemoteAddrOnlyResolver() )->resolve() );
	}

	public function non_string_provider(): array {
		return array(
			'integer' => array( 12345 ),
			'array'   => array( array( '203.0.113.5' ) ),
			'null'    => array( null ),
			'boolean' => array( true ),
		);
	}

	public function test_loopback_ipv4_is_not_rejected(): void {
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$resolved               = ( new RemoteAddrOnlyResolver() )->resolve();

		$this->assertInstanceOf( ResolvedClientIp::class, $resolved );
		$this->assertSame( '127.0.0.1', $resolved->ip );
	}

	public function test_loopback_ipv6_is_not_rejected(): void {
		$_SERVER['REMOTE_ADDR'] = '::1';
		$resolved               = ( new RemoteAddrOnlyResolver() )->resolve();

		$this->assertInstanceOf( ResolvedClientIp::class, $resolved );
		$this->assertSame( '::1', $resolved->ip );
	}

	public function test_private_rfc1918_address_is_not_rejected(): void {
		// This VPS's own reverse_proxy Docker bridge subnet shape.
		$_SERVER['REMOTE_ADDR'] = '172.18.0.7';
		$resolved               = ( new RemoteAddrOnlyResolver() )->resolve();

		$this->assertInstanceOf( ResolvedClientIp::class, $resolved );
		$this->assertSame( '172.18.0.7', $resolved->ip );
	}

	public function test_reserved_documentation_address_is_not_rejected(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$resolved               = ( new RemoteAddrOnlyResolver() )->resolve();

		$this->assertInstanceOf( ResolvedClientIp::class, $resolved );
		// Not rejected from resolving, but correctly classified as
		// non-public — 203.0.113.0/24 is a documentation range.
		$this->assertFalse( $resolved->is_public );
	}

	public function test_public_address_is_classified_public(): void {
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		$this->assertTrue( ( new RemoteAddrOnlyResolver() )->resolve()->is_public );
	}

	public function test_private_address_is_classified_not_public(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
		$this->assertFalse( ( new RemoteAddrOnlyResolver() )->resolve()->is_public );
	}

	public function test_cgnat_address_is_classified_not_public(): void {
		$_SERVER['REMOTE_ADDR'] = '100.64.0.1';
		$this->assertFalse( ( new RemoteAddrOnlyResolver() )->resolve()->is_public );
	}

	public function test_ipv6_ula_address_is_classified_not_public(): void {
		$_SERVER['REMOTE_ADDR'] = 'fd12:3456:789a::1';
		$this->assertFalse( ( new RemoteAddrOnlyResolver() )->resolve()->is_public );
	}

	public function test_cf_connecting_ip_header_is_ignored(): void {
		$_SERVER['REMOTE_ADDR']           = '203.0.113.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';

		$this->assertSame( '203.0.113.1', ( new RemoteAddrOnlyResolver() )->resolve()->ip );
	}

	public function test_x_forwarded_for_header_is_ignored(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 198.51.100.10';

		$this->assertSame( '203.0.113.1', ( new RemoteAddrOnlyResolver() )->resolve()->ip );
	}

	public function test_x_real_ip_header_is_ignored(): void {
		$_SERVER['REMOTE_ADDR']    = '203.0.113.1';
		$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.9';

		$this->assertSame( '203.0.113.1', ( new RemoteAddrOnlyResolver() )->resolve()->ip );
	}

	public function test_all_forwarding_headers_simultaneously_are_ignored(): void {
		$_SERVER['REMOTE_ADDR']           = '203.0.113.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.2';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.3, 198.51.100.4';
		$_SERVER['HTTP_X_REAL_IP']        = '198.51.100.5';
		$_SERVER['HTTP_FORWARDED']        = 'for=198.51.100.6';
		$_SERVER['HTTP_TRUE_CLIENT_IP']   = '198.51.100.7';
		$_SERVER['HTTP_CLIENT_IP']        = '198.51.100.8';

		$resolved = ( new RemoteAddrOnlyResolver() )->resolve();

		$this->assertSame( '203.0.113.1', $resolved->ip );
		$this->assertSame( 'REMOTE_ADDR', $resolved->header );
	}

	public function test_resolve_does_not_mutate_server_superglobal(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		$before                 = $_SERVER['REMOTE_ADDR'];

		( new RemoteAddrOnlyResolver() )->resolve();

		$this->assertSame( $before, $_SERVER['REMOTE_ADDR'] );
	}
}
