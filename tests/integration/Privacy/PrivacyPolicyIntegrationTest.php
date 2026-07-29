<?php
/**
 * Integration tests for the M5 wp_add_privacy_policy_content() registration.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Tests\Integration\Privacy;

use ReflectionClass;
use UniversalGeo\Plugin;
use UniversalGeo\Settings;
use WP_UnitTestCase;

/**
 * Covers Plugin::init()'s admin_init registration end-to-end against real
 * WordPress core (WP_Privacy_Policy_Content) — the D5 rule that the
 * remote-transfer paragraph appears in the registered text only when the
 * remote provider is actually enabled. PrivacyPolicyContent's own text
 * logic is already covered in isolation by
 * tests/unit/Privacy/PrivacyPolicyContentTest.php; this suite covers the
 * registration wiring (is_admin() + admin_init gating, Plugin's
 * composition-root construction) that only a real WordPress environment
 * can exercise.
 */
final class PrivacyPolicyIntegrationTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		$reflection = new ReflectionClass( Plugin::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$this->reset_registered_policy_content();

		set_current_screen( 'options-general' );

		// Every other callback already registered on admin_init (by WP core
		// and WooCommerce's own bootstrap, earlier) assumes a real HTTP
		// response cycle and calls header() — which fatals once any output
		// has occurred anywhere in this PHP process (true well before this
		// test runs, in the WP test-library's own bootstrap). Clearing them
		// first means firing admin_init later in this test exercises only
		// Plugin::init()'s own callback, registered fresh below — this test
		// is about that callback's behavior, not WordPress's or
		// WooCommerce's own admin_init side effects.
		remove_all_actions( 'admin_init' );
	}

	protected function tearDown(): void {
		set_current_screen( 'front' );
		$this->reset_registered_policy_content();

		parent::tearDown();
	}

	private function reset_registered_policy_content(): void {
		$reflection = new ReflectionClass( \WP_Privacy_Policy_Content::class );
		$property   = $reflection->getProperty( 'policy_content' );
		$property->setAccessible( true );
		$property->setValue( null, array() );
	}

	/**
	 * @return array<int, array{plugin_name: string, policy_text: string}>
	 */
	private function registered_policy_content(): array {
		$reflection = new ReflectionClass( \WP_Privacy_Policy_Content::class );
		$property   = $reflection->getProperty( 'policy_content' );
		$property->setAccessible( true );

		return $property->getValue();
	}

	private function our_registered_entry(): ?array {
		foreach ( $this->registered_policy_content() as $entry ) {
			if ( 'Universal Geo Context' === $entry['plugin_name'] ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Fires the real admin_init action inside an output buffer: other,
	 * unrelated core callbacks already registered on admin_init assume a
	 * real HTTP response cycle and call header() — which fatals with
	 * "headers already sent" once any earlier test output has occurred.
	 * Buffering keeps this fully off, matching what a real admin request's
	 * own output buffering already provides at this point in the page
	 * lifecycle.
	 *
	 * @return void
	 */
	private function fire_admin_init(): void {
		ob_start();
		do_action( 'admin_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own hook, fired to simulate a real admin request.
		ob_end_clean();
	}

	public function test_content_is_registered_when_remote_provider_is_disabled(): void {
		update_option( Settings::OPTION_NAME, Settings::defaults() );

		Plugin::instance()->init();
		$this->fire_admin_init();

		$entry = $this->our_registered_entry();

		$this->assertNotNull( $entry );
		$this->assertStringNotContainsString( 'MaxMind', $entry['policy_text'] );
	}

	public function test_content_includes_the_remote_transfer_clause_when_enabled(): void {
		update_option(
			Settings::OPTION_NAME,
			Settings::sanitize(
				array(
					'remote_enabled'               => true,
					'remote_transfer_acknowledged' => true,
					'remote_account_id'            => 'test-account',
					'remote_license_key'           => 'test-license',
				)
			)
		);

		Plugin::instance()->init();
		$this->fire_admin_init();

		$entry = $this->our_registered_entry();

		$this->assertNotNull( $entry );
		$this->assertStringContainsString( 'MaxMind', $entry['policy_text'] );
	}
}
