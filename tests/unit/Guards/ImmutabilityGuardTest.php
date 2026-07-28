<?php
/**
 * Guard test: the plugin's frozen value objects stay final and readonly.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Guards;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Http\ServerRequest;
use UniversalGeo\Model\GeoCandidate;
use UniversalGeo\Model\ResolvedClientIp;
use UniversalGeo\Model\VisitorContext;

/**
 * Enforces Revision 3 §13's exact frozen list: "VisitorContext, GeoCandidate,
 * ResolvedClientIp and ServerRequest are final with readonly promoted
 * properties." An explicit class allowlist, not a naming-pattern or
 * directory heuristic — "value object" is a design classification this
 * guard must not try to infer, and a heuristic (e.g. "everything under
 * src/Model/") would silently miss ServerRequest, which lives under
 * src/Http/ once M2 adds it.
 *
 * Checks `final` and "every declared property is readonly" — not literal
 * constructor promotion of every property. VisitorContext (frozen, M1)
 * validates and transforms `country_code` and `source` inside its
 * constructor body before assignment, so those two are declared readonly
 * properties rather than promoted parameters; GeoCandidate and
 * ResolvedClientIp are fully promoted. "Readonly" is the actual immutability
 * invariant; "promoted" is Revision 3's description of the common case, not
 * a separate rule the frozen code must also satisfy.
 *
 * ServerRequest (M2 sub-step 2B) is fully constructor-promoted, like
 * GeoCandidate and ResolvedClientIp. Its private constructor is intentional
 * (capture() is the only construction path) and does not affect this guard,
 * which only inspects properties, not visibility or promotion.
 *
 * Mutation-verified when written (Revision 3 §16): `readonly` was removed
 * from one property in a scratch copy, the guard went red, and the removal
 * was reverted — see the M2 sub-step 2A report.
 */
final class ImmutabilityGuardTest extends TestCase {

	private const IMMUTABLE_VALUE_OBJECTS = array(
		VisitorContext::class,
		GeoCandidate::class,
		ResolvedClientIp::class,
		ServerRequest::class,
	);

	/**
	 * @dataProvider value_object_provider
	 */
	public function test_value_object_class_is_final( string $class_name ): void {
		$this->assertTrue( ( new ReflectionClass( $class_name ) )->isFinal(), "{$class_name} must be final." );
	}

	/**
	 * @dataProvider value_object_provider
	 */
	public function test_value_object_declares_at_least_one_property( string $class_name ): void {
		$this->assertNotEmpty(
			( new ReflectionClass( $class_name ) )->getProperties(),
			"{$class_name} must declare at least one property."
		);
	}

	/**
	 * @dataProvider value_object_provider
	 */
	public function test_value_object_properties_are_all_readonly( string $class_name ): void {
		foreach ( ( new ReflectionClass( $class_name ) )->getProperties() as $property ) {
			$this->assertTrue(
				$property->isReadOnly(),
				sprintf( '%s::$%s must be readonly.', $class_name, $property->getName() )
			);
		}
	}

	public function value_object_provider(): array {
		$cases = array();

		foreach ( self::IMMUTABLE_VALUE_OBJECTS as $class_name ) {
			$cases[ $class_name ] = array( $class_name );
		}

		return $cases;
	}
}
