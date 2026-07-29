<?php
/**
 * Unit tests for UniversalGeo\Providers\Remote\TransportException.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Providers\Remote;

use PHPUnit\Framework\TestCase;
use UniversalGeo\Providers\Remote\TransportException;

/**
 * Covers scrubbed()'s URL removal, defense-in-depth generic URL scrubbing,
 * and message length bound — the M4 requirement that a transport failure
 * never carries the request URL or an unbounded upstream message into a
 * persisted or hook-visible exception.
 */
final class TransportExceptionTest extends TestCase {

	public function test_scrubbed_removes_the_exact_request_url_from_the_message(): void {
		$exception = TransportException::scrubbed(
			'cURL error: could not connect to https://geolite.info/geoip/v2.1/country/203.0.113.1',
			'https://geolite.info/geoip/v2.1/country/203.0.113.1'
		);

		$this->assertStringNotContainsString( '203.0.113.1', $exception->getMessage() );
		$this->assertStringNotContainsString( 'geolite.info', $exception->getMessage() );
		$this->assertStringContainsString( '[url]', $exception->getMessage() );
	}

	public function test_scrubbed_removes_a_generic_url_shaped_token_even_if_not_the_exact_request_url(): void {
		$exception = TransportException::scrubbed(
			'Redirected to https://attacker.example/steal?ip=203.0.113.1',
			'https://geolite.info/geoip/v2.1/country/'
		);

		$this->assertStringNotContainsString( 'attacker.example', $exception->getMessage() );
		$this->assertStringContainsString( '[url]', $exception->getMessage() );
	}

	public function test_scrubbed_is_a_no_op_on_a_message_without_any_url(): void {
		$exception = TransportException::scrubbed( 'Connection timed out', 'https://geolite.info/geoip/v2.1/country/' );

		$this->assertSame( 'Connection timed out', $exception->getMessage() );
	}

	public function test_scrubbed_truncates_an_overlong_message(): void {
		$exception = TransportException::scrubbed( str_repeat( 'a', 500 ), '' );

		// mb_strlen (character count), not strlen (byte count): the trailing
		// ellipsis is a multi-byte character.
		$this->assertLessThanOrEqual( 200, mb_strlen( $exception->getMessage() ) );
		$this->assertStringEndsWith( '…', $exception->getMessage() );
	}

	public function test_scrubbed_with_empty_url_still_returns_a_usable_exception(): void {
		$exception = TransportException::scrubbed( 'DNS lookup failed', '' );

		$this->assertSame( 'DNS lookup failed', $exception->getMessage() );
	}

	public function test_class_is_final_and_a_runtime_exception(): void {
		$reflection = new \ReflectionClass( TransportException::class );

		$this->assertTrue( $reflection->isFinal() );
		$this->assertTrue( $reflection->isSubclassOf( \RuntimeException::class ) );
	}
}
