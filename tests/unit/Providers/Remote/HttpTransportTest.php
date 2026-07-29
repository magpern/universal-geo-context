<?php
/**
 * Unit tests for UniversalGeo\Providers\Remote\HttpTransport.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Providers\Remote;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Providers\Remote\HttpTransport;

/**
 * Enforces the frozen "one-method interface" shape (M4): a provider-facing
 * contract with exactly one public method, get(). Not a guard test —
 * Revision 3 §2 caps those at four — an ordinary structural unit test, the
 * same shape CompositionRootTest already is.
 */
final class HttpTransportTest extends TestCase {

	public function test_is_an_interface(): void {
		$this->assertTrue( ( new ReflectionClass( HttpTransport::class ) )->isInterface() );
	}

	public function test_declares_exactly_one_method(): void {
		$this->assertCount( 1, ( new ReflectionClass( HttpTransport::class ) )->getMethods() );
	}

	public function test_the_one_method_is_named_get(): void {
		$methods = ( new ReflectionClass( HttpTransport::class ) )->getMethods();

		$this->assertSame( 'get', $methods[0]->getName() );
	}
}
