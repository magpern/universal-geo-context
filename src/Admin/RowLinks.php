<?php
/**
 * Plugin list row links.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Settings action link and Documentation/GitHub row meta on the Plugins screen.
 *
 * @internal
 * @final
 */
final class RowLinks {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'plugin_action_links_' . \plugin_basename( \UNIVERSAL_GEO_PLUGIN_FILE ), array( $this, 'add_action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'add_row_meta' ), 10, 2 );
	}

	/**
	 * Adds the Settings action link on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 *
	 * @return string[]
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=' . AdminPageSlugs::SETTINGS ) ),
			esc_html__( 'Settings', 'universal-geo-context' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Adds Documentation and GitHub row meta links.
	 *
	 * @param string[] $links       Existing row meta links.
	 * @param string   $plugin_file Plugin basename.
	 *
	 * @return string[]
	 */
	public function add_row_meta( array $links, string $plugin_file ): array {
		if ( \plugin_basename( \UNIVERSAL_GEO_PLUGIN_FILE ) !== $plugin_file ) {
			return $links;
		}

		$plugin_data = get_plugin_data( \UNIVERSAL_GEO_PLUGIN_FILE, false, false );
		$plugin_uri  = is_string( $plugin_data['PluginURI'] ?? null ) ? $plugin_data['PluginURI'] : '';

		if ( '' === $plugin_uri ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $plugin_uri ),
			esc_html__( 'GitHub', 'universal-geo-context' )
		);

		$links[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $plugin_uri . '#readme' ),
			esc_html__( 'Documentation', 'universal-geo-context' )
		);

		return $links;
	}
}
