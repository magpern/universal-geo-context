<?php
/**
 * Admin page contract for Universal Geo Context.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * One registered wp-admin screen under the plugin's top-level menu.
 *
 * @internal
 */
interface Page {

	/**
	 * Returns the page slug.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Returns the page title.
	 *
	 * @return string
	 */
	public function title(): string;

	/**
	 * Returns the submenu label.
	 *
	 * @return string
	 */
	public function menu_title(): string;

	/**
	 * Returns the required capability.
	 *
	 * @return string
	 */
	public function capability(): string;

	/**
	 * Renders the page body.
	 *
	 * @return void
	 */
	public function render(): void;
}
