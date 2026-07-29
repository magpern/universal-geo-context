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
 *    `file_get_contents('http...')` appear nowhere in `src/` (P4 — M4 will
 *    relax this with its own allowlist, extending rather than deleting this
 *    test).
 * 7. `VisitorContext` declares no property named `ip`, `ip_address`, or
 *    `client_ip` (P1; reflection, not source-text).
 *
 * Mutation-verified (recorded per Revision 3 §16's convention): in a scratch
 * copy, (a) an unauthorized `update_option()` call was added to
 * `WooCommerceProvider::resolve()` — this test went red on rule 5; (b) a
 * `hash_hmac()` call was added to `ContextResolver::resolve_via_providers()`
 * — this test went red on rule 1. Both mutations were reverted; the scratch
 * copy was discarded, not committed.
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

	private const USER_META_ALLOWED_FILE = 'Admin/AdminScreen.php';

	private const OPTION_WRITE_FUNCTIONS = array(
		'update_option',
		'add_option',
	);

	/**
	 * The explicit option-writer allowlist (rule 5). ProviderHealthStore.php
	 * is listed even before it exists (3D) — its absence does not make this
	 * rule vacuous, since the other two files already exercise it; 3D adds a
	 * dedicated positive test requiring the file once it lands.
	 */
	private const OPTION_WRITE_ALLOWED_FILES = array(
		'Settings.php',
		'Cache/GeoCache.php',
		'Diagnostics/ProviderHealthStore.php',
	);

	private const REMOTE_HTTP_FUNCTIONS = array(
		'wp_remote_get',
		'wp_remote_post',
		'wp_remote_request',
		'curl_init',
	);

	private const FORBIDDEN_IP_PROPERTY_NAMES = array(
		'ip',
		'ip_address',
		'client_ip',
	);

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
