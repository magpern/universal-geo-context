<?php
/**
 * Unit tests for UniversalGeo\Cli\DatabaseCommand.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Cli;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UniversalGeo\Cli\DatabaseCommand;
use UniversalGeo\MaxMind\ArchiveExtractor;
use UniversalGeo\MaxMind\DatabaseManager;
use UniversalGeo\MaxMind\DatabaseUpdateResult;
use UniversalGeo\MaxMind\UpdateLock;
use UniversalGeo\Tests\Support\FakeHttpTransport;

/**
 * Covers resolve_format(), status_payload(), and result_payload() — the
 * pure-ish, independently testable logic behind the five WP-CLI
 * subcommands. The WP-CLI-facing wrapper methods (status(), download(),
 * validate(), remove(), restore(), register()) are not unit-testable beyond
 * these, since WP_CLI::error()/confirm()/halt() exit the process outside
 * WP-CLI's own capture_exit test mode — the same "not unit-testable beyond
 * X" pattern Cli\CommandTest's own docblock already established; verified
 * via integration tests (success paths only) and manual/live CLI
 * acceptance instead.
 */
final class DatabaseCommandTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['universal_geo_test_options'] = array();
	}

	private function command(): DatabaseCommand {
		return new DatabaseCommand( $this->database_manager() );
	}

	private function database_manager(): DatabaseManager {
		return new DatabaseManager(
			sys_get_temp_dir() . '/ugeo-cli-database-command-test-unused',
			'',
			'',
			true,
			new FakeHttpTransport(),
			new ArchiveExtractor(),
			new UpdateLock()
		);
	}

	// ---- resolve_format() -----------------------------------------------------------

	public function test_resolve_format_defaults_to_table(): void {
		$this->assertSame( 'table', $this->command()->resolve_format( array() ) );
	}

	public function test_resolve_format_accepts_json(): void {
		$this->assertSame( 'json', $this->command()->resolve_format( array( 'format' => 'json' ) ) );
	}

	public function test_resolve_format_accepts_yaml(): void {
		$this->assertSame( 'yaml', $this->command()->resolve_format( array( 'format' => 'yaml' ) ) );
	}

	public function test_resolve_format_accepts_table_explicitly(): void {
		$this->assertSame( 'table', $this->command()->resolve_format( array( 'format' => 'table' ) ) );
	}

	public function test_resolve_format_rejects_an_unrecognized_value(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->command()->resolve_format( array( 'format' => 'xml' ) );
	}

	// ---- status_payload() ------------------------------------------------------------

	public function test_status_payload_reports_not_installed_by_default(): void {
		$payload = $this->command()->status_payload();

		$this->assertSame( 'no', $payload['installed'] );
		$this->assertSame( '', $payload['last_attempt_at'] );
		$this->assertSame( '', $payload['last_success_at'] );
		$this->assertSame( '', $payload['last_result_code'] );
		$this->assertSame( '', $payload['installed_build_epoch'] );
	}

	public function test_status_payload_reflects_persisted_state(): void {
		update_option(
			DatabaseManager::STATE_OPTION_NAME,
			array(
				'last_attempt_at'       => 1700000000,
				'last_success_at'       => 1700000000,
				'last_result_code'      => 'ok',
				'installed_build_epoch' => 1699999999,
			)
		);

		$payload = $this->command()->status_payload();

		$this->assertSame( 'ok', $payload['last_result_code'] );
		$this->assertSame( '1699999999', $payload['installed_build_epoch'] );
		$this->assertStringContainsString( '2023-11-14', $payload['last_attempt_at'] );
		$this->assertStringContainsString( 'UTC', $payload['last_attempt_at'] );
	}

	// ---- result_payload() -------------------------------------------------------------

	public function test_result_payload_for_a_success(): void {
		$payload = $this->command()->result_payload( DatabaseUpdateResult::success( 'ok', 'All good.' ) );

		$this->assertSame(
			array(
				'success' => 'yes',
				'code'    => 'ok',
				'message' => 'All good.',
			),
			$payload
		);
	}

	public function test_result_payload_for_a_failure(): void {
		$payload = $this->command()->result_payload( DatabaseUpdateResult::failure( 'credentials_missing', 'No credentials.' ) );

		$this->assertSame(
			array(
				'success' => 'no',
				'code'    => 'credentials_missing',
				'message' => 'No credentials.',
			),
			$payload
		);
	}
}
