<?php
/**
 * Guard test: the plugin's privacy floor — no raw IP persistence, ever.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Guards;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Model\VisitorContext;
use UniversalGeo\Tests\Support\SourceGuardTrait;

/**
 * The fourth and final capped guard test (Revision 3 §2; M3 architecture
 * report §6 3A): formalizes, by source inspection and reflection, every
 * privacy invariant `docs/PRIVACY.md` already documented and M1/M2 already
 * satisfied by review only. Lands before `MaxMindProvider` — the first new
 * IP-consuming code — per the M3 report's G8 ordering principle.
 *
 * Rules enforced, each via one data-provider-driven test over `src/`'s
 * tokenizer-stripped source (matching the house style of
 * `TrustBoundaryGuardTest` / `NoPolicyGuardTest`):
 *
 * 1. `hash_hmac` appears only in `src/Cache/GeoCache.php` (P2).
 * 2. `wp_cache_set` / `wp_cache_get` / `wp_cache_delete` / `wp_cache_add`
 *    appear only in `src/Cache/GeoCache.php` (P2).
 * 3. `set_transient` / `get_transient` appear nowhere in `src/`.
 * 4. `update_user_meta` / `add_user_meta` appear only in
 *    `src/Admin/AdminScreen.php`.
 * 5. `update_option` / `add_option` appear only in the explicit allowlist:
 *    `src/Settings.php`, `src/Cache/GeoCache.php`,
 *    `src/Diagnostics/ProviderHealthStore.php` (added in 3D — tolerated
 *    absent until then; 3D tightens this to a positive requirement, see
 *    `test_provider_health_store_is_a_real_option_writer_once_it_exists()`).
 * 6. `wp_remote_get` / `wp_remote_post` / `wp_remote_request` / `curl_init` /
 *    `file_get_contents('http...')` appear nowhere in `src/` (P4) — no
 *    allowlist exists for these; M4's reference remote provider uses
 *    `wp_safe_remote_get()` exclusively (rule 8, below), a distinct function
 *    this rule does not name.
 * 7. `VisitorContext` declares no property named `ip`, `ip_address`, or
 *    `client_ip` (P1; reflection, not source-text).
 * 8. `wp_safe_remote_get` appears only in
 *    `src/Providers/Remote/WordPressHttpTransport.php` (M4 — the one
 *    production file allowed to perform outbound HTTP). Tolerated absent
 *    until 4C; once the file exists it must be a real caller, or the
 *    allowlist entry would be vacuous (the `ProviderHealthStore` pattern
 *    from rule 5). The allowlist is per-file *and* per-function: rule 6's
 *    prohibition on `wp_remote_get`/`wp_remote_post`/`wp_remote_request`/
 *    `curl_init` still applies inside `WordPressHttpTransport.php` itself —
 *    there is no blanket file exemption from rule 6.
 *
 * Mutation-verified (recorded per Revision 3 §16's convention): in a scratch
 * copy, (a) an unauthorized `update_option()` call was added to
 * `WooCommerceProvider::resolve()` — this test went red on rule 5; (b) a
 * `hash_hmac()` call was added to `ContextResolver::resolve_via_providers()`
 * — this test went red on rule 1. Both mutations were reverted; the scratch
 * copy was discarded, not committed.
 *
 * M4 mutation verification (rules 6/8, recorded in the 4A sub-step report):
 * in a scratch copy, (c) `wp_safe_remote_get()` was added to
 * `MaxMindProvider.php` (an unauthorized provider file) — rule 8 went red;
 * (d) `wp_remote_post()` was added to `WordPressHttpTransport.php` once it
 * existed — rule 6 went red, proving the allowlist is per-function, not a
 * blanket file exemption. Both mutations were reverted; the scratch copy was
 * discarded, not committed.
 */
final class PrivacyGuardTest extends TestCase {

	use SourceGuardTrait;

	private const HASH_HMAC_ALLOWED_FILE = 'Cache/GeoCache.php';

	private const WP_CACHE_FUNCTIONS = array(
		'wp_cache_set',
		'wp_cache_get',
		'wp_cache_delete',
		'wp_cache_add',
	);

