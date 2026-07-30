<?php
/**
 * Orchestrates managed GeoLite2 Country database downloads, validation, and
 * atomic install/rollback.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\MaxMind;

use MaxMind\Db\Reader;
use Throwable;
use UniversalGeo\Cache\GeoCache;
use UniversalGeo\Providers\Remote\HttpTransport;
use UniversalGeo\Providers\Remote\TransportException;

/**
 * The operational service wrapped around the frozen provider architecture
 * (`docs/ARCHITECTURE_FREEZE.md` §6.4) — not itself a provider, never seen
 * by `ContextResolver`. Owns the managed directory
 * (`{uploads}/universal-geo-context/maxmind/`), the
 * `universal_geo_maxmind_update_state` option, and every filesystem write
 * this feature performs.
 *
 * Every settings-derived value ($account_id, $license_key,
 * $retain_previous) arrives pre-decided by `Plugin::build_graph()` — the
 * same "settings-derived values arrive pre-decided" pattern
 * `ReferenceRemoteProvider` already follows; this class never reads
 * settings or a constant itself.
 *
 * `download_now()`, `remove_managed_database()`, and `restore_previous()`
 * are the three action methods, each acquiring `UpdateLock` for their
 * entire duration and releasing it in every path (success, failure, or a
 * thrown exception) via try/finally. Concurrency is approximate by design
 * (the lock's own documented philosophy) — the real safety net against a
 * corrupted install is the atomic-rename algorithm below, never lock
 * perfection.
 *
 * @internal
 * @final
 */
final class DatabaseManager {

	/**
	 * MaxMind's current documented GeoLite2 Country download endpoint.
	 */
	public const ENDPOINT = 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download?suffix=tar.gz';

	/**
	 * The matching `.sha256` sidecar endpoint (confirmed live during M6J
	 * acceptance: same host, same Basic Auth, same redirect-safe two-hop
	 * shape as ENDPOINT — MaxMind simply appends `.sha256` to the `suffix`
	 * query value). Response body is one line, `sha256sum`-compatible:
	 * `<64 lowercase hex chars><one or two spaces><archive filename>`.
	 */
	public const CHECKSUM_ENDPOINT = self::ENDPOINT . '.sha256';

	/**
	 * The installed database's filename, both active and previous.
	 */
	public const ACTIVE_FILENAME = 'GeoLite2-Country.mmdb';

	/**
	 * Suffix appended to ACTIVE_FILENAME for the retained prior generation.
	 */
	private const PREVIOUS_SUFFIX = '.previous';

	/**
	 * Subdirectory (same filesystem as the managed dir, so every install
	 * rename() is same-volume atomic) used for in-progress downloads and
	 * extraction.
	 */
	private const TMP_SUBDIR = 'tmp';

	/**
	 * The option this class exclusively owns for writing: last-attempt and
	 * last-success metadata (decision #7: an option, not a file — consistency
	 * with every other piece of small structured state this plugin stores).
	 */
	public const STATE_OPTION_NAME = 'universal_geo_maxmind_update_state';

	/**
	 * Timeout for the first (redirect-detection) hop. Independent of, and
	 * unaffected by, ReferenceRemoteProvider's 1-5s page-view-latency clamp —
	 * this call never runs mid-page-view.
	 */
	private const REDIRECT_CHECK_TIMEOUT_SECONDS = 10;

	/**
	 * Timeout for the second (download) hop.
	 */
	private const DOWNLOAD_TIMEOUT_SECONDS = 30;

	/**
	 * Caps the downloaded archive's size. GeoLite2 Country is a few MB; this
	 * is a generous multiple, not a tight fit — matches
	 * ArchiveExtractor::DEFAULT_MAX_EXTRACTED_BYTES.
	 */
	private const MAX_ARCHIVE_BYTES = 67108864; // 64 MiB.

	/**
	 * The floor a candidate/installed file's size must clear to even attempt
	 * opening it — well below GeoLite2 Country's real size, just enough to
	 * reject a zero-byte or truncated file cheaply, before ever invoking the
	 * reader library.
	 */
	private const MIN_PLAUSIBLE_BYTES = 1024;

	/**
	 * Caps the downloaded `.sha256` sidecar response's size — the real
	 * response is under 100 bytes; this is a generous multiple, not a tight
	 * fit, purely to bound a pathological/hostile response.
	 */
	private const MAX_CHECKSUM_BYTES = 4096;

