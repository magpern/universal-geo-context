<?php
/**
 * Unit tests for UniversalGeo\Model\GeoCandidate.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Model\GeoCandidate;

/**
 * Covers construction and immutability of GeoCandidate. GeoCandidate
 * performs no validation or normalization — that is GeoValidator's job in a
 * later milestone — so these tests assert the facts are stored verbatim.
 */
final class GeoCandidateTest extends TestCase {

	public function test_country_code_is_stored_verbatim(): void {
		$candidate = new GeoCandidate( 'SE', null );
		$this->assertSame( 'SE', $candidate->country_code );
	}

	public function test_lowercase_country_is_not_normalized(): void {
		$candidate = new GeoCandidate( 'se', null );
		$this->assertSame( 'se', $candidate->country_code );
	}

	public function test_malformed_country_is_not_rejected(): void {
		$candidate = new GeoCandidate( 'United Kingdom', null );
		$this->assertSame( 'United Kingdom', $candidate->country_code );
	}

	public function test_null_country_code_is_accepted(): void {
		$candidate = new GeoCandidate( null, null );
		$this->assertNull( $candidate->country_code );
	}

	public function test_null_region_code_is_accepted(): void {
		$candidate = new GeoCandidate( 'SE', null );
		$this->assertNull( $candidate->region_code );
	}

	public function test_region_code_is_stored_verbatim(): void {
		$candidate = new GeoCandidate( 'SE', 'ab' );
		$this->assertSame( 'ab', $candidate->region_code );
	}

	public function test_class_has_no_source_property(): void {
		$this->assertFalse( property_exists( GeoCandidate::class, 'source' ) );
	}

	public function test_class_has_no_confidence_property(): void {
		$this->assertFalse( property_exists( GeoCandidate::class, 'confidence' ) );
	}

	public function test_class_has_no_mutator_or_getter_methods(): void {
		$this->assertFalse( method_exists( GeoCandidate::class, 'get_country_code' ) );
		$this->assertFalse( method_exists( GeoCandidate::class, 'get_region_code' ) );
		$this->assertFalse( method_exists( GeoCandidate::class, 'get_source' ) );
		$this->assertFalse( method_exists( GeoCandidate::class, 'set_country_code' ) );
		$this->assertFalse( method_exists( GeoCandidate::class, 'set_region_code' ) );
	}

	public function test_class_is_final(): void {
		$reflection = new ReflectionClass( GeoCandidate::class );
		$this->assertTrue( $reflection->isFinal() );
	}

	public function test_properties_are_public_and_readonly(): void {
		$candidate  = new GeoCandidate( 'SE', 'AB' );
		$reflection = new ReflectionClass( $candidate );

		$this->assertCount( 2, $reflection->getProperties() );

		foreach ( $reflection->getProperties() as $property ) {
			$this->assertTrue( $property->isPublic(), sprintf( 'Property %s must be public.', $property->getName() ) );
			$this->assertTrue( $property->isReadOnly(), sprintf( 'Property %s must be readonly.', $property->getName() ) );
		}
	}
}
