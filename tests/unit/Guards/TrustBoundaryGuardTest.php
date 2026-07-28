<?php
/**
 * Guard test: the $_SERVER superglobal is confined to the trust boundary.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Guards;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Tests\Support\SourceGuardTrait;

/**
 * Enforces Revision 3 §2's layering rule: "$_SERVER forwarding headers are
 * read in exactly one file (src/Http/ServerRequest.php)".
 *
 * ServerRequest's actual implementation is built from a plain array
 * (Revision 3 §16: "ServerRequest is built from an array — pass a
 * fixture", the seam that keeps it unit-testable without a WordPress
 * bootstrap), so the literal $_SERVER superglobal is touched at two places:
 * `Plugin::build_resolver()`, which passes the whole array once to
 * ServerRequest::capture() at plugins_loaded 10, and
 * `DiagnosticsService`'s report builder, which captures a *second*, fresh
 * snapshot to compare against the boot-time one for `$_SERVER` drift
 * detection (Revision 3 §5's drift reporting; M2 architecture report §17
 * ambiguity #14 / §18 decision 14 anticipated and approved this second call
 * site before DiagnosticsService was written). Http/ServerRequest.php (the
 * trust-boundary owner Revision 3 names) is also on the allowlist so a
 * future internal change there is not itself a violation. Every other file
 * in src/ — including every provider, the resolver, the cache, and
 * AdminScreen — must never reference $_SERVER directly. A class needing a
 * forwarding header calls ServerRequest::header()/remote_addr(), never
 * $_SERVER itself.
 *
 * Mutation-verified when written (Revision 3 §16): a `$_SERVER['HTTP_X_REAL_IP']`
 * reference was injected into real code in a file outside the allowlist in
 * a scratch copy, the guard went red, and the injection was reverted — see
 * the M2 sub-step 2B report.
 */
final class TrustBoundaryGuardTest extends TestCase {

	use SourceGuardTrait;

	/**
	 * The only files permitted to reference $_SERVER directly, relative to
	 * src/.
	 */
	private const ALLOWED_FILES = array(
		'Plugin.php',
		'Http/ServerRequest.php',
		'Diagnostics/DiagnosticsService.php',
	);

	/**
	 * @dataProvider source_file_provider
	 */
	public function test_source_file_does_not_reference_the_dollar_server_superglobal( string $file ): void {
		$code = $this->strip_comments( (string) file_get_contents( $file ) );

		$this->assertDoesNotMatchRegularExpression(
			'/\$_SERVER\b/',
			$code,
			sprintf(
				'%s must not reference the $_SERVER superglobal directly. Only Plugin.php ' .
				'(passing it once to ServerRequest::capture()), DiagnosticsService.php ' .
				'(a second, fresh capture for drift detection), and Http/ServerRequest.php ' .
				'(the trust-boundary owner, Revision 3 §2) may — every other class reads a ' .
				'forwarding header via ServerRequest::header()/remote_addr().',
				$this->relative_source_path( $file )
			)
		);
	}

	public function source_file_provider(): array {
		$cases = array();

		foreach ( $this->source_files() as $file ) {
			$relative = $this->relative_source_path( $file );

			if ( in_array( $relative, self::ALLOWED_FILES, true ) ) {
				continue;
			}

			$cases[ $relative ] = array( $file );
		}

		return $cases;
	}
}
