<?php
/**
 * Unit tests for admin page shell markup.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Admin\AdminPageRegistry;
use UniversalGeo\Admin\AdminPageShell;
use UniversalGeo\Admin\AdminPageShellViewModelFactory;
use UniversalGeo\Admin\AdminPageSlugs;
use UniversalGeo\Admin\SectionNavigation;

/**
 * Covers shell header and icon navigation output.
 */
final class AdminPageShellTest extends TestCase {

	public function test_shell_header_renders_branded_navigation(): void {
		$factory    = new AdminPageShellViewModelFactory();
		$shell      = new AdminPageShell( new SectionNavigation() );
		$view_model = $factory->build( AdminPageSlugs::OVERVIEW, 'Overview' );

		ob_start();
		$shell->render_header( $view_model );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'ugc-shell-header', $html );
		$this->assertStringContainsString( 'ugc-shell-nav', $html );
		$this->assertStringContainsString( 'dashicons-dashboard', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertStringContainsString( AdminPageRegistry::page_url( AdminPageSlugs::SETTINGS ), $html );
	}
}
