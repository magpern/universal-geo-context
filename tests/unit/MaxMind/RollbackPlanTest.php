<?php
/**
 * Unit tests for UniversalGeo\MaxMind\DatabaseManager's rollback decision matrix.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\MaxMind;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use UniversalGeo\MaxMind\DatabaseManager;

/**
 * Covers `DatabaseManager::rollback_decision()` — the small, pure,
 * filesystem-free function crossing "did a previous generation exist" with
 * "did the just-installed file pass final verification" to decide
 * keep_new/restore_previous/leave_absent. Reflection-based, the same
 * private-pure-method testing convention `PluginTest` already established
 * for `resolved_maxmind_db_path()`.
 */
final class RollbackPlanTest extends TestCase {

	private function decision( bool $previous_existed, bool $verification_passed ): string {
		$reflection = new ReflectionMethod( DatabaseManager::class, 'rollback_decision' );
		$reflection->setAccessible( true );

		return $reflection->invoke( null, $previous_existed, $verification_passed );
	}

	public function test_verification_passed_with_a_previous_generation_keeps_the_new_file(): void {
		$this->assertSame( 'keep_new', $this->decision( true, true ) );
	}

	public function test_verification_passed_with_no_previous_generation_keeps_the_new_file(): void {
		$this->assertSame( 'keep_new', $this->decision( false, true ) );
	}

	public function test_verification_failed_with_a_previous_generation_restores_it(): void {
		$this->assertSame( 'restore_previous', $this->decision( true, false ) );
	}

	public function test_verification_failed_with_no_previous_generation_leaves_absent(): void {
		$this->assertSame( 'leave_absent', $this->decision( false, false ) );
	}
}
