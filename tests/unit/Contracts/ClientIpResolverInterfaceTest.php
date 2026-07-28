<?php
/**
 * Unit tests for UniversalGeo\Contracts\ClientIpResolverInterface.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use UniversalGeo\Contracts\ClientIpResolverInterface;
use UniversalGeo\Model\ResolvedClientIp;

/**
 * Locks the exact ClientIpResolverInterface contract: namespace, method
 * set, parameter and return types.
 */
final class ClientIpResolverInterfaceTest extends TestCase {

	public function test_interface_exists_via_composer_autoloading(): void {
		// $autoload defaults to true: this must trigger the PSR-4 autoload
		// itself, not merely observe that some other test already loaded
		// it (order-dependent and not what "exists via autoloading" means).
		$this->assertTrue( interface_exists( ClientIpResolverInterface::class ) );
	}

	public function test_interface_is_in_the_contracts_namespace(): void {
		$reflection = new ReflectionClass( ClientIpResolverInterface::class );
		$this->assertSame( 'UniversalGeo\Contracts', $reflection->getNamespaceName() );
	}

	public function test_interface_declares_exactly_one_method(): void {
		$reflection = new ReflectionClass( ClientIpResolverInterface::class );
		$names      = array_map( static fn( ReflectionMethod $method ) => $method->getName(), $reflection->getMethods() );

		$this->assertSame( array( 'resolve' ), $names );
	}

	public function test_resolve_takes_no_arguments_and_returns_a_nullable_resolved_client_ip(): void {
		$method = new ReflectionMethod( ClientIpResolverInterface::class, 'resolve' );

		$this->assertCount( 0, $method->getParameters() );
		$this->assertSame( '?' . ResolvedClientIp::class, (string) $method->getReturnType() );
	}
}