	private const WP_CACHE_ALLOWED_FILE = 'Cache/GeoCache.php';

	private const TRANSIENT_FUNCTIONS = array(
		'set_transient',
		'get_transient',
	);

	private const USER_META_FUNCTIONS = array(
		'update_user_meta',
		'add_user_meta',
	);

	private const USER_META_ALLOWED_FILE = 'Admin/FirstRunNotice.php';

	private const OPTION_WRITE_FUNCTIONS = array(
		'update_option',
		'add_option',
	);

	/**
	 * The explicit option-writer allowlist (rule 5). ProviderHealthStore.php
	 * is listed even before it exists (3D) — its absence does not make this
	 * rule vacuous, since the other two files already exercise it; 3D adds a
	 * dedicated positive test requiring the file once it lands. M4 adds
	 * `Providers/Remote/CircuitBreaker.php`, the fifth and — per ADR-0005's
	 * own closing consequence ("cannot add a fifth option writer... without
	 * this guard going red first") — deliberately reviewed exception. M6
	 * adds `MaxMind/UpdateLock.php` (the sixth, already a real writer of its
	 * own `universal_geo_maxmind_update_lock` option) and
	 * `MaxMind/DatabaseManager.php` (the seventh, listed ahead of its own
	 * existence — the same tolerated-absent pattern ProviderHealthStore's
	 * entry established; 6D adds its own non-vacuity test once it lands).
	 */
	private const OPTION_WRITE_ALLOWED_FILES = array(
		'Settings.php',
		'Cache/GeoCache.php',
		'Diagnostics/ProviderHealthStore.php',
		'Providers/Remote/CircuitBreaker.php',
		'MaxMind/UpdateLock.php',
		'MaxMind/DatabaseManager.php',
	);

	private const REMOTE_HTTP_FUNCTIONS = array(
		'wp_remote_get',
		'wp_remote_post',
		'wp_remote_request',
		'curl_init',
	);

	private const SAFE_REMOTE_GET_FUNCTION = 'wp_safe_remote_get';

	private const SAFE_REMOTE_GET_ALLOWED_FILE = 'Providers/Remote/WordPressHttpTransport.php';

	private const FORBIDDEN_IP_PROPERTY_NAMES = array(
		'ip',
		'ip_address',
		'client_ip',
	);

	/**
	 * Rule 9 (M6): filesystem-write primitives confined to the MaxMind/
	 * directory as a whole — a directory-level allowlist, unlike every
	 * other rule's single-file allowlist, since M6's managed-database
	 * feature spreads filesystem writes across several small, single-
	 * responsibility classes (ArchiveExtractor streams archive entries to
	 * disk, DatabaseManager performs the atomic install/rollback renames)
	 * rather than funnelling them through one file the way option writes
	 * funnel through Settings.php.
	 */
	private const FILE_WRITE_FUNCTIONS = array(
		'file_put_contents',
		'fopen',
		'fwrite',
		'rename',
		'unlink',
		'mkdir',
		'rmdir',
		'copy',
	);

	private const FILE_WRITE_ALLOWED_DIRECTORY = 'MaxMind/';

	// ---- Rule 1: hash_hmac confined to GeoCache -------------------------------

	/**
	 * @dataProvider source_file_provider
	 */
	public function test_hash_hmac_appears_only_in_geo_cache( string $file ): void {
		$relative = $this->relative_source_path( $file );
		$code     = $this->strip_comments( (string) file_get_contents( $file ) );

		if ( self::HASH_HMAC_ALLOWED_FILE === $relative ) {
			$this->assertMatchesRegularExpression( '/\bhash_hmac\s*\(/', $code, self::HASH_HMAC_ALLOWED_FILE . ' should itself call hash_hmac() — otherwise this rule is vacuous.' );
			return;
		}

		$this->assertDoesNotMatchRegularExpression(
			'/\bhash_hmac\s*\(/',
			$code,
			sprintf( '%s must not call hash_hmac() — only %s may hash an IP.', $relative, self::HASH_HMAC_ALLOWED_FILE )
		);
	}

	// ---- Rule 2: wp_cache_* confined to GeoCache -------------------------------

