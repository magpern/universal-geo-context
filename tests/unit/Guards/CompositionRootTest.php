<?php
/**
 * Structural test: internal service construction is confined to Plugin.php.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Guards;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Tests\Support\SourceGuardTrait;

/**
 * Enforces the composition-root invariant added to the M2 architecture
 * report (§3/§4/§10/§16, decisions 17–18): `Plugin` is the exclusive
 * composition root for internal services — no internal service may
 * instantiate a peer internal service; every dependency arrives by
 * constructor injection from `Plugin`.
 *
 * An ordinary structural unit test, not a fifth guard test — Revision 3 §2
 * caps v1 at exactly four mutation-verified guard tests
 * (NoPolicyGuardTest, ImmutabilityGuardTest, TrustBoundaryGuardTest,
 * PrivacyGuardTest); that cap is unaffected by this file.
 *
 * Checks two things, both robustly, over `src/`'s actual `new` expressions
 * and `ServerRequest::capture()` calls (comments stripped first):
 *
 * 1. None of the named internal service classes is constructed anywhere
 *    outside Plugin.php.
 * 2. Plugin.php actually DOES construct every one of them — otherwise (1)
 *    would pass vacuously if a class were renamed, removed, or its
 *    construction silently moved (e.g. behind a factory), which would
 *    defeat the point of this guard without it ever going red.
 *
 * Deliberately does NOT attempt to detect "policy leaking into Plugin" by
 * scanning for words or patterns — that class of drift isn't reliably
 * catchable by source-text inspection and remains a code-review
 * responsibility (Plugin's own class docblock states the rule).
 */
final class CompositionRootTest extends TestCase {

	use SourceGuardTrait;

	/**
	 * Internal service classes the composition-root invariant confines to
	 * Plugin.php, checked via `new ClassName(`. ServerRequest is checked
	 * separately (via capture(), never new — its constructor is private).
	 *
	 * M5 adds two: `Command` (the WP-CLI command family, the symmetric
	 * WP-CLI-only counterpart to `AdminScreen`'s admin-only registration)
	 * and `PrivacyPolicyContent` (the `wp_add_privacy_policy_content()`
	 * text builder) — both follow the exact same "constructed once in
	 * Plugin.php, dependencies injected" shape every prior service already
	 * established, so no new pattern is introduced. M6 adds five:
	 * `DatabaseManager`, `ArchiveExtractor`, `UpdateLock`, `UpdateScheduler`,
	 * and `DatabaseCommand` (the WP-CLI database subcommand family, the
	 * symmetric counterpart to `CliCommand`) — the same shape again.
	 * `RedirectValidator` is deliberately NOT in this list: a stateless
	 * static utility (like `IpUtils`/`GeoValidator`), never instantiated
	 * anywhere.
	 */
	private const CONSTRUCTOR_CONFINED_CLASSES = array(
		'TrustedProxies',
		'ClientIpResolver',
		'GeoCache',
		'ContextResolver',
		'CloudflareHeaderProvider',
		'MaxMindProvider',
		'WooCommerceProvider',
		'DefaultCountryProvider',
		'DiagnosticsService',
		'AdminNotices',
		'ReportRenderer',
		'OverviewPage',
		'DetectionPage',
		'ProvidersPage',
		'TrustedProxiesPage',
		'DiagnosticsPage',
		'SettingsPage',
		'Menu',
		'FirstRunNotice',
		'RowLinks',
		'ProviderHealthStore',
		'ReferenceRemoteProvider',
		'CircuitBreaker',
		'WordPressHttpTransport',
		'CliCommand',
		'PrivacyPolicyContent',
		'DatabaseManager',
		'ArchiveExtractor',
		'UpdateLock',
		'UpdateScheduler',
		'DatabaseCommand',
		'SimulationCookie',
		'SimulationState',
		'SimulationContextFilter',
		'SimulationController',
		'SimulationAuthorization',
		'CountryCatalog',
		'SimulationAdminBar',
		'DetectionInspectorService',
		'ProviderExplanationBuilder',
		'ResolutionTimelineBuilder',
		'ExplanationFormatter',
		'DetectionInspectorRenderer',
		'AdminActionRenderer',
		'AdminComponentRenderer',
		'DefinitionListRenderer',
		'AdminPageShell',
		'SectionNavigation',
		'AdminPageShellViewModelFactory',
		'AdminHeaderRenderer',
		'TimelineRenderer',
		'QuickActionsRenderer',
		'AdminAssets',
	);

	/**
	 * @dataProvider other_source_file_provider
	 */
	public function test_confined_classes_are_not_constructed_outside_plugin_php( string $file ): void {
		$code     = $this->strip_comments( (string) file_get_contents( $file ) );
		$relative = $this->relative_source_path( $file );

		foreach ( self::CONSTRUCTOR_CONFINED_CLASSES as $class_name ) {
			$this->assertSame(
				0,
				$this->count_constructor_calls( $code, $class_name ),
				sprintf(
					'%s must not construct %s — Plugin is the exclusive composition root.',
					$relative,
					$class_name
				)
			);
		}
	}

	/**
	 * @dataProvider server_request_capture_confined_file_provider
	 */
	public function test_server_request_capture_is_confined_to_plugin_and_diagnostics( string $file ): void {
		$code = $this->strip_comments( (string) file_get_contents( $file ) );

		$this->assertDoesNotMatchRegularExpression(
			'/\bServerRequest::capture\s*\(/',
			$code,
			sprintf(
				'%s must not call ServerRequest::capture() — only Plugin.php (the boot-time ' .
				'snapshot) and DiagnosticsService.php (a second, fresh capture for drift ' .
				'detection) may.',
				$this->relative_source_path( $file )
			)
		);
	}

	public function test_plugin_php_actually_constructs_every_confined_class(): void {
		$code = $this->strip_comments( (string) file_get_contents( $this->plugin_php_path() ) );

		foreach ( self::CONSTRUCTOR_CONFINED_CLASSES as $class_name ) {
			$this->assertGreaterThan(
				0,
				$this->count_constructor_calls( $code, $class_name ),
				sprintf( 'Plugin.php should construct %s at least once.', $class_name )
			);
		}
	}

	public function test_plugin_php_actually_captures_server_request(): void {
		$code = $this->strip_comments( (string) file_get_contents( $this->plugin_php_path() ) );

		$this->assertMatchesRegularExpression( '/\bServerRequest::capture\s*\(/', $code );
	}

	public function other_source_file_provider(): array {
		$cases = array();

		foreach ( $this->source_files() as $file ) {
			$relative = $this->relative_source_path( $file );

			if ( 'Plugin.php' === $relative ) {
				continue;
			}

			$cases[ $relative ] = array( $file );
		}

		return $cases;
	}

	public function server_request_capture_confined_file_provider(): array {
		$allowed = array( 'Plugin.php', 'Diagnostics/DiagnosticsService.php' );
		$cases   = array();

		foreach ( $this->source_files() as $file ) {
			$relative = $this->relative_source_path( $file );

			if ( in_array( $relative, $allowed, true ) ) {
				continue;
			}

			$cases[ $relative ] = array( $file );
		}

		return $cases;
	}

	private function plugin_php_path(): string {
		foreach ( $this->source_files() as $file ) {
			if ( 'Plugin.php' === $this->relative_source_path( $file ) ) {
				return $file;
			}
		}

		$this->fail( 'src/Plugin.php not found.' );
	}
}
