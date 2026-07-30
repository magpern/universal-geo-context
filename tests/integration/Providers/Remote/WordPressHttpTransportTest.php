<?php
/**
 * Integration tests for UniversalGeo\Providers\Remote\WordPressHttpTransport.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration\Providers\Remote;

use UniversalGeo\Providers\Remote\TransportException;
use UniversalGeo\Providers\Remote\WordPressHttpTransport;
use WP_Error;
use WP_UnitTestCase;

/**
 * Every test short-circuits via the `pre_http_request` filter — the
 * standard WP-core testing technique — so no test here ever performs a real
 * outbound HTTP request; this class exists to prove
 * WordPressHttpTransport builds the request correctly and converts the
 * result correctly, not to test wp_safe_remote_get() itself.
 */
final class WordPressHttpTransportTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	public function test_successful_response_returns_status_and_body(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"country":{"iso_code":"US"}}',
			),
			10,
			3
		);

		$transport = new WordPressHttpTransport();
		$response  = $transport->get( 'https://geolite.info/geoip/v2.1/country/', array(), 5 );

		$this->assertSame( 200, $response->status_code );
		$this->assertSame( '{"country":{"iso_code":"US"}}', $response->body );
	}

	public function test_a_404_response_is_returned_not_thrown(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 404 ),
				'body'     => '',
			),
			10,
			3
		);

		$transport = new WordPressHttpTransport();
		$response  = $transport->get( 'https://geolite.info/geoip/v2.1/country/', array(), 5 );

		$this->assertSame( 404, $response->status_code );
	}

	public function test_wp_error_becomes_a_scrubbed_transport_exception(): void {
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'http_request_failed', 'Could not resolve host: geolite.info' ),
			10,
			3
		);

		$transport = new WordPressHttpTransport();

		try {
			$transport->get( 'https://geolite.info/geoip/v2.1/country/203.0.113.1', array(), 5 );
			$this->fail( 'Expected a TransportException.' );
		} catch ( TransportException $e ) {
			$this->assertStringNotContainsString( '203.0.113.1', $e->getMessage() );
		}
	}

	public function test_disables_redirection(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->get( 'https://geolite.info/geoip/v2.1/country/', array(), 5 );

		$this->assertSame( 0, $captured_args['redirection'] );
	}

	public function test_caps_the_response_size(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->get( 'https://geolite.info/geoip/v2.1/country/', array(), 5 );

		$this->assertSame( 16384, $captured_args['limit_response_size'] );
	}

	public function test_sends_the_given_headers(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->get(
			'https://geolite.info/geoip/v2.1/country/',
			array( 'Authorization' => 'Basic dGVzdDp0ZXN0' ),
			5
		);

		$this->assertSame( 'Basic dGVzdDp0ZXN0', $captured_args['headers']['Authorization'] );
	}

	public function test_user_agent_includes_the_plugin_version(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->get( 'https://geolite.info/geoip/v2.1/country/', array(), 5 );

		$this->assertStringContainsString( 'Universal Geo Context/', $captured_args['user-agent'] );
	}

	public function test_timeout_within_bounds_is_passed_through(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->get( 'https://geolite.info/geoip/v2.1/country/', array(), 5 );

		$this->assertSame( 5, $captured_args['timeout'] );
	}

	public function test_an_excessive_timeout_is_clamped_down(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->get( 'https://geolite.info/geoip/v2.1/country/', array(), 999 );

		$this->assertSame( 5, $captured_args['timeout'] );
	}

	public function test_a_non_positive_timeout_is_clamped_up(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->get( 'https://geolite.info/geoip/v2.1/country/', array(), 0 );

		$this->assertSame( 1, $captured_args['timeout'] );
	}

	// ---- M6: get_redirect_location() -----------------------------------------

	public function test_get_redirect_location_reports_a_302_with_location_as_a_redirect(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 302 ),
				'headers'  => array( 'location' => 'https://r2.cloudflarestorage.com/signed?sig=abc' ),
				'body'     => '',
			),
			10,
			3
		);

		$result = ( new WordPressHttpTransport() )->get_redirect_location( 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download', array(), 10 );

		$this->assertTrue( $result->is_redirect );
		$this->assertSame( 'https://r2.cloudflarestorage.com/signed?sig=abc', $result->location );
		$this->assertSame( 302, $result->status_code );
	}

	public function test_get_redirect_location_reports_a_200_as_not_a_redirect(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 200 ),
				'body'     => 'irrelevant',
			),
			10,
			3
		);

		$result = ( new WordPressHttpTransport() )->get_redirect_location( 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download', array(), 10 );

		$this->assertFalse( $result->is_redirect );
		$this->assertNull( $result->location );
		$this->assertSame( 200, $result->status_code );
	}

	public function test_get_redirect_location_reports_a_401_as_not_a_redirect(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 401 ),
				'body'     => '',
			),
			10,
			3
		);

		$result = ( new WordPressHttpTransport() )->get_redirect_location( 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download', array(), 10 );

		$this->assertFalse( $result->is_redirect );
		$this->assertNull( $result->location );
		$this->assertSame( 401, $result->status_code );
	}

	public function test_get_redirect_location_a_3xx_with_no_location_header_is_not_a_redirect(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 302 ),
				'body'     => '',
			),
			10,
			3
		);

		$result = ( new WordPressHttpTransport() )->get_redirect_location( 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download', array(), 10 );

		$this->assertFalse( $result->is_redirect );
		$this->assertNull( $result->location );
	}

	public function test_get_redirect_location_disables_redirection(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->get_redirect_location( 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download', array(), 10 );

		$this->assertSame( 0, $captured_args['redirection'] );
	}

	public function test_get_redirect_location_sends_the_given_headers(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->get_redirect_location(
			'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download',
			array( 'Authorization' => 'Basic dGVzdDp0ZXN0' ),
			10
		);

		$this->assertSame( 'Basic dGVzdDp0ZXN0', $captured_args['headers']['Authorization'] );
	}

	public function test_get_redirect_location_does_not_clamp_the_timeout(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->get_redirect_location( 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download', array(), 999 );

		$this->assertSame( 999, $captured_args['timeout'] );
	}

	public function test_get_redirect_location_wp_error_becomes_a_scrubbed_transport_exception(): void {
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'http_request_failed', 'Could not resolve host: download.maxmind.com' ),
			10,
			3
		);

		$transport = new WordPressHttpTransport();

		$this->expectException( TransportException::class );
		$transport->get_redirect_location( 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download', array(), 10 );
	}

	// ---- M6: download() -------------------------------------------------------

	public function test_download_streams_to_the_destination_and_reports_bytes_written(): void {
		$destination = sys_get_temp_dir() . '/ugeo-test-download-' . uniqid() . '.tar.gz';

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- simulates WP_Http's own stream-to-file behavior for this short-circuited test.
				file_put_contents( $args['filename'], 'fake-archive-bytes' );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		$result = ( new WordPressHttpTransport() )->download( 'https://r2.cloudflarestorage.com/signed?sig=abc', $destination, array(), 30, 100 );

		$this->assertSame( 200, $result->status_code );
		$this->assertSame( strlen( 'fake-archive-bytes' ), $result->bytes_written );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $destination );
	}

	public function test_download_reports_zero_bytes_when_nothing_was_written(): void {
		$destination = sys_get_temp_dir() . '/ugeo-test-download-' . uniqid() . '.tar.gz';

		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 401 ),
				'body'     => '',
			),
			10,
			3
		);

		$result = ( new WordPressHttpTransport() )->download( 'https://r2.cloudflarestorage.com/signed?sig=abc', $destination, array(), 30, 100 );

		$this->assertSame( 401, $result->status_code );
		$this->assertSame( 0, $result->bytes_written );
	}

	public function test_download_disables_redirection(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->download( 'https://r2.cloudflarestorage.com/signed?sig=abc', sys_get_temp_dir() . '/ugeo-test-unused.tar.gz', array(), 30, 100 );

		$this->assertSame( 0, $captured_args['redirection'] );
	}

	public function test_download_passes_the_stream_args(): void {
		$captured_args = null;
		$destination   = sys_get_temp_dir() . '/ugeo-test-download-' . uniqid() . '.tar.gz';
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->download( 'https://r2.cloudflarestorage.com/signed?sig=abc', $destination, array(), 30, 100 );

		$this->assertTrue( $captured_args['stream'] );
		$this->assertSame( $destination, $captured_args['filename'] );
		$this->assertSame( 100, $captured_args['limit_response_size'] );
	}

	public function test_download_does_not_clamp_the_timeout(): void {
		$captured_args = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		( new WordPressHttpTransport() )->download( 'https://r2.cloudflarestorage.com/signed?sig=abc', sys_get_temp_dir() . '/ugeo-test-unused.tar.gz', array(), 999, 100 );

		$this->assertSame( 999, $captured_args['timeout'] );
	}

	public function test_download_wp_error_becomes_a_scrubbed_transport_exception(): void {
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'http_request_failed', 'Could not resolve host: r2.cloudflarestorage.com' ),
			10,
			3
		);

		$transport = new WordPressHttpTransport();

		$this->expectException( TransportException::class );
		$transport->download( 'https://r2.cloudflarestorage.com/signed?sig=abc', sys_get_temp_dir() . '/ugeo-test-unused.tar.gz', array(), 30, 100 );
	}
}
