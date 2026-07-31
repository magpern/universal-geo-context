<?php
/**
 * View model for one shell navigation item.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin\ViewModel;

/**
 * Navigation item data for the admin page shell.
 *
 * @internal
 */
final class SectionNavItemViewModel {

	/**
	 * @param string $slug       Page slug.
	 * @param string $label      Visible label.
	 * @param string $icon_class Dashicon class.
	 * @param string $url        Admin URL.
	 * @param bool   $is_active  Whether this item is active.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $label,
		public readonly string $icon_class,
		public readonly string $url,
		public readonly bool $is_active
	) {
	}
}
