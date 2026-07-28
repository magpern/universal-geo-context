<?php
/**
 * Unit tests for UniversalGeo\Model\VisitorContext.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Model;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Model\VisitorContext;

/**
 * Covers construction, validation, the unknown-state contract,
 * serialization and immutability of VisitorContext.
 */
final class VisitorContextTest extends TestCase {

	public function test_known_context_stores_all_fields(): void {
		$context = new VisitorContext( 'SE', 'AB', 'maxmind', 0.9, true );

		$this->assertSame( 'SE', $context->country_code );
		$this->assertSame( 'AB', $context->region_code );
		$this->assertSame( 'maxmind', $context->source );
		$this->assertSame( 0.9, $context->confidence );
		$this->assertTrue( $context->is_cached );
	}

	public function test_is_cached_defaults_to_false(): void {
		$context = new VisitorContext( 'SE', null, 'maxmind', 0.9 );
		$this->assertFalse( $context->is_cached );
	}

	public function test_lowercase_country_is_normalized_to_uppercase(): void {
		$context = new VisitorContext( 'se', null, 'maxmind', 0.9 );
		$this->assertSame( 'SE', $context->country_code );
	}

	public function test_null_country_code_is_accepted(): void {
		$context = new VisitorContext( null, null, 'unknown', 0.0 );
		$this->assertNull( $context->country_code );
	}

	/**
	 * @dataProvider invalid_country_provider
	 */
	public function test_non_null_invalid_country_is_rejected( string $country ): void {
		$this->expectException( InvalidArgumentException::class );
		new VisitorContext( $country, null, 'maxmind', 0.9 );
	}

	public function invalid_country_provider(): array {
		return array(
			'empty'         => array( '' ),
			'one letter'    => array( 'S' ),
			'three letters' => array( 'SWE' ),
			'digits'        => array( '12' ),
		);
	}

	public function test_region_defaults_to_null(): void {
		$context = new VisitorContext( 'SE', null, 'maxmind', 0.9 );
		$this->assertNull( $context->region_code );
	}

	public function test_source_is_trimmed(): void {
		$context = new VisitorContext( 'SE', null, '  maxmind  ', 0.9 );
		$this->assertSame( 'maxmind', $context->source );
	}

