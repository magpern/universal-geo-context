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
use UniversalGeo\Admin\AdminPageShell;
use UniversalGeo\Admin\AdminPageShellViewModelFactory;
use UniversalGeo\Admin\QuickActionsRenderer;
use UniversalGeo\Admin\SectionNavigation;

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
}
