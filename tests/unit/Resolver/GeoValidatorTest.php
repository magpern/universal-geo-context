<?php
/**
 * Unit tests for UniversalGeo\Resolver\GeoValidator.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Resolver;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Providers\DefaultCountryProvider;
use UniversalGeo\Resolver\GeoValidator;

/**
 * Covers the exact Revision 3 §4.4/§8 contract: two independent static
 * methods operating on raw strings, never on a GeoCandidate. No
 * ContextResolver, provider ordering, or confidence concerns belong here.
 */
final class GeoValidatorTest extends TestCase {

	// ---- country(): valid input --------------------------------------------

	public function test_country_valid_uppercase(): void {
		$this->assertSame( 'SE', GeoValidator::country( 'SE' ) );
	}

	public function test_country_valid_lowercase_is_uppercased(): void {
		$this->assertSame( 'SE', GeoValidator::country( 'se' ) );
	}

	public function test_country_mixed_case_is_uppercased(): void {
		$this->assertSame( 'SE', GeoValidator::country( 'Se' ) );
	}

	public function test_country_surrounding_whitespace_is_trimmed(): void {
		$this->assertSame( 'SE', GeoValidator::country( "  SE\t\n" ) );
	}

	/**
	 * @dataProvider real_country_code_provider
	 */
	public function test_country_recognizes_real_codes_across_the_alphabet( string $code ): void {
		$this->assertSame( $code, GeoValidator::country( $code ) );
	}

	public function real_country_code_provider(): array {
		return array(
			'first alphabetically (Andorra)' => array( 'AD' ),
			'Sweden'                         => array( 'SE' ),
			'United States'                  => array( 'US' ),
			'Japan'                          => array( 'JP' ),
			'Brazil'                         => array( 'BR' ),
			'South Africa'                   => array( 'ZA' ),
			'India'                          => array( 'IN' ),
			'Australia'                      => array( 'AU' ),
			'Germany'                        => array( 'DE' ),
			'France'                         => array( 'FR' ),
			'China'                          => array( 'CN' ),
			'United Kingdom (code is GB)'    => array( 'GB' ),
			'last alphabetically (Zimbabwe)' => array( 'ZW' ),
			'Kosovo, user-assigned'          => array( 'XK' ),
		);
	}

	// ---- country(): invalid input -------------------------------------------

	public function test_country_null_returns_null(): void {
		$this->assertNull( GeoValidator::country( null ) );
	}

	public function test_country_empty_string_returns_null(): void {
		$this->assertNull( GeoValidator::country( '' ) );
	}

	public function test_country_whitespace_only_returns_null(): void {
		$this->assertNull( GeoValidator::country( '   ' ) );
	}

	public function test_country_one_character_returns_null(): void {
		$this->assertNull( GeoValidator::country( 'S' ) );
	}

	public function test_country_three_characters_returns_null(): void {
		$this->assertNull( GeoValidator::country( 'SWE' ) );
	}

	public function test_country_numeric_returns_null(): void {
		$this->assertNull( GeoValidator::country( '12' ) );
	}

	public function test_country_alphanumeric_returns_null(): void {
		$this->assertNull( GeoValidator::country( 'S1' ) );
	}

	public function test_country_punctuation_returns_null(): void {
		$this->assertNull( GeoValidator::country( 'S-' ) );
	}

	public function test_country_unicode_letters_return_null(): void {
		// Å is not an ASCII letter; must not slip past an ASCII-only allowlist.
		$this->assertNull( GeoValidator::country( 'ÅÅ' ) );
	}

	/**
	 * @dataProvider pseudo_code_provider
	 */
	public function test_country_rejects_pseudo_and_reserved_codes( string $code ): void {
		// Every one of these is structurally two uppercase ASCII letters —
		// a regex-only check would wrongly accept all of them. Only real
		// ISO 3166-1 membership rejects them.
		$this->assertNull( GeoValidator::country( $code ) );
	}

	public function pseudo_code_provider(): array {
		return array(
			'EU (Europe, not a country)'         => array( 'EU' ),
			'AP (Asia/Pacific, not a country)'   => array( 'AP' ),
			'XX (unknown placeholder)'           => array( 'XX' ),
			'ZZ (unknown/reserved)'              => array( 'ZZ' ),
			'T1 (Tor exit node placeholder)'     => array( 'T1' ),
			'A1 (anonymous proxy placeholder)'   => array( 'A1' ),
			'A2 (satellite provider)'            => array( 'A2' ),
			'O1 (other country placeholder)'     => array( 'O1' ),
			'UK (informal, real code is GB)'     => array( 'UK' ),
			'UN (United Nations, not a country)' => array( 'UN' ),
		);
	}

	// ---- region(): valid input ----------------------------------------------

	public function test_region_valid_alphabetic(): void {
		$this->assertSame( 'AB', GeoValidator::region( 'AB', 'SE' ) );
	}

	public function test_region_lowercase_is_uppercased(): void {
		$this->assertSame( 'AB', GeoValidator::region( 'ab', 'SE' ) );
	}

	public function test_region_strips_country_prefix(): void {
		$this->assertSame( 'AB', GeoValidator::region( 'SE-AB', 'SE' ) );
	}

	public function test_region_strips_country_prefix_case_insensitively(): void {
		$this->assertSame( 'AB', GeoValidator::region( 'se-ab', 'SE' ) );
	}

