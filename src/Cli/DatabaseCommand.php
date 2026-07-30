<?php
/**
 * WP-CLI command family: managed GeoLite2 Country database status/download/validate/remove/restore.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Cli;

use InvalidArgumentException;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\DatabaseUpdateResult;

/**
 * The five M6 `wp universal-geo database *` subcommands, registered only
 * when `defined( 'WP_CLI' ) && WP_CLI`, by `Plugin::init()`, exactly like
 * `Cli\Command`'s own admin-symmetric registration (the composition-root
 * pattern: this class is constructed here and nowhere else).
 *
 * Every command delegates to the same `DatabaseManager` instance
 * `Plugin::build_graph()` already constructed — never a second, independent
 * one, and never any resolution logic of its own. `status`/`download`/
 * `validate` support `--format`; `remove`/`restore` (destructive) use
 * `WP_CLI::confirm()` for `--yes` gating, WP-CLI's own standard idiom, which
 * exits the process on decline — not independently unit-testable beyond
 * that, the same "not unit-testable beyond X, verified live" limitation
 * `Cli\Command`'s own docblock already documents for `WP_CLI::error()`.
 *
 * No `--account-id`/`--license-key` flags exist on any command (shell-
 * history/process-list exposure) — credentials come only from the shared,
 * persisted settings/constant pair `Plugin::resolved_maxmind_credentials()`
 * already resolves.
 *
 * @internal
 * @final
 */
final class DatabaseCommand {

	/**
	 * WP-CLI output formats status/download/validate support.
	 */
	private const ALLOWED_FORMATS = array( 'table', 'json', 'yaml' );

	/**
	 * Stores the injected dependency — the same `DatabaseManager` instance
	 * `Plugin::build_graph()` already constructed for the current request.
	 *
	 * @param DatabaseManager $database_manager Backs every subcommand.
	 */
	public function __construct(
		private readonly DatabaseManager $database_manager
	) {
	}

	/**
	 * Registers the five subcommands. A no-op when WP-CLI has not actually
	 * run (`WP_CLI::add_command()`'s own guard) — safe to call unconditionally.
	 *
	 * @return void
	 */
	public function register(): void {
		\WP_CLI::add_command( 'universal-geo database status', array( $this, 'status' ) );
		\WP_CLI::add_command( 'universal-geo database download', array( $this, 'download' ) );
		\WP_CLI::add_command( 'universal-geo database validate', array( $this, 'validate' ) );
		\WP_CLI::add_command( 'universal-geo database remove', array( $this, 'remove' ) );
		\WP_CLI::add_command( 'universal-geo database restore', array( $this, 'restore' ) );
	}

	/**
	 * Reports the managed database's current status — no network call.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in this format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-geo database status
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by every WP-CLI command callable's signature.
		try {
			$format = $this->resolve_format( $assoc_args );
		} catch ( InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		$this->render( array( $this->status_payload() ), $format );
	}

	/**
	 * Downloads, validates, and installs the latest GeoLite2 Country
	 * database. Exits non-zero on failure.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in this format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-geo database download
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function download( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		try {
			$format = $this->resolve_format( $assoc_args );
		} catch ( InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		$this->render_result( $this->database_manager->download_now( 'cli' ), $format );
	}

	/**
	 * Re-validates the currently installed database, structurally — no
	 * network call, the same method the admin "Check database" action uses.
	 * Exits non-zero on failure.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in this format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-geo database validate
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function validate( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		try {
			$format = $this->resolve_format( $assoc_args );
		} catch ( InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		$this->render_result( $this->database_manager->validate_installed(), $format );
	}

	/**
	 * Removes the managed database (both the active file and any retained
	 * previous generation). Destructive — prompts for confirmation unless
	 * `--yes` is given. Exits non-zero on failure.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-geo database remove --yes
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function remove( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		\WP_CLI::confirm( __( 'Remove the managed GeoLite2 Country database?', 'universal-geo-context' ), $assoc_args );

		$this->render_result( $this->database_manager->remove_managed_database( 'cli' ), 'table' );
	}

	/**
	 * Restores the retained previous generation as the active database.
	 * Destructive (discards the current active file) — prompts for
	 * confirmation unless `--yes` is given. Exits non-zero on failure.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-geo database restore --yes
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function restore( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		\WP_CLI::confirm( __( 'Restore the previous GeoLite2 Country database version?', 'universal-geo-context' ), $assoc_args );

		$this->render_result( $this->database_manager->restore_previous( 'cli' ), 'table' );
	}

	/**
	 * Validates and normalizes --format, defaulting to 'table'.
	 *
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return string One of ALLOWED_FORMATS.
	 *
	 * @throws InvalidArgumentException When --format is set but not recognized.
	 */
	public function resolve_format( array $assoc_args ): string {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		if ( ! in_array( $format, self::ALLOWED_FORMATS, true ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Invalid --format value "%s". Must be one of: %s.',
					$format,
					implode( ', ', self::ALLOWED_FORMATS )
				)
			);
		}

