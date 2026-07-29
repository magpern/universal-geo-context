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
}