	public function test_region_valid_numeric(): void {
		$this->assertSame( '01', GeoValidator::region( '01', 'SE' ) );
	}

	public function test_region_single_character(): void {
		$this->assertSame( 'A', GeoValidator::region( 'A', 'SE' ) );
	}

	public function test_region_three_characters(): void {
		$this->assertSame( 'ABC', GeoValidator::region( 'ABC', 'SE' ) );
	}

	public function test_region_surrounding_whitespace_is_trimmed(): void {
		$this->assertSame( 'AB', GeoValidator::region( "  AB\t\n", 'SE' ) );
	}

	// ---- region(): invalid input --------------------------------------------

	public function test_region_null_returns_null(): void {
		$this->assertNull( GeoValidator::region( null, 'SE' ) );
	}

	public function test_region_empty_string_returns_null(): void {
		$this->assertNull( GeoValidator::region( '', 'SE' ) );
	}

	public function test_region_whitespace_only_returns_null(): void {
		$this->assertNull( GeoValidator::region( '   ', 'SE' ) );
	}

	public function test_region_overlong_returns_null(): void {
		$this->assertNull( GeoValidator::region( 'ABCD', 'SE' ) );
	}

	public function test_region_malformed_characters_return_null(): void {
		$this->assertNull( GeoValidator::region( 'A!B', 'SE' ) );
	}

	public function test_region_unicode_returns_null(): void {
		$this->assertNull( GeoValidator::region( 'ÅB', 'SE' ) );
	}

	public function test_region_non_matching_hyphenated_form_returns_null(): void {
		// The hyphen is only meaningful as the '{country}-' prefix; a
		// hyphen elsewhere in the remainder is not a valid syntactic form.
		$this->assertNull( GeoValidator::region( 'AB-CD', 'SE' ) );
	}

	public function test_region_with_empty_country_does_not_strip_and_still_validates(): void {
		// Documents defined behaviour rather than a crash: with an empty
		// $country the prefix is just '-', which 'AB' does not start with,
		// so no stripping occurs and the syntactic check still applies.
		$this->assertSame( 'AB', GeoValidator::region( 'AB', '' ) );
	}

	public function test_invalid_region_does_not_depend_on_country_validity(): void {
		// GeoValidator::region() does not itself validate $country — that
		// is the caller's responsibility (call country() first).
		$this->assertNull( GeoValidator::region( 'TOOLONG', 'SE' ) );
	}

	// ---- Determinism and purity ----------------------------------------------

	public function test_country_is_deterministic(): void {
		$this->assertSame( GeoValidator::country( 'se' ), GeoValidator::country( 'se' ) );
	}

	public function test_region_is_deterministic(): void {
		$this->assertSame( GeoValidator::region( 'se-ab', 'SE' ), GeoValidator::region( 'se-ab', 'SE' ) );
	}

	// ---- Allowlist integrity ----------------------------------------------

	public function test_every_allowlist_entry_matches_the_declared_format(): void {
		$reflection = new ReflectionClass( GeoValidator::class );
		$codes      = $reflection->getConstant( 'COUNTRY_CODES' );

		$this->assertNotEmpty( $codes );

		foreach ( array_keys( $codes ) as $code ) {
			$this->assertMatchesRegularExpression( '/^[A-Z]{2}$/', $code, "Allowlist entry '{$code}' does not match ^[A-Z]{2}\$." );
		}
	}

	public function test_allowlist_does_not_contain_any_pseudo_code(): void {
		$reflection = new ReflectionClass( GeoValidator::class );
		$codes      = $reflection->getConstant( 'COUNTRY_CODES' );

		foreach ( $this->pseudo_code_provider() as $case ) {
			$code = $case[0];
			$this->assertArrayNotHasKey( $code, $codes, "Allowlist must not contain pseudo-code '{$code}'." );
		}
	}

	// ---- Class shape ------------------------------------------------------

	public function test_class_is_final(): void {
		$reflection = new ReflectionClass( GeoValidator::class );
		$this->assertTrue( $reflection->isFinal() );
	}

	public function test_public_api_is_exactly_country_and_region(): void {
		$reflection = new ReflectionClass( GeoValidator::class );
		$public     = array_map(
			static fn( \ReflectionMethod $method ) => $method->getName(),
			$reflection->getMethods( \ReflectionMethod::IS_PUBLIC )
		);

		sort( $public );
		$this->assertSame( array( 'country', 'country_codes', 'region' ), $public );
	}

	public function test_class_has_no_constructor(): void {
		$reflection = new ReflectionClass( GeoValidator::class );
		$this->assertNull( $reflection->getConstructor() );
	}

	// ---- DefaultCountryProvider interoperability ---------------------------
	// Narrow, unit-level only: proves the two components compose correctly
	// without ContextResolver, provider iteration, confidence, or caching.

	public function test_validates_a_default_country_provider_candidate(): void {
		$candidate = ( new DefaultCountryProvider( 'se' ) )->resolve( '203.0.113.1' );

		$this->assertSame( 'SE', GeoValidator::country( $candidate->country_code ) );
	}

	public function test_rejects_a_malformed_default_country_provider_candidate(): void {
		$candidate = ( new DefaultCountryProvider( 'SWE' ) )->resolve( '203.0.113.1' );

		$this->assertNull( GeoValidator::country( $candidate->country_code ) );
	}
}