	/**
	 * @dataProvider source_file_provider
	 */
	public function test_wp_cache_functions_appear_only_in_geo_cache( string $file ): void {
		$relative = $this->relative_source_path( $file );

		if ( self::WP_CACHE_ALLOWED_FILE === $relative ) {
			$this->markTestSkipped( $relative . ' is the allowed object-cache writer.' );
		}

		$code = $this->strip_comments( (string) file_get_contents( $file ) );

		foreach ( self::WP_CACHE_FUNCTIONS as $function ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b' . preg_quote( $function, '/' ) . '\s*\(/',
				$code,
				sprintf( '%s must not call %s() — only %s may touch the object cache.', $relative, $function, self::WP_CACHE_ALLOWED_FILE )
			);
		}
	}

	public function test_geo_cache_actually_uses_the_object_cache(): void {
		foreach ( $this->source_files() as $file ) {
			if ( self::WP_CACHE_ALLOWED_FILE !== $this->relative_source_path( $file ) ) {
				continue;
			}

			$code = $this->strip_comments( (string) file_get_contents( $file ) );

			$this->assertMatchesRegularExpression( '/\bwp_cache_set\s*\(/', $code );
			$this->assertMatchesRegularExpression( '/\bwp_cache_get\s*\(/', $code );

			return;
		}

		$this->fail( self::WP_CACHE_ALLOWED_FILE . ' not found.' );
	}

	// ---- Rule 3: no transients anywhere in src/ --------------------------------

	/**
	 * @dataProvider source_file_provider
	 */
	public function test_transient_functions_never_appear( string $file ): void {
		$relative = $this->relative_source_path( $file );
		$code     = $this->strip_comments( (string) file_get_contents( $file ) );

		foreach ( self::TRANSIENT_FUNCTIONS as $function ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b' . preg_quote( $function, '/' ) . '\s*\(/',
				$code,
				sprintf( '%s must not call %s() — transients are rejected outright (write amplification).', $relative, $function )
			);
		}
	}

	// ---- Rule 4: user meta writes confined to AdminScreen ----------------------

	/**
	 * @dataProvider source_file_provider
	 */
	public function test_user_meta_functions_appear_only_in_admin_screen( string $file ): void {
		$relative = $this->relative_source_path( $file );

		if ( self::USER_META_ALLOWED_FILE === $relative ) {
			$this->markTestSkipped( $relative . ' is the allowed user-meta writer.' );
		}

		$code = $this->strip_comments( (string) file_get_contents( $file ) );

		foreach ( self::USER_META_FUNCTIONS as $function ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b' . preg_quote( $function, '/' ) . '\s*\(/',
				$code,
				sprintf( '%s must not call %s() — only %s may write user meta.', $relative, $function, self::USER_META_ALLOWED_FILE )
			);
		}
	}

	// ---- Rule 5: option writes confined to the explicit allowlist --------------

	/**
	 * @dataProvider source_file_provider
	 */
	public function test_option_write_functions_appear_only_in_the_allowed_files( string $file ): void {
		$relative = $this->relative_source_path( $file );

		if ( in_array( $relative, self::OPTION_WRITE_ALLOWED_FILES, true ) ) {
			$this->markTestSkipped( $relative . ' is an allowed option writer.' );
		}

		$code = $this->strip_comments( (string) file_get_contents( $file ) );

		foreach ( self::OPTION_WRITE_FUNCTIONS as $function ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b' . preg_quote( $function, '/' ) . '\s*\(/',
				$code,
				sprintf(
					'%s must not call %s() — only %s may write the options this plugin owns.',
					$relative,
					$function,
					implode( ', ', self::OPTION_WRITE_ALLOWED_FILES )
				)
			);
		}
	}

