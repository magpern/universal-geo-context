<?php
/**
 * Architecture guard: ContextController's REST v1 contract independence.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Guards;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Tests\Support\SourceGuardTrait;

/**
 * Protects the two architectural invariants M14's REST surface exists to
 * guarantee (docs/PLAN-v1.9.0.md §B/§C, docs/adr/0012-cache-safe-visitor-context.md):
 *
 * 1. `ContextController` depends on a narrow `callable`, never on `Plugin`
 *    as a service locator — no import, no type-hint, no `Plugin::instance()`
 *    call anywhere in the file.
 * 2. The REST v1 response contract (`country_code`, `region_code` only) is
 *    an explicit, independent mapping — never `VisitorContext::to_array()`,
 *    never `VisitorContext::SCHEMA_VERSION`.
 *
 * A source-scan guard rather than a runtime assertion deliberately: the
 * property under test is "this file never contains X", which a passing
 * behavioral test cannot rule out (the correct behavior today does not
 * prove no accidental `to_array()` call was left dead in the file, nor
 * that a future edit will not reintroduce one). Also asserts
 * ContextController never reads $_SERVER/$_GET directly — the trust
 * boundary stays confined to ServerRequest (TrustBoundaryGuardTest already
 * covers $_SERVER repo-wide; $_GET is REST-route-specific, checked here).
 *
 * @internal
 */
final class RestContractGuardTest extends TestCase {

	use SourceGuardTrait;

	private const TARGET_FILE = 'Rest/ContextController.php';

	/**
	 * Literal strings that must never appear in ContextController.php.
	 * Deliberately over-inclusive (matches even inside a future comment) —
	 * a legitimate reference to any of these belongs in a different file,
	 * so a false positive here is itself a signal worth looking at.
	 */
	private const FORBIDDEN_SUBSTRINGS = array(
		'to_array(',
		'SCHEMA_VERSION',
		'Plugin::instance(',
		'$_SERVER',
		'$_GET',
	);

	public function test_context_controller_does_not_reference_forbidden_symbols(): void {
		$path = dirname( __DIR__, 3 ) . '/src/' . self::TARGET_FILE;
		// Comments stripped first (real tokenizer, not a regex approximation
		// — SourceGuardTrait::strip_comments()) so this checks executable
		// code only; the file's own docblocks legitimately name
		// to_array()/SCHEMA_VERSION in prose explaining why they are NOT used.
		$code = $this->strip_comments( (string) file_get_contents( $path ) );

		foreach ( self::FORBIDDEN_SUBSTRINGS as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$code,
				sprintf(
					'%s must never reference "%s" — see docs/adr/0012-cache-safe-visitor-context.md for why.',
					self::TARGET_FILE,
					$forbidden
				)
			);
		}
	}

	public function test_context_controller_does_not_import_or_type_hint_plugin(): void {
		$path = dirname( __DIR__, 3 ) . '/src/' . self::TARGET_FILE;
		$code = $this->strip_comments( (string) file_get_contents( $path ) );

		$this->assertDoesNotMatchRegularExpression(
			'/\buse\s+UniversalGeo\\\\Plugin\s*;/',
			$code,
			self::TARGET_FILE . ' must not import UniversalGeo\\Plugin — it depends on a plain callable only.'
		);

		$this->assertDoesNotMatchRegularExpression(
			'/\bPlugin\s+\$/',
			$code,
			self::TARGET_FILE . ' must not type-hint a Plugin parameter — it depends on a plain callable only.'
		);
	}

	public function test_context_controller_file_actually_exists(): void {
		// Guards against both checks above passing vacuously if the file
		// were ever renamed or removed.
		$path = dirname( __DIR__, 3 ) . '/src/' . self::TARGET_FILE;
		$this->assertFileExists( $path );
	}
}
