<?php
/**
 * Unit tests for UniversalGeo\Providers\DefaultCountryProvider.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use UniversalGeo\Contracts\GeoProviderInterface;
use UniversalGeo\Model\GeoCandidate;
use UniversalGeo\Providers\DefaultCountryProvider;

/**
 * Covers the complete DefaultCountryProvider contract: identity,
 * availability, and resolve() behaviour. No ContextResolver, ordering, or
 * confidence concerns belong here — this provider is tested in isolation.
 */
final class DefaultCountryProviderTest extends TestCase {

	// ---- Interface and class shape --------------------------------------

	public function test_implements_geo_provider_interface(): void {
		$this->assertInstanceOf( GeoProviderInterface::class, new DefaultCountryProvider( 'SE' ) );
	}

	public function test_class_is_in_the_providers_namespace(): void {
		$reflection = new ReflectionClass( DefaultCountryProvider::class );
		$this->assertSame( 'UniversalGeo\Providers', $reflection->getNamespaceName() );
	}

	public function test_class_is_final(): void {
		$reflection = new ReflectionClass( DefaultCountryProvider::class );
		$this->assertTrue( $reflection->isFinal() );
	}

	public function test_constructor_takes_exactly_one_string_parameter(): void {
		$constructor = ( new ReflectionClass( DefaultCountryProvider::class ) )->getConstructor();
		$parameters  = $constructor->getParameters();

		$this->assertCount( 1, $parameters );
		$this->assertSame( 'default_country', $parameters[0]->getName() );
		$this->assertSame( 'string', (string) $parameters[0]->getType() );
		$this->assertFalse( $parameters[0]->allowsNull() );
	}

	public function test_public_method_set_matches_the_interface_exactly(): void {
		$reflection = new ReflectionClass( DefaultCountryProvider::class );
		$names      = array_values(
			array_diff(
				array_map(
					static fn( ReflectionMethod $method ) => $method->getName(),
					$reflection->getMethods( ReflectionMethod::IS_PUBLIC )
				),
				array( '__construct' )
			)
		);

		sort( $names );
		$this->assertSame( array( 'get_id', 'is_available', 'resolve' ), $names );
	}

	// ---- get_id() ---------------------------------------------------------

	public function test_provider_id_is_exactly_default(): void {
		$this->assertSame( 'default', ( new DefaultCountryProvider( 'SE' ) )->get_id() );
	}

	public function test_provider_id_does_not_depend_on_configured_country(): void {
		$this->assertSame( 'default', ( new DefaultCountryProvider( 'DE' ) )->get_id() );
		$this->assertSame( 'default', ( new DefaultCountryProvider( '' ) )->get_id() );
	}

	// ---- is_available() ----------------------------------------------------

	public function test_available_when_a_country_is_configured(): void {
		$this->assertTrue( ( new DefaultCountryProvider( 'SE' ) )->is_available() );
	}

	public function test_unavailable_when_country_is_empty(): void {
		$this->assertFalse( ( new DefaultCountryProvider( '' ) )->is_available() );
	}

	public function test_available_even_for_a_structurally_malformed_configured_value(): void {
		// Structural/ISO-3166 validation is GeoValidator's job in the
		// resolver loop, applied uniformly to every provider — this layer
		// only asks "is something configured at all?".
		$this->assertTrue( ( new DefaultCountryProvider( 'SWE' ) )->is_available() );
		$this->assertTrue( ( new DefaultCountryProvider( 'not-a-country' ) )->is_available() );
	}

	// ---- resolve() ----------------------------------------------------------

	public function test_resolve_returns_a_geo_candidate_when_available(): void {
		$this->assertInstanceOf( GeoCandidate::class, ( new DefaultCountryProvider( 'SE' ) )->resolve( '203.0.113.1' ) );
	}

	public function test_resolve_returns_the_configured_country_code(): void {
		$candidate = ( new DefaultCountryProvider( 'SE' ) )->resolve( '203.0.113.1' );
		$this->assertSame( 'SE', $candidate->country_code );
	}

	public function test_resolve_returns_null_region(): void {
		$candidate = ( new DefaultCountryProvider( 'SE' ) )->resolve( '203.0.113.1' );
		$this->assertNull( $candidate->region_code );
	}

	public function test_resolve_returns_null_when_unavailable(): void {
		$this->assertNull( ( new DefaultCountryProvider( '' ) )->resolve( '203.0.113.1' ) );
	}

	public function test_resolve_carries_a_malformed_configured_value_through_unvalidated(): void {
		// Confirms the provider itself does not reject or correct it —
		// that discard happens uniformly in the resolver loop later.
		$candidate = ( new DefaultCountryProvider( 'SWE' ) )->resolve( '203.0.113.1' );
		$this->assertSame( 'SWE', $candidate->country_code );
	}

	/**
	 * @dataProvider ip_provider
	 */
	public function test_different_ip_values_do_not_affect_the_result( string $ip ): void {
		$candidate = ( new DefaultCountryProvider( 'SE' ) )->resolve( $ip );
		$this->assertSame( 'SE', $candidate->country_code );
	}

	public function ip_provider(): array {
		return array(
			'public ipv4'  => array( '203.0.113.1' ),
			'private ipv4' => array( '10.0.0.1' ),
			'ipv6'         => array( '2001:db8::1' ),
			'empty string' => array( '' ),
			'malformed'    => array( 'not-an-ip' ),
		);
	}

	public function test_resolve_returns_a_fresh_candidate_each_call(): void {
		$provider = new DefaultCountryProvider( 'SE' );
		$first    = $provider->resolve( '203.0.113.1' );
		$second   = $provider->resolve( '198.51.100.1' );

		$this->assertNotSame( $first, $second );
		$this->assertSame( $first->country_code, $second->country_code );
	}

	public function test_resolve_does_not_mutate_configuration(): void {
		$provider = new DefaultCountryProvider( 'SE' );

		$provider->resolve( '203.0.113.1' );

		$this->assertTrue( $provider->is_available() );
		$this->assertSame( 'SE', $provider->resolve( '198.51.100.1' )->country_code );
	}

	// ---- No confidence/source on the candidate ----------------------------

	public function test_returned_candidate_has_no_source_or_confidence_fields(): void {
		$this->assertFalse( property_exists( GeoCandidate::class, 'source' ) );
		$this->assertFalse( property_exists( GeoCandidate::class, 'confidence' ) );
	}
}
