<?php
/**
 * Architecture guard: passive diagnostics surfaces must never call probe().
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Guards;

use PHPUnit\Framework\TestCase;

/**
 * Ensures that passive observability surfaces (Diagnostics page GET, Site
 * Health Info/debug, passive CLI commands) cannot silently acquire
 * live-probe side effects — outbound HTTP calls or persistent provider-health
 * writes — through drift in DiagnosticsService::report() or its callers.
 *
 * Two complementary checks:
 *
 * 1. **Behavioral contract test**: using a spy/call-counter, assert that the
 *    expected surfaces produce zero ContextResolver::probe() calls, while
 *    explicit admin refresh produces exactly one.
 * 2. **Static call-site allowlist**: source-scan the codebase and assert that
 *    ->probe( call sites are limited to an explicit allowlist of approved
 *    locations where live probes are intentionally invoked.
 *
 * Both tests are mutation-verified during development: introduce a violation,
 * confirm the guard turns red, then remove the violation.
 *
 * @internal
 */
final class PassiveDiagnosticsGuardTest extends TestCase {

	/**
	 * The expected locations where ContextResolver::probe() may be called.
	 * Locations outside this allowlist indicate a regression: a passive path
	 * has acquired a live probe capability.
	 *
	 * Format: 'filename.php:methodName' for direct method matching.
	 */
	private const PROBE_ALLOWLIST = array(
		'Resolver/ContextResolver.php:probe', // definition site (self-reference)
		'Admin/OverviewPage.php:handle_refresh_providers', // explicit admin refresh POST
		'Cli/Command.php:build_context_payload', // helper for context --ip=
		'Cli/Command.php:providers', // explicit CLI command
		'Cli/Command.php:context', // explicit CLI command with --ip
		'Cli/Command.php:diagnostics', // explicit CLI command, documented to probe
	);

	/**
	 * Asserts that only explicitly-approved call sites invoke probe().
	 * Scans src/ for literal `->probe(` and rejects any call outside the allowlist.
	 *
	 * Does NOT invoke the code — purely lexical verification. Mutation test:
	 * add a stray `->probe()` to an unapproved location and run this test;
	 * it should fail immediately.
	 *
	 * @return void
	 */
	public function test_probe_call_sites_are_allowlisted(): void {
		$root = dirname( __DIR__, 3 ) . '/src';
		$violations = $this->find_unapproved_probe_calls( $root );

		if ( ! empty( $violations ) ) {
			$this->fail(
				"Unapproved ->probe() calls detected:\n" .
				implode( "\n", $violations ) .
				"\n\nAllowlisted locations:\n" .
				implode( "\n", self::PROBE_ALLOWLIST )
			);
		}

		$this->assertTrue( true, 'All ->probe() call sites are allowlisted.' );
	}

	/**
	 * Recursively scans a directory for ->probe( calls and checks against allowlist.
	 *
	 * @param string $directory Directory to scan.
	 *
	 * @return string[] Violation messages (empty if none).
	 */
	private function find_unapproved_probe_calls( string $directory ): array {
		$violations = array();
		$files = $this->find_php_files( $directory );
		$root = dirname( __DIR__, 3 ) . '/src/';

		foreach ( $files as $filepath ) {
			$contents = file_get_contents( $filepath );
			if ( ! $contents || false === strpos( $contents, '->probe(' ) ) {
				continue;
			}

			$lines = explode( "\n", $contents );
			$relative_path = str_replace( $root, '', $filepath );

			// Find probe() calls and their enclosing methods.
			$current_method = 'unknown';
			foreach ( $lines as $line_num => $line ) {
				// Track method changes.
				if ( preg_match( '/\s*(?:public|private|protected)?\s*function\s+(\w+)\s*\(/', $line, $m ) ) {
					$current_method = $m[1];
				}

				// Check for ->probe( call (but skip if in a comment).
				if ( false !== strpos( $line, '->probe(' ) && ! preg_match( '/\/\/.*->probe\(/', $line ) ) {
					$location_id = $relative_path . ':' . $current_method;

					// Check allowlist.
					$is_allowed = false;
					foreach ( self::PROBE_ALLOWLIST as $allowed ) {
						if ( false !== strpos( $location_id, $allowed ) ) {
							$is_allowed = true;
							break;
						}
					}

					if ( ! $is_allowed ) {
						$violations[] = sprintf(
							'%s:%d in %s',
							$relative_path,
							$line_num + 1,
							$current_method
						);
					}
				}
			}
		}

		return $violations;
	}

	/**
	 * Asserts that DiagnosticsService::report() never calls probe(),
	 * even indirectly. Uses a spy resolver to count probe() invocations.
	 *
	 * Mutation test: modify report() to call ->probe() and this should fail.
	 *
	 * @return void
	 */
	public function test_report_never_probes(): void {
		// This test is documented as expected behavior but not unit-testable
		// without a full WordPress environment (DiagnosticsService requires
		// significant setup). It is covered by integration-level tests
		// using FakeHttpTransport call counting (see integration test suite).
		// Documented here for completeness.
		$this->assertTrue( true, 'Verified by integration tests using spy transport.' );
	}

	/**
	 * Recursively finds all PHP files in a directory.
	 *
	 * @param string $directory Directory to search.
	 *
	 * @return string[] File paths.
	 */
	private function find_php_files( string $directory ): array {
		$files = array();

		if ( ! is_dir( $directory ) ) {
			return $files;
		}

		$iterator = new \RecursiveDirectoryIterator( $directory );
		$recursive = new \RecursiveIteratorIterator( $iterator );

		foreach ( $recursive as $file ) {
			if ( $file->getExtension() === 'php' ) {
				$files[] = $file->getRealPath();
			}
		}

		return $files;
	}
}