	/**
	 * The expected shape of the checksum sidecar's filename field —
	 * confirmed live during M6J acceptance (`GeoLite2-Country_20260728.tar.gz`).
	 * A response naming something else is treated as an unavailable/untrusted
	 * checksum rather than parsed further.
	 */
	private const CHECKSUM_FILENAME_PATTERN = '/^GeoLite2-Country_\d{8}\.tar\.gz$/';

	/**
	 * The expected shape of the checksum sidecar's body: one line,
	 * `sha256sum`-compatible.
	 */
	private const CHECKSUM_LINE_PATTERN = '/^([a-f0-9]{64})\s{1,2}(\S+)\s*$/i';

	/**
	 * A fixed, well-known public IP used solely as a non-blocking smoke
	 * lookup during validation — the lookup completing without throwing is
	 * the pass condition; an empty/null result is not itself a failure
	 * (GeoLite2 Country can legitimately return no data for some
	 * addresses).
	 */
	private const SMOKE_TEST_IP = '1.1.1.1';

	/**
	 * The default, fresh state.
	 *
	 * @var array{last_attempt_at: int|null, last_success_at: int|null, last_result_code: string, installed_build_epoch: int|null}
	 */
	private const DEFAULT_STATE = array(
		'last_attempt_at'       => null,
		'last_success_at'       => null,
		'last_result_code'      => '',
		'installed_build_epoch' => null,
	);

	/**
	 * The injected clock.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Stores the injected dependencies. Every settings-derived value
	 * arrives already resolved by `Plugin::build_graph()` — this class
	 * never reads settings, a constant, or `get_option()` for its own
	 * configuration (only for its own persisted state, via
	 * STATE_OPTION_NAME).
	 *
	 * @param string               $managed_dir     Absolute path to the managed directory ({uploads}/universal-geo-context/maxmind/), no trailing slash required.
	 * @param string               $account_id      The effective MaxMind account id, or '' when none is configured.
	 * @param string               $license_key     The effective MaxMind license key, or '' when none is configured.
	 * @param bool                 $retain_previous Whether a successful install retains the prior generation as .previous.
	 * @param HttpTransport        $http_transport  Performs the redirect-safe two-hop download.
	 * @param ArchiveExtractor     $extractor    Extracts the database from the downloaded archive.
	 * @param UpdateLock           $lock            Guards this instance's three action methods against concurrent triggers.
	 * @param callable(): int|null $clock    Returns the current Unix timestamp. Defaults to `static fn(): int => time()`.
	 */
	public function __construct(
		private readonly string $managed_dir,
		private readonly string $account_id,
		private readonly string $license_key,
		private readonly bool $retain_previous,
		private readonly HttpTransport $http_transport,
		private readonly ArchiveExtractor $extractor,
		private readonly UpdateLock $lock,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * The managed GeoLite2 database directory
	 * ({uploads}/universal-geo-context/maxmind/) — the single formula both
	 * `Plugin::build_graph()` (via this method) and `uninstall.php` (via
	 * `uninstall_files()`, passed this method's own return value) use, so
	 * the two can never compute two different paths for the same thing.
	 * Never admin-configurable; a formula over `wp_upload_dir()` alone.
	 *
	 * @return string '' when wp_upload_dir() is unavailable or malformed.
	 */
	public static function managed_directory(): string {
		$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : null;
		$base       = is_array( $upload_dir ) ? ( $upload_dir['basedir'] ?? null ) : null;

		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}

		return rtrim( $base, '/' ) . '/universal-geo-context/maxmind';
	}

	/**
	 * Deletes only the fixed filenames inside $managed_dir, by exact name,
	 * never a glob, then removes the managed dir, its tmp/ subdirectory, and
	 * (if left empty) the shared "universal-geo-context" uploads parent —
	 * called from uninstall.php, all-or-nothing with every other option and
	 * file this plugin owns. A static method, not an instance one: uninstall
	 * runs in its own bootstrap, with no `Plugin::build_graph()` object graph
	 * available to construct a full `DatabaseManager` instance from.
	 *
	 * @param string $managed_dir Absolute path, typically managed_directory()'s own return value.
	 *
	 * @return void
	 */
	public static function uninstall_files( string $managed_dir ): void {
		if ( '' === $managed_dir ) {
			return;
		}

		$active   = rtrim( $managed_dir, '/' ) . '/' . self::ACTIVE_FILENAME;
		$previous = $active . self::PREVIOUS_SUFFIX;
		$tmp_dir  = rtrim( $managed_dir, '/' ) . '/' . self::TMP_SUBDIR;

		foreach ( array( $active, $previous ) as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file );
			}
		}

