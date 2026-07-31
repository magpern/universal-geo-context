<?php
/**
 * Unit tests for AdminComponentRenderer markup contracts.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Admin\AdminComponentRenderer;

/**
 * Covers design-system component output and accessibility attributes.
 */
final class AdminComponentRendererTest extends TestCase {

	/**
	 * Component renderer under test.
	 *
	 * @var AdminComponentRenderer
	 */
	private AdminComponentRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new AdminComponentRenderer();
	}

	public function test_status_badge_renders_accessible_label_and_variant(): void {
		$html = $this->renderer->status_badge( 'Active', 'active' );

		$this->assertStringContainsString( 'ugc-ui-status-badge--active', $html );
		$this->assertStringContainsString( 'ugc-ui-status-badge__label', $html );
		$this->assertStringContainsString( 'Active', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}

	public function test_statistics_card_renders_grid_tile_markup(): void {
		$html = $this->renderer->statistics_card( 'Effective Country', 'SE', 'cloudflare' );

		$this->assertStringContainsString( 'ugc-ui-statistics-card__label', $html );
		$this->assertStringContainsString( 'ugc-ui-statistics-card__value', $html );
		$this->assertStringContainsString( 'SE', $html );
	}

	public function test_settings_card_open_includes_header_and_body(): void {
		$html = $this->renderer->settings_card_open( 'General', 'Defaults and cache.' );

		$this->assertStringContainsString( 'ugc-ui-settings-card', $html );
		$this->assertStringContainsString( 'ugc-ui-settings-card__body', $html );
		$this->assertStringContainsString( 'General', $html );
	}

	public function test_pill_navigation_marks_active_item(): void {
		$html = $this->renderer->pill_navigation(
			'Panels',
			array(
				array(
					'url'    => 'https://example.test/a',
					'label'  => 'Detection',
					'icon'   => 'dashicons-search',
					'active' => true,
				),
			)
		);

		$this->assertStringContainsString( 'ugc-ui-pill-nav', $html );
		$this->assertStringContainsString( 'ugc-ui-pill-nav__item--active', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
	}

	public function test_sticky_save_bar_includes_scope_attributes(): void {
		$html = $this->renderer->sticky_save_bar( 'settings' );

		$this->assertStringContainsString( 'data-ugc-sticky-save', $html );
		$this->assertStringContainsString( 'data-ugc-sticky-scope="settings"', $html );
		$this->assertStringContainsString( 'data-ugc-unsaved-indicator', $html );
	}

	public function test_empty_state_renders_icon_and_actions(): void {
		$html = $this->renderer->empty_state(
			'dashicons-info',
			'Nothing here',
			'Try refreshing.',
			'<a href="#">Action</a>'
		);

		$this->assertStringContainsString( 'ugc-ui-empty-state', $html );
		$this->assertStringContainsString( 'Nothing here', $html );
		$this->assertStringContainsString( 'ugc-ui-empty-state__actions', $html );
	}

	public function test_toggle_row_includes_hidden_zero_value(): void {
		$html = $this->renderer->toggle_row( 'derived_cache_enabled', true, 'Enable cache', 'Requires object cache.' );

		$this->assertStringContainsString( 'type="hidden" name="derived_cache_enabled" value="0"', $html );
		$this->assertStringContainsString( 'ugc-ui-toggle-row', $html );
	}
}