	/**
	 * 3D tightens tolerance to a requirement: once ProviderHealthStore.php
	 * exists, it must actually be a real option writer, or rule 5's
	 * allowlist entry for it would be vacuous (mirroring how
	 * CompositionRootTest avoids the same failure mode).
	 */
	public function test_provider_health_store_is_a_real_option_writer_once_it_exists(): void {
		$path = dirname( __DIR__, 3 ) . '/src/Diagnostics/ProviderHealthStore.php';

		if ( ! is_file( $path ) ) {
			$this->markTestSkipped( 'ProviderHealthStore.php does not exist yet (pre-3D).' );
		}

		$code = $this->strip_comments( (string) file_get_contents( $path ) );

		$this->assertTrue(
			1 === preg_match( '/\b(?:update_option|add_option)\s*\(/', $code ),
			'ProviderHealthStore.php exists but does not call update_option()/add_option() — the allowlist entry would be vacuous.'
		);
	}

	/**
	 * M4: CircuitBreaker.php must actually be a real option writer, or its
	 * rule-5 allowlist entry would be vacuous — the same non-vacuity check
	 * as ProviderHealthStore's own.
	 */
	public function test_circuit_breaker_is_a_real_option_writer_once_it_exists(): void {
		$path = dirname( __DIR__, 3 ) . '/src/Providers/Remote/CircuitBreaker.php';

		if ( ! is_file( $path ) ) {
			$this->markTestSkipped( 'CircuitBreaker.php does not exist yet (pre-4D).' );
		}

		$code = $this->strip_comments( (string) file_get_contents( $path ) );

		$this->assertTrue(
			1 === preg_match( '/\b(?:update_option|add_option)\s*\(/', $code ),
			'CircuitBreaker.php exists but does not call update_option()/add_option() — the allowlist entry would be vacuous.'
		);
	}

	/**
	 * M6: UpdateLock.php must actually be a real option writer.
	 */
	public function test_update_lock_is_a_real_option_writer(): void {
		$path = dirname( __DIR__, 3 ) . '/src/MaxMind/UpdateLock.php';

		$code = $this->strip_comments( (string) file_get_contents( $path ) );

		$this->assertTrue(
			1 === preg_match( '/\b(?:update_option|add_option)\s*\(/', $code ),
			'UpdateLock.php does not call update_option()/add_option() — the allowlist entry would be vacuous.'
		);
	}

	/**
	 * M6: DatabaseManager.php must actually be a real option writer once it
	 * exists (tolerated absent until 6D) — the same non-vacuity pattern
	 * ProviderHealthStore's own entry established.
	 */
	public function test_database_manager_is_a_real_option_writer_once_it_exists(): void {
		$path = dirname( __DIR__, 3 ) . '/src/MaxMind/DatabaseManager.php';

		if ( ! is_file( $path ) ) {
			$this->markTestSkipped( 'DatabaseManager.php does not exist yet (pre-6D).' );
		}

		$code = $this->strip_comments( (string) file_get_contents( $path ) );

		$this->assertTrue(
			1 === preg_match( '/\b(?:update_option|add_option)\s*\(/', $code ),
			'DatabaseManager.php exists but does not call update_option()/add_option() — the allowlist entry would be vacuous.'
		);
	}

	// ---- Rule 6: no remote HTTP primitives anywhere in src/ ---------------------

	/**
	 * @dataProvider source_file_provider
	 */
	public function test_remote_http_functions_never_appear( string $file ): void {
		$relative = $this->relative_source_path( $file );
		$code     = $this->strip_comments( (string) file_get_contents( $file ) );

		foreach ( self::REMOTE_HTTP_FUNCTIONS as $function ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b' . preg_quote( $function, '/' ) . '\s*\(/',
				$code,
				sprintf( '%s must not call %s() — no provider performs outbound HTTP in v1.', $relative, $function )
			);
		}

		$this->assertDoesNotMatchRegularExpression(
			'/\bfile_get_contents\s*\(\s*[\'"]https?:/i',
			$code,
			sprintf( '%s must not use file_get_contents() to fetch a URL.', $relative )
		);
	}

	// ---- Rule 8: wp_safe_remote_get confined to WordPressHttpTransport --------

