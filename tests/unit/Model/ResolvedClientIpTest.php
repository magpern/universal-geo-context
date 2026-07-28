<?php
/**
 * Unit tests for UniversalGeo\Model\ResolvedClientIp.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Http\IpUtils;
use UniversalGeo\Model\ResolvedClientIp;

/**
 * Covers construction and immutability of the @internal ResolvedClientIp
 * value object.
 */
final class ResolvedClientIpTest extends TestCase {

	public function test_constructor_stores_all_fields(): void {
		$resolved = new ResolvedClientIp( '203.0.113.5', 'REMOTE_ADDR', true, false );

		$this->assertSame( '203.0.113.5', $resolved->ip );
		$this->assertSame( 'REMOTE_ADDR', $resolved->header );
		$this->assertTrue( $resolved->chain_verified );
		$this->assertFalse( $resolved->is_public );
	}

	public function test_class_is_final(): void {
		$reflection = new ReflectionClass( ResolvedClientIp::class );
		$this->assertTrue( $reflection->isFinal() );
	}

	public function test_class_has_no_mutator_methods(): void {
		$this->assertFalse( method_exists( ResolvedClientIp::class, 'set_ip' ) );
		$this->assertFalse( method_exists( ResolvedClientIp::class, 'with_ip' ) );
	}

	public function test_masked_method_exists(): void {
		$this->assertTrue( method_exists( ResolvedClientIp::class, 'masked' ) );
	}

	public function test_masked_delegates_to_ip_utils_for_ipv4(): void {
		$resolved = new ResolvedClientIp( '203.0.113.55', 'REMOTE_ADDR', true, true );
		$this->assertSame( IpUtils::mask( '203.0.113.55' ), $resolved->masked() );
		$this->assertSame( '203.0.113.x', $resolved->masked() );
	}

	public function test_masked_delegates_to_ip_utils_for_ipv6(): void {
		$resolved = new ResolvedClientIp( '2001:db8:1234:5678::1', 'REMOTE_ADDR', true, true );
		$this->assertSame( IpUtils::mask( '2001:db8:1234:5678::1' ), $resolved->masked() );
		$this->assertSame( '2001:db8:1234:…', $resolved->masked() );
	}

	public function test_masked_does_not_leak_the_full_address(): void {
		$resolved = new ResolvedClientIp( '203.0.113.55', 'REMOTE_ADDR', true, true );
		$this->assertStringNotContainsString( '55', $resolved->masked() );
	}

	public function test_properties_are_public_and_readonly(): void {
		$resolved   = new ResolvedClientIp( '203.0.113.5', 'REMOTE_ADDR', true, false );
		$reflection = new ReflectionClass( $resolved );

		$this->assertCount( 4, $reflection->getProperties() );

		foreach ( $reflection->getProperties() as $property ) {
			$this->assertTrue( $property->isPublic(), sprintf( 'Property %s must be public.', $property->getName() ) );
			$this->assertTrue( $property->isReadOnly(), sprintf( 'Property %s must be readonly.', $property->getName() ) );
		}
	}
}