		if ( is_dir( $tmp_dir ) ) {
			foreach ( (array) glob( $tmp_dir . '/*' ) as $tmp_file ) {
				if ( is_file( $tmp_file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $tmp_file );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- deliberately best-effort: rmdir() fails (and warns) if the directory is somehow non-empty, which must never turn into a fatal during uninstall.
			@rmdir( $tmp_dir );
		}

		if ( is_dir( $managed_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
			@rmdir( $managed_dir );
		}

		$parent = dirname( rtrim( $managed_dir, '/' ) );

		if ( is_dir( $parent ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- a no-op when other plugins/files still occupy the shared uploads parent; never forced.
			@rmdir( $parent );
		}
	}

	/**
	 * The installed active database's path, or '' when none is installed —
	 * a cheap existence/readability check only, deliberately never opening a
	 * `Reader` here (this feeds `Plugin`'s path-resolution precedence at
	 * graph build, a per-request cost that must stay cheap).
	 *
	 * @return string
	 */
	public function installed_path(): string {
		$path = $this->active_path();

		return is_file( $path ) && is_readable( $path ) ? $path : '';
	}

	/**
	 * The persisted last-attempt/last-success state — diagnostics and
	 * Site Health only.
	 *
	 * @return array{last_attempt_at: int|null, last_success_at: int|null, last_result_code: string, installed_build_epoch: int|null}
	 */
	public function status(): array {
		return $this->read_state();
	}

	/**
	 * Re-validates the currently installed active database file,
	 * structurally — no network call, no lock (read-only, never mutates the
	 * managed directory or the lock's own state). The single method behind
	 * both the admin "Check database" action and the WP-CLI `validate`
	 * command, so the two surfaces can never disagree about what counts as
	 * valid.
	 *
	 * @return DatabaseUpdateResult
	 */
	public function validate_installed(): DatabaseUpdateResult {
		$path = $this->active_path();

		if ( ! is_file( $path ) ) {
			return DatabaseUpdateResult::failure( 'not_installed', 'No managed database is installed.' );
		}

		try {
			$this->validate_file( $path );
		} catch ( DatabaseValidationException $e ) {
			return DatabaseUpdateResult::failure( 'validation_failed', $e->getMessage() );
		}

		return DatabaseUpdateResult::success( 'ok', 'The installed database passed validation.' );
	}

	/**
	 * Downloads, validates, and installs the latest GeoLite2 Country
	 * database. See this class's docblock for the lock/action shape.
	 *
	 * @param string $owner A short, human-readable label for who triggered this ('admin', 'cron', or 'cli') — diagnostics only.
	 *
	 * @return DatabaseUpdateResult
	 */
	public function download_now( string $owner ): DatabaseUpdateResult {
		if ( '' === $this->account_id || '' === $this->license_key ) {
			return DatabaseUpdateResult::failure( 'credentials_missing', 'MaxMind credentials are not configured.' );
		}

		$token = $this->lock->acquire( $owner );

		if ( null === $token ) {
			return DatabaseUpdateResult::failure( 'already_running', 'A database update is already in progress.' );
		}

		try {
			$result = $this->run_download();
		} finally {
			$this->lock->release( $token );
		}

		return $result;
	}

	/**
	 * Removes the managed database (both the active file and any retained
	 * previous generation). Never touches `maxmind_db_path` or the
	 * WooCommerce-auto-detected path — this class owns only the managed
	 * directory.
	 *
	 * @param string $owner A short, human-readable label for who triggered this.
	 *
	 * @return DatabaseUpdateResult
	 */
	public function remove_managed_database( string $owner ): DatabaseUpdateResult {
		$token = $this->lock->acquire( $owner );

		if ( null === $token ) {
			return DatabaseUpdateResult::failure( 'already_running', 'A database update is already in progress.' );
		}

		try {
			if ( ! is_file( $this->active_path() ) && ! is_file( $this->previous_path() ) ) {
				return DatabaseUpdateResult::failure( 'not_installed', 'No managed database is installed.' );
			}

			$this->delete_if_exists( $this->active_path() );
			$this->delete_if_exists( $this->previous_path() );

			GeoCache::bump_epoch();

			$this->persist_attempt( DatabaseUpdateResult::success( 'ok', 'The managed database was removed.' ), null, true );

			return DatabaseUpdateResult::success( 'ok', 'The managed database was removed.' );
		} finally {
			$this->lock->release( $token );
		}
	}

	/**
	 * Restores the retained previous generation as the active database,
	 * re-validating it structurally before the swap — a `.previous` file
	 * that has itself become corrupt (e.g. an interrupted disk write) must
	 * never be promoted back to active.
	 *
	 * @param string $owner A short, human-readable label for who triggered this.
	 *
	 * @return DatabaseUpdateResult
	 */
	public function restore_previous( string $owner ): DatabaseUpdateResult {
		$token = $this->lock->acquire( $owner );

		if ( null === $token ) {
			return DatabaseUpdateResult::failure( 'already_running', 'A database update is already in progress.' );
		}

		try {
			$previous = $this->previous_path();

			if ( ! is_file( $previous ) ) {
				return DatabaseUpdateResult::failure( 'no_previous_version', 'No previous version is available to restore.' );
			}

			try {
				$build_epoch = $this->validate_file( $previous );
			} catch ( DatabaseValidationException $e ) {
				return DatabaseUpdateResult::failure( 'validation_failed', 'The previous version failed structural validation and was not restored.' );
			}

			$this->delete_if_exists( $this->active_path() );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- restoring a caller-controlled managed-directory file, confined to MaxMind/ (PrivacyGuardTest rule 9).
			if ( ! rename( $previous, $this->active_path() ) ) {
				return DatabaseUpdateResult::failure( 'install_failed', 'The previous version could not be restored.' );
			}

			GeoCache::bump_epoch();

			$result = DatabaseUpdateResult::success( 'ok', 'The previous version was restored.' );
			$this->persist_attempt( $result, $build_epoch );

			return $result;
		} finally {
			$this->lock->release( $token );
		}
	}

	/**
	 * The full download → validate → install → rollback → cleanup sequence,
	 * run while the lock is held.
	 *
	 * @return DatabaseUpdateResult
	 */
	private function run_download(): DatabaseUpdateResult {
		if ( ! $this->ensure_directories() ) {
			$result = DatabaseUpdateResult::failure( 'directory_not_writable', 'The managed database directory is not writable.' );
			$this->persist_attempt( $result, null );

			return $result;
		}

		$tmp_archive = $this->tmp_dir() . '/download-' . $this->unique_token() . '.tar.gz';

		$fetch = $this->fetch_archive( $tmp_archive );

		if ( ! $fetch->success ) {
			$this->cleanup_tmp();
			$this->persist_attempt( $fetch, null );

			return $fetch;
		}

		$checksum = $this->fetch_checksum();

		if ( $checksum instanceof DatabaseUpdateResult ) {
			$this->cleanup_tmp();
			$this->persist_attempt( $checksum, null );

			return $checksum;
		}

		$actual_checksum = hash_file( 'sha256', $tmp_archive );

		if ( false === $actual_checksum || ! hash_equals( $checksum, $actual_checksum ) ) {
			$this->cleanup_tmp();
			$result = DatabaseUpdateResult::failure( 'checksum_mismatch', 'The downloaded database failed checksum verification.' );
			$this->persist_attempt( $result, null );

			return $result;
		}

		$candidate = $this->tmp_dir() . '/candidate-' . $this->unique_token() . '.mmdb';

		try {
			$this->extractor->extract_country_database( $tmp_archive, $candidate );
		} catch ( ArchiveException $e ) {
			$this->cleanup_tmp();
			$result = DatabaseUpdateResult::failure( 'archive_invalid', $e->getMessage() );
			$this->persist_attempt( $result, null );

			return $result;
		}

		try {
			$candidate_epoch = $this->validate_file( $candidate );
		} catch ( DatabaseValidationException $e ) {
			$this->cleanup_tmp();
			$result = DatabaseUpdateResult::failure( 'validation_failed', $e->getMessage() );
			$this->persist_attempt( $result, null );

			return $result;
		}

		// Decision #3 (flagged): no pre-download "has it changed" check, but
		// installation itself is short-circuited when the already-validated
		// candidate's build epoch matches the currently installed one — but
		// only when a file is actually installed (M6J: a state/filesystem
		// divergence, e.g. a stale epoch surviving an out-of-band file
		// deletion, must never be trusted over the filesystem itself).
		$state = $this->read_state();

		if ( is_int( $state['installed_build_epoch'] ) && $candidate_epoch === $state['installed_build_epoch'] && is_file( $this->active_path() ) ) {
			$this->cleanup_tmp();
			$result = DatabaseUpdateResult::success( 'already_current', 'The managed database is already up to date.' );
			$this->persist_attempt( $result, $candidate_epoch );

			return $result;
		}

		$install = $this->install( $candidate );
		$this->cleanup_tmp();

		if ( ! $install->success ) {
			$this->persist_attempt( $install, null );

			return $install;
		}

		try {
			$this->validate_file( $this->active_path() );
		} catch ( DatabaseValidationException $e ) {
			$result = $this->rollback_after_failed_final_verification();
			$this->persist_attempt( $result, null );

			return $result;
		}

		if ( ! $this->retain_previous ) {
			$this->delete_if_exists( $this->previous_path() );
		}

		GeoCache::bump_epoch();

		$result = DatabaseUpdateResult::success( 'ok', 'The managed database was updated successfully.' );
		$this->persist_attempt( $result, $candidate_epoch );

		return $result;
	}

	/**
	 * The redirect-safe two-hop download (`docs/ARCHITECTURE_FREEZE.md`
	 * §12.3): the first hop carries the Basic Auth header and only checks
	 * for a redirect, never following it; the (independently validated)
	 * target is fetched by a second, separate call carrying no credentials
	 * at all. A non-redirect first-hop response is classified directly —
	 * MaxMind may answer 401/403/429/other without ever redirecting on bad
	 * credentials or rate limiting.
	 *
	 * @param string $destination Absolute path to stream the downloaded archive to.
	 *
	 * @return DatabaseUpdateResult
	 */
	private function fetch_archive( string $destination ): DatabaseUpdateResult {
		$headers = array(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication's own wire format, per RFC 7617 (mirrors ReferenceRemoteProvider's identical justification).
			'Authorization' => 'Basic ' . base64_encode( $this->account_id . ':' . $this->license_key ),
		);

		try {
			$redirect_check = $this->http_transport->get_redirect_location( self::ENDPOINT, $headers, self::REDIRECT_CHECK_TIMEOUT_SECONDS );
		} catch ( TransportException $e ) {
			return DatabaseUpdateResult::failure( 'download_failed', 'The database could not be downloaded.' );
		}

		if ( ! $redirect_check->is_redirect ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an HTTP status code is a small int, never user-facing output, never containing the URL or credentials.
			return DatabaseUpdateResult::failure( 'http_error', sprintf( 'MaxMind returned an unexpected status: %d.', $redirect_check->status_code ) );
		}

		$validated = RedirectValidator::validate( (string) $redirect_check->location );

		if ( ! $validated['ok'] ) {
			return DatabaseUpdateResult::failure( 'unsafe_redirect', 'The download target failed validation and was not followed.' );
		}

		try {
			// Deliberately an empty headers array: credentials must never
			// reach the redirect target — the entire reason this is a
			// second, separate call rather than following the redirect
			// automatically.
			$download = $this->http_transport->download( (string) $validated['url'], $destination, array(), self::DOWNLOAD_TIMEOUT_SECONDS, self::MAX_ARCHIVE_BYTES );
		} catch ( TransportException $e ) {
			return DatabaseUpdateResult::failure( 'download_failed', 'The database could not be downloaded.' );
		}

		if ( $download->status_code >= 300 && $download->status_code < 400 ) {
			// No third hop, ever — a redirect loop is structurally
			// impossible, not merely detected.
			return DatabaseUpdateResult::failure( 'unexpected_redirect', 'The download target itself returned an unexpected forwarding response.' );
		}

		if ( 200 !== $download->status_code ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			return DatabaseUpdateResult::failure( 'download_failed', sprintf( 'The download failed with status: %d.', $download->status_code ) );
		}

		return DatabaseUpdateResult::success( 'ok', 'Downloaded.' );
	}

	/**
	 * Fetches and parses the `.sha256` sidecar via the identical
	 * redirect-safe two-hop flow `fetch_archive()` uses (confirmed, during
	 * M6J live acceptance, to redirect through the same host allowlist
	 * `RedirectValidator` already governs) — the credentials-on-first-hop,
	 * empty-headers-on-second-hop guarantee applies here too. Any failure
	 * along the way, including a response that does not match the expected
	 * one-line `sha256sum` shape or references an unexpected filename, is
	 * reported as `checksum_unavailable` — this gate fails closed, exactly
	 * like the structural MMDB validation it sits alongside.
	 *
	 * @return string|DatabaseUpdateResult The lowercase hex digest on success, or a failure result.
	 */
	private function fetch_checksum(): string|DatabaseUpdateResult {
		$headers = array(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication's own wire format, per RFC 7617.
			'Authorization' => 'Basic ' . base64_encode( $this->account_id . ':' . $this->license_key ),
		);

		try {
			$redirect_check = $this->http_transport->get_redirect_location( self::CHECKSUM_ENDPOINT, $headers, self::REDIRECT_CHECK_TIMEOUT_SECONDS );
		} catch ( TransportException $e ) {
			return DatabaseUpdateResult::failure( 'checksum_unavailable', 'The database checksum could not be retrieved.' );
		}

		if ( ! $redirect_check->is_redirect ) {
			return DatabaseUpdateResult::failure( 'checksum_unavailable', 'The database checksum could not be retrieved.' );
		}

		$validated = RedirectValidator::validate( (string) $redirect_check->location );

		if ( ! $validated['ok'] ) {
			return DatabaseUpdateResult::failure( 'unsafe_redirect', 'The checksum target failed validation and was not followed.' );
		}

		$destination = $this->tmp_dir() . '/checksum-' . $this->unique_token() . '.sha256';

		try {
			// Deliberately an empty headers array, for the same reason
			// fetch_archive()'s second hop is: credentials must never reach
			// the redirect target.
			$download = $this->http_transport->download( (string) $validated['url'], $destination, array(), self::DOWNLOAD_TIMEOUT_SECONDS, self::MAX_CHECKSUM_BYTES );
		} catch ( TransportException $e ) {
			return DatabaseUpdateResult::failure( 'checksum_unavailable', 'The database checksum could not be retrieved.' );
		}

		if ( 200 !== $download->status_code || ! is_file( $destination ) ) {
			return DatabaseUpdateResult::failure( 'checksum_unavailable', 'The database checksum could not be retrieved.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this feature's own tmp file, confined to MaxMind/.
		$contents = file_get_contents( $destination );

		if ( false === $contents || ! preg_match( self::CHECKSUM_LINE_PATTERN, trim( $contents ), $matches ) ) {
			return DatabaseUpdateResult::failure( 'checksum_unavailable', 'The database checksum response was not in the expected format.' );
		}

		if ( 1 !== preg_match( self::CHECKSUM_FILENAME_PATTERN, $matches[2] ) ) {
			return DatabaseUpdateResult::failure( 'checksum_unavailable', 'The database checksum response referenced an unexpected filename.' );
		}

		return strtolower( $matches[1] );
	}

	/**
	 * Structurally validates a candidate or installed database file: (a)
	 * non-empty and within plausible size bounds, (b) opens via
	 * `MaxMind\Db\Reader`, (c) reports a `databaseType` containing
	 * 'Country', (d) has a plausible `buildEpoch` (neither zero nor in the
	 * future), and (e) a lookup against SMOKE_TEST_IP completes without
	 * throwing (an empty/null result is not itself a failure).
	 *
	 * @param string $path Absolute path to the file to validate.
	 *
	 * @return int The validated file's build epoch.
	 *
	 * @throws DatabaseValidationException When any check fails.
	 */
	private function validate_file( string $path ): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable -- reading a caller-controlled managed-directory/tmp file, confined to MaxMind/.
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw DatabaseValidationException::unreadable();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_filesize
		$size = filesize( $path );

		if ( false === $size || $size < self::MIN_PLAUSIBLE_BYTES || $size > self::MAX_ARCHIVE_BYTES ) {
			throw DatabaseValidationException::unreadable();
		}

		if ( ! class_exists( Reader::class ) ) {
			throw DatabaseValidationException::unopenable();
		}

		try {
			$reader = new Reader( $path );
		} catch ( Throwable $e ) {
			throw DatabaseValidationException::unopenable();
		}

		$metadata = $reader->metadata();

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- vendor library property names, not this codebase's own.
		if ( ! is_string( $metadata->databaseType ) || ! str_contains( $metadata->databaseType, 'Country' ) ) {
			$reader->close();
			throw DatabaseValidationException::wrong_edition();
		}

		$build_epoch = (int) $metadata->buildEpoch;
		// phpcs:enable

		if ( $build_epoch <= 0 || $build_epoch > ( $this->clock )() ) {
			$reader->close();
			throw DatabaseValidationException::implausible_metadata();
		}

		try {
			$reader->get( self::SMOKE_TEST_IP );
		} catch ( Throwable $e ) {
			$reader->close();
			throw DatabaseValidationException::lookup_threw();
		}

		$reader->close();

		return $build_epoch;
	}

	/**
	 * The atomic install: if an active file exists, it is renamed to
	 * .previous (replacing any stale .previous first) before the candidate
	 * is renamed into place as the new active file. On the second rename's
	 * failure, attempts to undo the first so the site is never left with
	 * zero active database.
	 *
	 * @param string $candidate_path Absolute path to the already-validated candidate file.
	 *
	 * @return DatabaseUpdateResult
	 */
	private function install( string $candidate_path ): DatabaseUpdateResult {
		$active         = $this->active_path();
		$previous       = $this->previous_path();
		$active_existed = is_file( $active );

		if ( $active_existed ) {
			$this->delete_if_exists( $previous );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-volume install rename, confined to MaxMind/.
			if ( ! rename( $active, $previous ) ) {
				return DatabaseUpdateResult::failure( 'install_failed', 'The database could not be installed.' );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! rename( $candidate_path, $active ) ) {
			if ( $active_existed ) {
				// Best-effort undo — if this also fails, the site is left
				// with the .previous file present but not active, which
				// installed_path()/is_available() correctly report as "no
				// managed database", never a corrupt one silently active.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $previous, $active );
			}

			return DatabaseUpdateResult::failure( 'install_failed', 'The database could not be installed.' );
		}

		return DatabaseUpdateResult::success( 'ok', 'Installed.' );
	}

	/**
	 * The step-9 fallback when the just-installed active file fails its own
	 * fresh re-validation: restores .previous if one exists and is itself
	 * still structurally valid, otherwise leaves no managed database active
	 * — never a corrupt one.
	 *
	 * @return DatabaseUpdateResult
	 */
	private function rollback_after_failed_final_verification(): DatabaseUpdateResult {
		$previous         = $this->previous_path();
		$previous_existed = is_file( $previous );
		$decision         = self::rollback_decision( $previous_existed, false );

		$this->delete_if_exists( $this->active_path() );

		if ( 'restore_previous' !== $decision ) {
			return DatabaseUpdateResult::failure( 'validation_failed', 'The updated database failed verification and was removed.' );
		}

		try {
			$this->validate_file( $previous );
		} catch ( DatabaseValidationException $e ) {
			$this->delete_if_exists( $previous );

			return DatabaseUpdateResult::failure( 'validation_failed', 'The updated database failed verification and no valid previous version could be restored.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! rename( $previous, $this->active_path() ) ) {
			return DatabaseUpdateResult::failure( 'install_failed', 'The updated database failed verification and the previous version could not be restored.' );
		}

		return DatabaseUpdateResult::failure( 'validation_failed', 'The updated database failed verification; the previous version was restored.' );
	}

	/**
	 * The rollback decision matrix: whether a previous generation existed,
	 * crossed with whether final verification passed, decides the outcome.
	 * A small, pure function — independently unit-testable with no
	 * filesystem, no reader, no I/O of any kind.
	 *
	 * @param bool $previous_existed    Whether a .previous file existed before this install attempt.
	 * @param bool $verification_passed Whether the just-installed active file's own fresh re-validation passed.
	 *
	 * @return string One of 'keep_new', 'restore_previous', 'leave_absent'.
	 */
	private static function rollback_decision( bool $previous_existed, bool $verification_passed ): string {
		if ( $verification_passed ) {
			return 'keep_new';
		}

		return $previous_existed ? 'restore_previous' : 'leave_absent';
	}

	/**
	 * Ensures the managed directory and its tmp/ subdirectory exist and are
	 * writable.
	 *
	 * @return bool
	 */
	private function ensure_directories(): bool {
		foreach ( array( $this->managed_dir, $this->tmp_dir() ) as $dir ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating this feature's own managed directory under {uploads}, confined to MaxMind/.
			if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- checking this feature's own managed directory under {uploads}, confined to MaxMind/.
		return is_writable( $this->managed_dir ) && is_writable( $this->tmp_dir() );
	}

	/**
	 * Deletes every file directly inside tmp/, unconditionally — run after
	 * every download attempt regardless of outcome. A cleanup failure is a
	 * non-blocking secondary concern, never surfaced as the action's own
	 * result.
	 *
	 * @return void
	 */
	private function cleanup_tmp(): void {
		$files = glob( $this->tmp_dir() . '/*' );

		if ( false === $files ) {
			return;
		}

		foreach ( $files as $file ) {
			$this->delete_if_exists( $file );
		}
	}

	/**
	 * Deletes $path if it is currently a file — silently a no-op otherwise
	 * (including "path is a directory", which this method never touches).
	 *
	 * @param string $path Absolute path.
	 *
	 * @return void
	 */
	private function delete_if_exists( string $path ): void {
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $path );
		}
	}

	/**
	 * The active database's absolute path.
	 *
	 * @return string
	 */
	private function active_path(): string {
		return rtrim( $this->managed_dir, '/' ) . '/' . self::ACTIVE_FILENAME;
	}

	/**
	 * The retained-prior-generation database's absolute path.
	 *
	 * @return string
	 */
	private function previous_path(): string {
		return $this->active_path() . self::PREVIOUS_SUFFIX;
	}

	/**
	 * The tmp/ subdirectory's absolute path.
	 *
	 * @return string
	 */
	private function tmp_dir(): string {
		return rtrim( $this->managed_dir, '/' ) . '/' . self::TMP_SUBDIR;
	}

	/**
	 * A short, unpredictable token for building unique tmp/ filenames —
	 * unrelated to UpdateLock's own token; this one only needs to avoid
	 * collisions between concurrent tmp/ filenames, never to authenticate
	 * anything.
	 *
	 * @return string
	 */
	private function unique_token(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( Throwable $e ) {
			return uniqid( '', true );
		}
	}

	/**
	 * Records a completed action attempt into the persisted state: always
	 * updates last_attempt_at/last_result_code; on success, also updates
	 * last_success_at and (when known) installed_build_epoch.
	 *
	 * @param DatabaseUpdateResult $result      The completed action's result.
	 * @param int|null             $build_epoch The installed file's build epoch, when this attempt is known to have left one installed (null leaves the previously stored value unchanged, unless $clear_epoch is true).
	 * @param bool                 $clear_epoch When true (only `remove_managed_database()` uses this), resets installed_build_epoch to null regardless of $build_epoch — this attempt is known to have left *no* database installed, so a stale epoch must never survive to falsely short-circuit a later download as "already current" (M6J).
	 *
	 * @return void
	 */
	private function persist_attempt( DatabaseUpdateResult $result, ?int $build_epoch, bool $clear_epoch = false ): void {
		$state = $this->read_state();
		$now   = ( $this->clock )();

		$state['last_attempt_at']  = $now;
		$state['last_result_code'] = $result->code;

		if ( $result->success ) {
			$state['last_success_at'] = $now;

			if ( $clear_epoch ) {
				$state['installed_build_epoch'] = null;
			} elseif ( null !== $build_epoch ) {
				$state['installed_build_epoch'] = $build_epoch;
			}
		}

		$this->persist_state( $state );
	}

	/**
	 * Reads and normalizes the persisted state, tolerating a missing or
	 * corrupt option: malformed shapes fall back to the default state,
	 * never throwing.
	 *
	 * @return array{last_attempt_at: int|null, last_success_at: int|null, last_result_code: string, installed_build_epoch: int|null}
	 */
	private function read_state(): array {
		$stored = get_option( self::STATE_OPTION_NAME, false );

		if ( ! is_array( $stored ) ) {
			return self::DEFAULT_STATE;
		}

		$last_attempt_at = $stored['last_attempt_at'] ?? null;
		$last_attempt_at = is_int( $last_attempt_at ) ? $last_attempt_at : null;

		$last_success_at = $stored['last_success_at'] ?? null;
		$last_success_at = is_int( $last_success_at ) ? $last_success_at : null;

		$last_result_code = $stored['last_result_code'] ?? '';
		$last_result_code = is_string( $last_result_code ) ? $last_result_code : '';

		$installed_build_epoch = $stored['installed_build_epoch'] ?? null;
		$installed_build_epoch = is_int( $installed_build_epoch ) ? $installed_build_epoch : null;

		return array(
			'last_attempt_at'       => $last_attempt_at,
			'last_success_at'       => $last_success_at,
			'last_result_code'      => $last_result_code,
			'installed_build_epoch' => $installed_build_epoch,
		);
	}

	/**
	 * Persists a state, creating the option non-autoloaded on first write
	 * (mirroring `CircuitBreaker::persist()`'s identical shape) and updating
	 * it thereafter.
	 *
	 * @param array{last_attempt_at: int|null, last_success_at: int|null, last_result_code: string, installed_build_epoch: int|null} $state The complete state to persist.
	 *
	 * @return void
	 */
	private function persist_state( array $state ): void {
		if ( false === get_option( self::STATE_OPTION_NAME, false ) ) {
			add_option( self::STATE_OPTION_NAME, $state, '', false );
			return;
		}

		update_option( self::STATE_OPTION_NAME, $state );
	}
}
