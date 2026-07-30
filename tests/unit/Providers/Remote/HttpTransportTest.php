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
 * Enforces the transport contract's exact shape: get() (M4, the provider-
 * facing single-request method) plus get_redirect_location() and download()
 * (M6, the two-hop redirect-safe download flow — used exclusively by
 * MaxMind\DatabaseManager, never by ReferenceRemoteProvider). Not a guard
 * test — Revision 3 §2 caps those at four — an ordinary structural unit
 * test, the same shape CompositionRootTest already is.
 */
final class HttpTransportTest extends TestCase {

	private const EXPECTED_METHODS = array( 'get', 'get_redirect_location', 'download' );

	public function test_is_an_interface(): void {
		$this->assertTrue( ( new ReflectionClass( HttpTransport::class ) )->isInterface() );
	}

	public function test_declares_exactly_the_expected_methods(): void {
		$methods = array_map(
			static fn( $method ) => $method->getName(),
			( new ReflectionClass( HttpTransport::class ) )->getMethods()
		);

		sort( $methods );
		$expected = self::EXPECTED_METHODS;
		sort( $expected );

		$this->assertSame( $expected, $methods );
	}
}
