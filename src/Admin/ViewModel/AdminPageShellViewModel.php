<?php
/**
 * View model for the admin page shell.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin\ViewModel;

/**
 * Shell presentation data for Universal Geo admin pages.
 *
 * @internal
 */
final class AdminPageShellViewModel {

	/**
	 * @param string                         $plugin_title    Brand title in shell header.
	 * @param string                         $page_title      Active page title.
	 * @param string                         $subtitle        Active page description.
	 * @param string                         $active_slug     Active page slug.
	 * @param list<SectionNavItemViewModel>  $navigation_items Icon navigation items.
	 * @param bool                           $has_header_save Whether header save is shown.
	 * @param string                         $notice_html     Optional notice HTML.
	 */
	public function __construct(
		public readonly string $plugin_title,
		public readonly string $page_title,
		public readonly string $subtitle,
		public readonly string $active_slug,
		public readonly array $navigation_items,
		public readonly bool $has_header_save = false,
		public readonly string $notice_html = ''
	) {
	}
}
