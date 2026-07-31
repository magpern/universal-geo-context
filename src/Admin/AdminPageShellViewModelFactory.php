<?php
/**
 * Builds admin page shell view models.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Admin\ViewModel\AdminPageShellViewModel;
use UniversalGeo\Admin\ViewModel\SectionNavItemViewModel;

/**
 * Factory for shell view models from registry metadata.
 *
 * @internal
 * @final
 */
final class AdminPageShellViewModelFactory {

	/**
	 * Plugin brand title shown in the shell header.
	 */
	private const PLUGIN_TITLE = 'Universal Geo Context';

	/**
	 * Dashicon for the plugin mark.
	 */
	private const PLUGIN_MARK_ICON = 'dashicons-location-alt';

	/**
	 * Icon class per page slug.
	 *
	 * @var array<string, string>
	 */
	private const ICONS = array(
		AdminPageSlugs::OVERVIEW        => 'dashicons-dashboard',
		AdminPageSlugs::SETTINGS        => 'dashicons-admin-settings',
		AdminPageSlugs::DETECTION       => 'dashicons-search',
		AdminPageSlugs::PROVIDERS       => 'dashicons-networking',
		AdminPageSlugs::TRUSTED_PROXIES => 'dashicons-shield',
		AdminPageSlugs::DIAGNOSTICS     => 'dashicons-heart',
	);

	/**
	 * Builds a shell view model for one admin page.
	 *
	 * @param string $active_slug Active page slug.
	 * @param string $page_title  Page title (section heading).
	 * @param bool   $has_save    Whether the page shows header save.
	 */
	public function build( string $active_slug, string $page_title, bool $has_save = false ): AdminPageShellViewModel {
		return new AdminPageShellViewModel(
			self::PLUGIN_TITLE,
			$page_title,
			AdminPageRegistry::description( $active_slug ),
			$active_slug,
			$this->navigation_items( $active_slug ),
			$has_save
		);
	}

	/**
	 * Returns the plugin mark dashicon class.
	 */
	public static function plugin_mark_icon(): string {
		return self::PLUGIN_MARK_ICON;
	}

	/**
	 * Builds navigation item view models.
	 *
	 * @param string $active_slug Active page slug.
	 *
	 * @return list<SectionNavItemViewModel>
	 */
	private function navigation_items( string $active_slug ): array {
		$items = array();

		foreach ( AdminPageRegistry::navigation_items() as $item ) {
			$slug = $item['slug'];

			$items[] = new SectionNavItemViewModel(
				$slug,
				$item['label'],
				self::ICONS[ $slug ] ?? 'dashicons-admin-generic',
				AdminPageRegistry::page_url( $slug ),
				$slug === $active_slug
			);
		}

		return $items;
	}
}
