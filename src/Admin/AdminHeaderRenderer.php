<?php
/**
 * Reusable admin page header (title, description, navigation, actions).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Consistent page header for every Universal Geo Context admin screen.
 *
 * @internal
 * @final
 */
final class AdminHeaderRenderer {

	/**
	 * Stores the shell dependencies.
	 *
	 * @param AdminPageShell                 $shell    Branded page shell.
	 * @param AdminPageShellViewModelFactory $factory Shell view-model factory.
	 */
	public function __construct(
		private readonly AdminPageShell $shell,
		private readonly AdminPageShellViewModelFactory $factory
	) {
	}

	/**
	 * Renders the page header block.
	 *
	 * @param string        $active_slug Active page slug.
	 * @param string        $title       Page title (h1).
	 * @param callable|null $actions     Optional callback that echoes contextual actions.
	 * @param bool          $has_save    Whether to show header save button.
	 *
	 * @return void
	 */
	public function render( string $active_slug, string $title, ?callable $actions = null, bool $has_save = false ): void {
		$view_model = $this->factory->build( $active_slug, $title, $has_save );
		$this->shell->render_header( $view_model, $actions );
	}

	/**
	 * Returns the page shell renderer.
	 */
	public function shell(): AdminPageShell {
		return $this->shell;
	}
}
