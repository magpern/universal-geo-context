<?php
/**
 * Minimal WP_REST_Response stand-in for the WordPress-free unit bootstrap.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- impersonates a real WordPress core global class name, deliberately unprefixed.

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Just enough surface for ContextController::get_context() to be
	 * unit-tested without a WordPress bootstrap: the two calls it actually
	 * makes (construct with body data, header()) plus the two read-back
	 * accessors tests use to assert on the result.
	 */
	class WP_REST_Response {

		/**
		 * The response body, as given to the constructor.
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * Headers set via header(), in insertion order.
		 *
		 * @var array<string, string>
		 */
		private $headers = array();

		/**
		 * @param mixed $data The response body.
		 */
		public function __construct( $data = null ) {
			$this->data = $data;
		}

		/**
		 * @param string $key   Header name.
		 * @param string $value Header value.
		 *
		 * @return void
		 */
		public function header( $key, $value ) {
			$this->headers[ $key ] = $value;
		}

		/**
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * @return array<string, string>
		 */
		public function get_headers() {
			return $this->headers;
		}
	}
}

// phpcs:enable
