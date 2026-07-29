<?php
/**
 * Unit tests for UniversalGeo\Diagnostics\ProviderHealthStore.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Unit\Diagnostics;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalGeo\Diagnostics\ProviderHealthStore;

/**
 * Covers the M3 F2/F5/F6 resolutions: scrubbing, truncation, throttling,
 * bounded shape, stale-entry pruning, and the non-autoload option write.
 */
final class ProviderHealthStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['universal_geo_test_options']         = array();
		$GLOBALS['universal_geo_test_option_autoload'] = array();
	}

	// ---- Class shape ----------------------------------------------------------

	public function test_class_is_final(): void {
		$this->assertTrue( ( new ReflectionClass( ProviderHealthStore::class ) )->isFinal() );
	}

	// ---- record() / read() basic round trip ------------------------------------

	public function test_record_creates_a_bounded_record(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: something went wrong' );

		$records = $store->read();

		$this->assertArrayHasKey( 'maxmind', $records );
		$this->assertSame(
			array( 'last_error_class', 'last_error_message', 'approx_count', 'last_seen_at' ),
			array_keys( $records['maxmind'] )
		);
	}

	public function test_record_splits_class_and_message(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: something went wrong' );

		$record = $store->read()['maxmind'];

		$this->assertSame( 'RuntimeException', $record['last_error_class'] );
		$this->assertSame( 'something went wrong', $record['last_error_message'] );
	}

	public function test_record_with_no_colon_separator_has_empty_class(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'just a message with no class prefix' );

		$record = $store->read()['maxmind'];

		$this->assertSame( '', $record['last_error_class'] );
		$this->assertSame( 'just a message with no class prefix', $record['last_error_message'] );
	}

	public function test_first_record_sets_approx_count_to_one(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: boom' );

		$this->assertSame( 1, $store->read()['maxmind']['approx_count'] );
	}

	public function test_read_is_empty_when_nothing_recorded(): void {
		$this->assertSame( array(), ( new ProviderHealthStore() )->read() );
	}

	// ---- Scrubbing (F5) ---------------------------------------------------------

	public function test_ipv4_address_is_scrubbed(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: failed to resolve 203.0.113.42' );

		$message = $store->read()['maxmind']['last_error_message'];

		$this->assertStringNotContainsString( '203.0.113.42', $message );
		$this->assertStringContainsString( '[ip]', $message );
	}

	public function test_ipv6_address_is_scrubbed(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: failed to resolve 2001:db8::1234' );

		$message = $store->read()['maxmind']['last_error_message'];

		$this->assertStringNotContainsString( '2001:db8::1234', $message );
		$this->assertStringContainsString( '[ip]', $message );
	}

	public function test_full_ipv6_address_is_scrubbed(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: bad address 2001:0db8:85a3:0000:0000:8a2e:0370:7334 seen' );

		$message = $store->read()['maxmind']['last_error_message'];

		$this->assertStringNotContainsString( '2001:0db8:85a3:0000:0000:8a2e:0370:7334', $message );
	}

	public function test_loopback_ipv6_is_scrubbed(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: peer was ::1 unexpectedly' );

		$message = $store->read()['maxmind']['last_error_message'];

		$this->assertStringNotContainsString( '::1 unexpectedly', $message );
	}

	public function test_multiple_ip_addresses_in_one_message_are_all_scrubbed(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: 10.0.0.1 and 192.168.1.1 both failed' );

		$message = $store->read()['maxmind']['last_error_message'];

		$this->assertStringNotContainsString( '10.0.0.1', $message );
		$this->assertStringNotContainsString( '192.168.1.1', $message );
	}

	public function test_a_class_method_reference_is_not_treated_as_an_ip(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: failure in Foo\\Bar::method()' );

		$message = $store->read()['maxmind']['last_error_message'];

		$this->assertStringContainsString( 'Foo\\Bar::method()', $message );
	}

	// ---- Truncation ---------------------------------------------------------------

	public function test_message_is_truncated_to_a_bounded_length(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: ' . str_repeat( 'x', 500 ) );

		$message = $store->read()['maxmind']['last_error_message'];

		// mb_strlen (character count), not strlen (byte count): the
		// truncation ellipsis is a multi-byte UTF-8 character.
		$this->assertLessThanOrEqual( 200, mb_strlen( $message ) );
	}

	public function test_short_message_is_not_truncated(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: short' );

		$this->assertSame( 'short', $store->read()['maxmind']['last_error_message'] );
	}

	// ---- Throttling (F6) ------------------------------------------------------------

	public function test_identical_signature_within_the_throttle_window_does_not_write_again(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: same failure' );
		$first_seen = $store->read()['maxmind']['last_seen_at'];

		$store->record( 'maxmind', 'RuntimeException: same failure' );
		$second = $store->read()['maxmind'];

		$this->assertSame( $first_seen, $second['last_seen_at'] );
		$this->assertSame( 1, $second['approx_count'] );
	}

	public function test_changed_signature_writes_immediately_regardless_of_throttle(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: first failure' );
		$store->record( 'maxmind', 'InvalidArgumentException: different failure' );

		$record = $store->read()['maxmind'];

		$this->assertSame( 'InvalidArgumentException', $record['last_error_class'] );
		$this->assertSame( 'different failure', $record['last_error_message'] );
		$this->assertSame( 2, $record['approx_count'] );
	}

	public function test_identical_signature_after_throttle_window_elapses_writes_again(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: same failure' );

		// Simulate 300+ seconds having passed by rewriting last_seen_at directly.
		$options = $GLOBALS['universal_geo_test_options'];
		$options[ ProviderHealthStore::OPTION_NAME ]['maxmind']['last_seen_at'] = time() - 301;
		$GLOBALS['universal_geo_test_options']                                  = $options;

		$store->record( 'maxmind', 'RuntimeException: same failure' );

		$this->assertSame( 2, $store->read()['maxmind']['approx_count'] );
	}

	// ---- Bounded shape / multiple providers --------------------------------------------

	public function test_different_providers_get_separate_records(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: a' );
		$store->record( 'woocommerce', 'RuntimeException: b' );

		$records = $store->read();

		$this->assertArrayHasKey( 'maxmind', $records );
		$this->assertArrayHasKey( 'woocommerce', $records );
		$this->assertSame( 'a', $records['maxmind']['last_error_message'] );
		$this->assertSame( 'b', $records['woocommerce']['last_error_message'] );
	}

	// ---- Stale-provider pruning ---------------------------------------------------------

	public function test_stale_provider_entries_are_pruned_on_the_next_write(): void {
		$store = new ProviderHealthStore();
		$store->record( 'stale-provider', 'RuntimeException: old failure' );

		$options = $GLOBALS['universal_geo_test_options'];
		$options[ ProviderHealthStore::OPTION_NAME ]['stale-provider']['last_seen_at'] = time() - ( 8 * 86400 );
		$GLOBALS['universal_geo_test_options'] = $options;

		// Any write triggers the prune pass.
		$store->record( 'maxmind', 'RuntimeException: fresh failure' );

		$this->assertArrayNotHasKey( 'stale-provider', $store->read() );
	}

	public function test_recently_seen_provider_entries_are_not_pruned(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: a' );
		$store->record( 'woocommerce', 'InvalidArgumentException: b' );

		$this->assertArrayHasKey( 'maxmind', $store->read() );
		$this->assertArrayHasKey( 'woocommerce', $store->read() );
	}

	// ---- Non-autoload option write (F6) --------------------------------------------------

	public function test_option_is_created_with_autoload_false(): void {
		$store = new ProviderHealthStore();
		$store->record( 'maxmind', 'RuntimeException: boom' );

		$this->assertSame( false, $GLOBALS['universal_geo_test_option_autoload'][ ProviderHealthStore::OPTION_NAME ] );
	}

	// ---- No writes on success (structural — the call site's job, verified here as absence of any public write method) --

	public function test_public_api_is_exactly_record_and_read(): void {
		$methods = array_values(
			array_diff(
				array_map(
					static fn( \ReflectionMethod $m ) => $m->getName(),
					( new ReflectionClass( ProviderHealthStore::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC )
				),
				array( '__construct' )
			)
		);

		sort( $methods );
		$this->assertSame( array( 'read', 'record' ), $methods );
	}
}