	/**
	 * @dataProvider source_file_provider
	 */
	public function test_safe_remote_get_appears_only_in_the_allowed_transport_file( string $file ): void {
		$relative = $this->relative_source_path( $file );

		if ( self::SAFE_REMOTE_GET_ALLOWED_FILE === $relative ) {
			$this->markTestSkipped( $relative . ' is the allowed outbound HTTP transport.' );
		}

		$code = $this->strip_comments( (string) file_get_contents( $file ) );

		$this->assertDoesNotMatchRegularExpression(
			'/\b' . preg_quote( self::SAFE_REMOTE_GET_FUNCTION, '/' ) . '\s*\(/',
			$code,
			sprintf(
				'%s must not call %s() — only %s may perform outbound HTTP.',
				$relative,
				self::SAFE_REMOTE_GET_FUNCTION,
				self::SAFE_REMOTE_GET_ALLOWED_FILE
			)
		);
	}

	/**
	 * Tolerated absent until 4C; once WordPressHttpTransport.php exists, it
	 * must actually call wp_safe_remote_get(), or the allowlist entry above
	 * would be vacuous (the same non-vacuity pattern
	 * test_provider_health_store_is_a_real_option_writer_once_it_exists()
	 * already established for rule 5).
	 */
	public function test_wordpress_http_transport_is_a_real_safe_remote_get_caller_once_it_exists(): void {
		$path = dirname( __DIR__, 3 ) . '/src/' . self::SAFE_REMOTE_GET_ALLOWED_FILE;

		if ( ! is_file( $path ) ) {
			$this->markTestSkipped( self::SAFE_REMOTE_GET_ALLOWED_FILE . ' does not exist yet (pre-4C).' );
		}

		$code = $this->strip_comments( (string) file_get_contents( $path ) );

		$this->assertTrue(
			1 === preg_match( '/\b' . preg_quote( self::SAFE_REMOTE_GET_FUNCTION, '/' ) . '\s*\(/', $code ),
			self::SAFE_REMOTE_GET_ALLOWED_FILE . ' exists but does not call wp_safe_remote_get() — the allowlist entry would be vacuous.'
		);
	}

	// ---- Rule 9: filesystem-write primitives confined to MaxMind/ (M6) ---------

	/**
	 * @dataProvider source_file_provider
	 */
	public function test_file_write_functions_appear_only_under_the_maxmind_directory( string $file ): void {
		$relative = $this->relative_source_path( $file );

		if ( str_starts_with( $relative, self::FILE_WRITE_ALLOWED_DIRECTORY ) ) {
			$this->markTestSkipped( $relative . ' is under the allowed MaxMind/ directory.' );
		}

		$code = $this->strip_comments( (string) file_get_contents( $file ) );

		foreach ( self::FILE_WRITE_FUNCTIONS as $function ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b' . preg_quote( $function, '/' ) . '\s*\(/',
				$code,
				sprintf( '%s must not call %s() — filesystem writes are confined to the MaxMind/ directory.', $relative, $function )
			);
		}
	}

	/**
	 * Non-vacuity: at least one file under MaxMind/ must actually call at
	 * least one of the file-write functions, or this rule's allowlist would
	 * be vacuous.
	 */
	public function test_at_least_one_maxmind_file_actually_writes_the_filesystem(): void {
		$found = false;

		foreach ( $this->source_files() as $file ) {
			if ( ! str_starts_with( $this->relative_source_path( $file ), self::FILE_WRITE_ALLOWED_DIRECTORY ) ) {
				continue;
			}

			$code = $this->strip_comments( (string) file_get_contents( $file ) );

			foreach ( self::FILE_WRITE_FUNCTIONS as $function ) {
				if ( 1 === preg_match( '/\b' . preg_quote( $function, '/' ) . '\s*\(/', $code ) ) {
					$found = true;
					break 2;
				}
			}
		}

		$this->assertTrue( $found, 'No file under MaxMind/ calls any file-write function — the allowlist entry would be vacuous.' );
	}

	// ---- Rule 7: VisitorContext carries no IP field ----------------------------

	public function test_visitor_context_declares_no_ip_property(): void {
		$declared = array_map(
			static fn( $property ) => $property->getName(),
			( new ReflectionClass( VisitorContext::class ) )->getProperties()
		);

		foreach ( self::FORBIDDEN_IP_PROPERTY_NAMES as $forbidden ) {
			$this->assertNotContains(
				$forbidden,
				$declared,
				sprintf( 'VisitorContext must not declare a property named "%s".', $forbidden )
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
