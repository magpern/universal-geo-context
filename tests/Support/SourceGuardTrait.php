<?php
/**
 * Reflection-enumerated source-file helpers backing the plugin's guard tests.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Shared file-discovery and comment-stripping helpers for every guard test
 * (Revision 3 §16/§26): each guard test asserts a structural property over
 * `src/` via plain reflection and tokenization — no mocking framework, no
 * static-analysis dependency.
 */
trait SourceGuardTrait {

	/**
	 * Every .php file under src/, as absolute paths, sorted for determinism.
	 *
	 * @return string[]
	 */
	private function source_files(): array {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$files = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * A source file's path relative to src/, forward-slashed for readable,
	 * OS-independent assertion failure messages.
	 *
	 * @param string $absolute_path An absolute path returned by source_files().
	 *
	 * @return string
	 */
	private function relative_source_path( string $absolute_path ): string {
		$root = dirname( __DIR__, 2 ) . '/src/';

		return str_replace( '\\', '/', substr( $absolute_path, strlen( $root ) ) );
	}

	/**
	 * Strips every comment (line and block, including docblocks) from a PHP
	 * source string using the real tokenizer — not a regex approximation —
	 * leaving executable code and string/literal content untouched.
	 *
	 * @param string $source A complete PHP source file's contents.
	 *
	 * @return string
	 */
	private function strip_comments( string $source ): string {
		$stripped = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				[ $id, $text ] = $token;

				if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
					continue;
				}

				$stripped .= $text;
			} else {
				$stripped .= $token;
			}
		}

		return $stripped;
	}

	/**
	 * Counts constructor-call occurrences of one class (`new ClassName(`,
	 * optionally namespace-qualified) inside code that has already had its
	 * comments stripped. Does not match `instanceof`, bare type-hints, or
	 * string references — only an actual `new` expression immediately
	 * followed by that class's bare name and an opening parenthesis.
	 *
	 * @param string $code_without_comments Source with strip_comments() already applied.
	 * @param string $short_class_name      The bare class name as written at the call site, e.g. 'GeoCache'.
	 *
	 * @return int
	 */
	private function count_constructor_calls( string $code_without_comments, string $short_class_name ): int {
		$pattern = '/\bnew\s+(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\)*' . preg_quote( $short_class_name, '/' ) . '\s*\(/';

		return (int) preg_match_all( $pattern, $code_without_comments );
	}
}
