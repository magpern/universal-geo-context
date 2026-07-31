<?php
/**
 * Test factory for M10 admin UX renderers.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Support;

use UniversalGeo\Admin\AdminActionRenderer;
use UniversalGeo\Admin\AdminHeaderRenderer;
use UniversalGeo\Admin\AdminNavigationRenderer;
use UniversalGeo\Admin\QuickActionsRenderer;

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
	 * Returns a shared page header renderer.
	 *
	 * @return AdminHeaderRenderer
	 */
	public static function header(): AdminHeaderRenderer {
		return new AdminHeaderRenderer( new AdminNavigationRenderer() );
	}

	/**
	 * Returns the overview quick-actions renderer.
	 *
	 * @return QuickActionsRenderer
	 */
	public static function quick_actions(): QuickActionsRenderer {
		return new QuickActionsRenderer( self::actions() );
	}
}
