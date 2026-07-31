<?php
/**
 * Test factory for M10/M11 admin UX renderers.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Support;

use UniversalGeo\Admin\AdminActionRenderer;
use UniversalGeo\Admin\AdminComponentRenderer;
use UniversalGeo\Admin\AdminHeaderRenderer;
use UniversalGeo\Admin\AdminNotices;
use UniversalGeo\Admin\AdminPageShell;
use UniversalGeo\Admin\AdminPageShellViewModelFactory;
use UniversalGeo\Admin\DefinitionListRenderer;
use UniversalGeo\Admin\QuickActionsRenderer;
use UniversalGeo\Admin\ReportRenderer;
use UniversalGeo\Admin\SectionNavigation;
use UniversalGeo\Admin\SettingsPage;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Diagnostics\DiagnosticsService;
use UniversalGeo\Diagnostics\ProviderHealthStore;
use UniversalGeo\Http\ClientIpResolver;
use UniversalGeo\Http\TrustedProxies;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\MaxMind\UpdateScheduler;
use UniversalGeo\Providers\MaxMindProvider;
use UniversalGeo\Providers\Remote\CircuitBreaker;
use UniversalGeo\Resolver\ContextResolver;
use UniversalGeo\Tests\Support\FakeHttpTransport;
use UniversalGeo\Tests\Support\ServerRequestFactory;

/**
 * Builds admin presentation helpers for unit/integration tests.
 */
final class AdminUxFactory {

	/**
	 * Returns a shared action renderer.
	 *
	 * @return AdminActionRenderer
	 */
	public static function actions(): AdminActionRenderer {
		return new AdminActionRenderer();
	}

	/**
	 * Returns a shared component renderer.
	 *
	 * @return AdminComponentRenderer
	 */
	public static function components(): AdminComponentRenderer {
		return new AdminComponentRenderer();
	}

	/**
	 * Returns a shared page shell renderer.
	 *
	 * @return AdminPageShell
	 */
	public static function shell(): AdminPageShell {
		return new AdminPageShell( new SectionNavigation() );
	}

	/**
	 * Returns a shared page header renderer.
	 *
	 * @return AdminHeaderRenderer
	 */
	public static function header(): AdminHeaderRenderer {
		return new AdminHeaderRenderer( self::shell(), new AdminPageShellViewModelFactory() );
	}

	/**
	 * Returns the overview quick-actions renderer.
	 *
	 * @return QuickActionsRenderer
	 */
	public static function quick_actions(): QuickActionsRenderer {
		return new QuickActionsRenderer( self::actions(), self::components() );
	}

	/**
	 * Returns a report renderer backed by a minimal diagnostics service.
	 *
	 * @param DatabaseManager|null $database_manager Optional database manager override.
	 *
	 * @return ReportRenderer
	 */
	public static function report_renderer( ?DatabaseManager $database_manager = null ): ReportRenderer {
		return new ReportRenderer(
			new DefinitionListRenderer( self::diagnostics( $database_manager ) )
		);
	}

	/**
	 * Builds a settings page wired like production admin screens.
	 *
	 * @param DatabaseManager $database_manager Managed database dependency.
	 *
	 * @return SettingsPage
	 */
	public static function settings_page( DatabaseManager $database_manager ): SettingsPage {
		return new SettingsPage(
			new UpdateScheduler( $database_manager ),
			$database_manager,
			new AdminNotices(),
			self::header(),
			self::actions(),
			self::components(),
			self::report_renderer( $database_manager )
		);
	}

	/**
	 * Returns a minimal diagnostics service for admin presentation tests.
	 *
	 * @param DatabaseManager|null $database_manager Optional database manager override.
	 *
	 * @return DiagnosticsService
	 */
	public static function diagnostics( ?DatabaseManager $database_manager = null ): DiagnosticsService {
		$database_manager = $database_manager ?? new DatabaseManager(
			sys_get_temp_dir() . '/ugeo-admin-test-unused',
			'',
			'',
			true,
			new FakeHttpTransport(),
			new ArchiveExtractor(),
			new UpdateLock()
		);
		$request         = ServerRequestFactory::make();
		$trusted_proxies = new TrustedProxies( array(), false );
		$ip_resolver     = new ClientIpResolver( $request, $trusted_proxies );
		$resolver        = new ContextResolver( $ip_resolver, array(), new GeoCache( false, 900, 'sig' ) );

		return new DiagnosticsService(
			$resolver,
			$ip_resolver,
			$request,
			$trusted_proxies,
			array(),
			new ProviderHealthStore(),
			new MaxMindProvider( '' ),
			new CircuitBreaker(),
			'none',
			$database_manager,
			'none'
		);
	}
}
