<?php
/**
 * Unit tests for UniversalGeo\Contracts\GeoProviderInterface.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Model\GeoCandidate;
use UniversalGeo\Tests\Unit\Doubles\FakeGeoProvider;

/**
 * Locks the exact GeoProviderInterface contract: namespace, method names,
 * parameter and return types. No concrete provider is implemented in M1
 * Step 1C; ContextResolver is deferred until its full Revision 3 dependency
 * graph exists.
 */
final class GeoProviderInterfaceTest extends TestCase {

	public function test_interface_exists_via_composer_autoloading(): void {
		// $autoload defaults to true: this must trigger the PSR-4 autoload
		// itself, not merely observe that some other test already loaded
		// it (order-dependent and not what "exists via autoloading" means).
		$this->assertTrue( interface_exists( GeoProviderInterface::class ) );
	}

	public function test_interface_is_in_the_contracts_namespace(): void {
		$reflection = new ReflectionClass( GeoProviderInterface::class );
		$this->assertSame( 'UniversalGeo\Contracts', $reflection->getNamespaceName() );
	}

	public function test_interface_declares_exactly_these_three_methods(): void {
		$reflection = new ReflectionClass( GeoProviderInterface::class );
		$names      = array_map( static fn( ReflectionMethod $method ) => $method->getName(), $reflection->getMethods() );

		$this->assertSame( array( 'get_id', 'is_available', 'resolve' ), $names );
	}

	public function test_get_id_returns_string_and_takes_no_arguments(): void {
		$method = new ReflectionMethod( GeoProviderInterface::class, 'get_id' );
		$this->assertSame( 'string', (string) $method->getReturnType() );
		$this->assertCount( 0, $method->getParameters() );
	}

	public function test_is_available_returns_bool_and_takes_no_arguments(): void {
		$method = new ReflectionMethod( GeoProviderInterface::class, 'is_available' );
		$this->assertSame( 'bool', (string) $method->getReturnType() );
		$this->assertCount( 0, $method->getParameters() );
	}

	public function test_resolve_accepts_a_string_ip_and_returns_a_nullable_candidate(): void {
		$method = new ReflectionMethod( GeoProviderInterface::class, 'resolve' );
		$this->assertSame( '?' . GeoCandidate::class, (string) $method->getReturnType() );

		$parameters = $method->getParameters();
		$this->assertCount( 1, $parameters );
		$this->assertSame( 'ip', $parameters[0]->getName() );
		$this->assertSame( 'string', (string) $parameters[0]->getType() );
	}

	/**
	 * A reflection check only proves the *declared* shape. These exercise a
	 * real implementation to prove the contract is actually usable: a
	 * provider can report its own id and availability, and resolve() can
	 * legitimately answer with either a candidate (hit) or null (miss) —
	 * the miss case the resolver's provider loop depends on (Revision 3 §7).
	 */
	public function test_a_hand_written_double_satisfies_the_interface(): void {
		$provider = new FakeGeoProvider( 'test' );
		$this->assertInstanceOf( GeoProviderInterface::class, $provider );
	}

	public function test_double_returns_its_configured_id(): void {
		$provider = new FakeGeoProvider( 'maxmind' );
		$this->assertSame( 'maxmind', $provider->get_id() );
	}

	public function test_double_reports_configured_availability(): void {
		$this->assertTrue( ( new FakeGeoProvider( 'a', true ) )->is_available() );
		$this->assertFalse( ( new FakeGeoProvider( 'a', false ) )->is_available() );
	}

	public function test_double_resolve_returns_the_configured_candidate(): void {
		$candidate = new GeoCandidate( 'SE', null );
		$provider  = new FakeGeoProvider( 'a', true, $candidate );

		$this->assertSame( $candidate, $provider->resolve( '203.0.113.1' ) );
	}

	public function test_double_resolve_returns_null_when_no_candidate_is_configured(): void {
		$provider = new FakeGeoProvider( 'a' );
		$this->assertNull( $provider->resolve( '203.0.113.1' ) );
	}
}
