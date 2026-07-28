<?php
/**
 * Guard test: no policy logic (currency, language, tax, etc.) in src/.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Guards;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Tests\Support\SourceGuardTrait;

/**
 * Enforces Revision 3 §2's layering rule and CLAUDE.md invariant 1 ("The
 * plugin resolves geography, never policy"): no file in `src/` mentions
 * currency, locale, language, translation, redirect, shipping, tax, or
 * consent outside a comment/docblock. Comments are explicitly exempt —
 * documentation explaining why the plugin avoids a concern (e.g. this very
 * docblock) is not the violation this guard exists to catch; a term
 * appearing in actual code (an identifier, a function call, a string
 * literal) is.
 *
 * Word-boundary matching means compound WordPress identifiers joined by
 * underscores (`wp_safe_redirect`, `redirect_to`) do not trigger a false
 * positive: `_` is a word character, so there is no boundary in the middle
 * of such a token. Only a standalone occurrence of one of the forbidden
 * words trips this guard.
 *
 * Mutation-verified when written (Revision 3 §16): a forbidden term was
 * injected into non-comment code in a scratch copy, the guard went red, and
 * the injection was reverted — see the M2 sub-step 2A report.
 */
final class NoPolicyGuardTest extends TestCase {

	use SourceGuardTrait;

	/**
	 * Revision 3 §2's exact word list.
	 */
	private const FORBIDDEN_TERMS = array(
		'currency',
		'locale',
		'language',
		'translation',
		'redirect',
		'shipping',
		'tax',
		'consent',
	);

	/**
	 * @dataProvider source_file_provider
	 */
	public function test_source_file_mentions_no_policy_term_outside_a_comment( string $file ): void {
		$code = $this->strip_comments( (string) file_get_contents( $file ) );

		foreach ( self::FORBIDDEN_TERMS as $term ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b' . $term . '\b/i',
				$code,
				sprintf(
					'%s must not mention "%s" outside a comment/docblock.',
					$this->relative_source_path( $file ),
					$term
				)
			);
		}
	}

	public function source_file_provider(): array {
		$cases = array();

		foreach ( $this->source_files() as $file ) {
			$cases[ $this->relative_source_path( $file ) ] = array( $file );
		}

		return $cases;
	}
}
