<?php
/**
 * Unit tests for UniversalGeo\Privacy\PrivacyPolicyContent.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Privacy\PrivacyPolicyContent;

/**
 * Covers the M5 D5 rule: the remote-transfer paragraph appears only when
 * the remote provider is actually enabled — the registered privacy-policy
 * text must never claim a transfer that cannot happen.
 */
final class PrivacyPolicyContentTest extends TestCase {

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( PrivacyPolicyContent::class ) )->isFinal() );
	}

	public function test_build_returns_a_non_empty_string(): void {
		$this->assertNotSame( '', ( new PrivacyPolicyContent( false ) )->build() );
	}

	public function test_remote_paragraph_absent_when_remote_disabled(): void {
		$content = ( new PrivacyPolicyContent( false ) )->build();

		$this->assertStringNotContainsString( 'MaxMind', $content );
	}

	public function test_remote_paragraph_present_when_remote_enabled(): void {
		$content = ( new PrivacyPolicyContent( true ) )->build();

		$this->assertStringContainsString( 'MaxMind', $content );
	}

	public function test_states_the_ip_address_is_never_persisted(): void {
		$content = ( new PrivacyPolicyContent( false ) )->build();

		$this->assertStringContainsString( 'never', $content );
	}
}