		return $format;
	}

	/**
	 * The single-row payload for `status` — no network call, no Reader open
	 * (installed_path() is a cheap existence/readability check only).
	 *
	 * @return array<string, string>
	 */
	public function status_payload(): array {
		$status         = $this->database_manager->status();
		$installed_path = $this->database_manager->installed_path();

		return array(
			'installed'             => '' !== $installed_path ? 'yes' : 'no',
			'last_attempt_at'       => null !== $status['last_attempt_at'] ? gmdate( 'Y-m-d H:i:s', $status['last_attempt_at'] ) . ' UTC' : '',
			'last_success_at'       => null !== $status['last_success_at'] ? gmdate( 'Y-m-d H:i:s', $status['last_success_at'] ) . ' UTC' : '',
			'last_result_code'      => $status['last_result_code'],
			'installed_build_epoch' => null !== $status['installed_build_epoch'] ? (string) $status['installed_build_epoch'] : '',
		);
	}

	/**
	 * The single-row payload for a completed action's DatabaseUpdateResult.
	 * $result->message is printed as-is: DatabaseManager's own design
	 * already guarantees every result message is a fixed, safe string —
	 * never a raw path, credential, or redirect URL.
	 *
	 * @param DatabaseUpdateResult $result The completed action's result.
	 *
	 * @return array<string, string>
	 */
	public function result_payload( DatabaseUpdateResult $result ): array {
		return array(
			'success' => $result->success ? 'yes' : 'no',
			'code'    => $result->code,
			'message' => $result->message,
		);
	}

	/**
	 * Renders a completed action's result, then exits non-zero on failure —
	 * after rendering, never instead of it, so the informative row is always
	 * visible regardless of the exit code a calling script inspects.
	 *
	 * @param DatabaseUpdateResult $result The completed action's result.
	 * @param string               $format One of ALLOWED_FORMATS.
	 *
	 * @return void
	 */
	private function render_result( DatabaseUpdateResult $result, string $format ): void {
		$this->render( array( $this->result_payload( $result ) ), $format );

		if ( ! $result->success ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * The one place this class touches WP_CLI\Utils\format_items() —
	 * everything above builds plain arrays, this renders them. Mirrors
	 * Cli\Command::render() exactly; kept as its own small copy rather than
	 * shared, matching this codebase's "no premature abstraction" convention
	 * for two short, independently-evolving helpers.
	 *
	 * @param array<int, array<string, mixed>> $items  Rows, all sharing the same keys.
	 * @param string                           $format One of ALLOWED_FORMATS.
	 *
	 * @return void
	 */
	private function render( array $items, string $format ): void {
		if ( array() === $items ) {
			\WP_CLI::log( __( 'No data.', 'universal-geo-context' ) );
			return;
		}

		// The real `wp` binary always loads this functions file as part of
		// its own bootstrap, before any plugin's plugins_loaded fires — this
		// require is a defensive no-op there. It matters only for callers
		// (this plugin's own PHPUnit integration suite) that construct and
		// call this class directly, bypassing that bootstrap.
		if ( ! function_exists( '\WP_CLI\Utils\format_items' ) ) {
			require_once dirname( __DIR__, 2 ) . '/vendor/wp-cli/wp-cli/php/utils.php';
		}

		\WP_CLI\Utils\format_items( $format, $items, array_keys( $items[0] ) );
	}
}