	public function test_empty_source_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new VisitorContext( 'SE', null, '', 0.9 );
	}

	public function test_whitespace_only_source_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new VisitorContext( 'SE', null, '   ', 0.9 );
	}

	/**
	 * @dataProvider valid_confidence_provider
	 */
	public function test_valid_confidence_is_stored( float $confidence ): void {
		$context = new VisitorContext( 'SE', null, 'maxmind', $confidence );
		$this->assertSame( $confidence, $context->confidence );
	}

	public function valid_confidence_provider(): array {
		return array(
			'lower bound' => array( 0.0 ),
			'upper bound' => array( 1.0 ),
			'mid-range'   => array( 0.85 ),
		);
	}

	/**
	 * @dataProvider invalid_confidence_provider
	 */
	public function test_invalid_confidence_is_rejected( float $confidence ): void {
		$this->expectException( InvalidArgumentException::class );
		new VisitorContext( 'SE', null, 'maxmind', $confidence );
	}

	public function invalid_confidence_provider(): array {
		return array(
			'below zero'        => array( -0.01 ),
			'above one'         => array( 1.01 ),
			'nan'               => array( NAN ),
			'positive infinity' => array( INF ),
			'negative infinity' => array( -INF ),
		);
	}

	public function test_is_known_true_for_resolved_country(): void {
		$context = new VisitorContext( 'SE', null, 'maxmind', 0.9 );
		$this->assertTrue( $context->is_known() );
	}

	public function test_is_known_false_for_null_country(): void {
		$context = new VisitorContext( null, null, 'unknown', 0.0 );
		$this->assertFalse( $context->is_known() );
	}

	public function test_has_region_true_when_region_present(): void {
		$context = new VisitorContext( 'SE', 'AB', 'maxmind', 0.9 );
		$this->assertTrue( $context->has_region() );
	}

	public function test_has_region_false_when_region_absent(): void {
		$context = new VisitorContext( 'SE', null, 'maxmind', 0.9 );
		$this->assertFalse( $context->has_region() );
	}

	public function test_with_cached_returns_new_instance_with_flag_toggled(): void {
		$original = new VisitorContext( 'SE', 'AB', 'maxmind', 0.9, false );
		$cached   = $original->with_cached( true );

		$this->assertNotSame( $original, $cached );
		$this->assertFalse( $original->is_cached );
		$this->assertTrue( $cached->is_cached );
	}

	public function test_with_cached_preserves_every_other_field(): void {
		$original = new VisitorContext( 'SE', 'AB', 'maxmind', 0.9, false );
		$cached   = $original->with_cached( true );

		$this->assertSame( $original->country_code, $cached->country_code );
		$this->assertSame( $original->region_code, $cached->region_code );
		$this->assertSame( $original->source, $cached->source );
		$this->assertSame( $original->confidence, $cached->confidence );
	}

	public function test_unknown_has_no_country_or_region(): void {
		$context = VisitorContext::unknown();
		$this->assertNull( $context->country_code );
		$this->assertNull( $context->region_code );
	}

	public function test_unknown_has_source_unknown(): void {
		$this->assertSame( 'unknown', VisitorContext::unknown()->source );
	}

	public function test_unknown_has_zero_confidence(): void {
		$this->assertSame( 0.0, VisitorContext::unknown()->confidence );
	}

	public function test_unknown_is_not_cached(): void {
		$this->assertFalse( VisitorContext::unknown()->is_cached );
	}

	public function test_unknown_is_known_returns_false(): void {
		$this->assertFalse( VisitorContext::unknown()->is_known() );
	}

	public function test_schema_version_constant_is_one(): void {
		$this->assertSame( 1, VisitorContext::SCHEMA_VERSION );
	}

	public function test_to_array_contains_exactly_the_expected_keys(): void {
		$context = new VisitorContext( 'SE', 'AB', 'maxmind', 0.9, true );

		$this->assertSame(
			array( 'schema_version', 'country_code', 'region_code', 'source', 'confidence', 'is_cached' ),
			array_keys( $context->to_array() )
		);
	}

	public function test_to_array_values_match_the_object(): void {
		$context = new VisitorContext( 'SE', 'AB', 'maxmind', 0.9, true );

		$this->assertSame(
			array(
				'schema_version' => 1,
				'country_code'   => 'SE',
				'region_code'    => 'AB',
				'source'         => 'maxmind',
				'confidence'     => 0.9,
				'is_cached'      => true,
			),
			$context->to_array()
		);
	}

	public function test_from_array_round_trips_a_known_context(): void {
		$original = new VisitorContext( 'SE', 'AB', 'maxmind', 0.9, true );
		$restored = VisitorContext::from_array( $original->to_array() );

		$this->assertSame( $original->country_code, $restored->country_code );
		$this->assertSame( $original->region_code, $restored->region_code );
		$this->assertSame( $original->source, $restored->source );
		$this->assertSame( $original->confidence, $restored->confidence );
		$this->assertSame( $original->is_cached, $restored->is_cached );
	}

	public function test_from_array_round_trips_the_unknown_context(): void {
		$restored = VisitorContext::from_array( VisitorContext::unknown()->to_array() );

		$this->assertFalse( $restored->is_known() );
		$this->assertSame( 'unknown', $restored->source );
		$this->assertSame( 0.0, $restored->confidence );
	}

	public function test_from_array_never_throws_on_empty_input(): void {
		$restored = VisitorContext::from_array( array() );

		$this->assertNull( $restored->country_code );
		$this->assertSame( 'unknown', $restored->source );
		$this->assertSame( 0.0, $restored->confidence );
		$this->assertFalse( $restored->is_cached );
	}

	/**
	 * @dataProvider malformed_hydration_provider
	 */
	public function test_from_array_never_throws_on_malformed_input( array $data ): void {
		$restored = VisitorContext::from_array( $data );
		$this->assertInstanceOf( VisitorContext::class, $restored );
	}

	public function malformed_hydration_provider(): array {
		return array(
			'malformed country'       => array( array( 'country_code' => 'SWE' ) ),
			'non-string country'      => array( array( 'country_code' => 123 ) ),
			'non-string source'       => array( array( 'source' => array( 'x' ) ) ),
			'confidence out of range' => array( array( 'confidence' => 5.0 ) ),
			'confidence nan'          => array( array( 'confidence' => NAN ) ),
			'non-numeric confidence'  => array( array( 'confidence' => 'high' ) ),
		);
	}

	public function test_from_array_falls_back_to_unknown_country_for_malformed_country(): void {
		$restored = VisitorContext::from_array( array( 'country_code' => 'SWE' ) );
		$this->assertNull( $restored->country_code );
	}

	public function test_class_has_no_getter_methods(): void {
		$this->assertFalse( method_exists( VisitorContext::class, 'get_country_code' ) );
		$this->assertFalse( method_exists( VisitorContext::class, 'get_region_code' ) );
		$this->assertFalse( method_exists( VisitorContext::class, 'get_source' ) );
		$this->assertFalse( method_exists( VisitorContext::class, 'get_confidence' ) );
	}

	public function test_class_is_final(): void {
		$reflection = new ReflectionClass( VisitorContext::class );
		$this->assertTrue( $reflection->isFinal() );
	}

	public function test_properties_are_public_and_readonly(): void {
		$context    = new VisitorContext( 'SE', 'AB', 'maxmind', 0.9 );
		$reflection = new ReflectionClass( $context );

		$this->assertCount( 5, $reflection->getProperties() );

		foreach ( $reflection->getProperties() as $property ) {
			$this->assertTrue( $property->isPublic(), sprintf( 'Property %s must be public.', $property->getName() ) );
			$this->assertTrue( $property->isReadOnly(), sprintf( 'Property %s must be readonly.', $property->getName() ) );
		}
	}
}
