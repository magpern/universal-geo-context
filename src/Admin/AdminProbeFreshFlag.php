<?php
/**
 * One-shot PRG flag after an explicit provider refresh (M7/M9).
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

/**
 * Reads the post-refresh query args from an Overview/Detection/Providers redirect.
 *
 * The flag is presentation-only: live probing runs in the POST handler, not on GET.
 *
 * @internal
 * @final
 */
final class AdminProbeFreshFlag {

	/**
	 * Returns probe summary query args when this request follows explicit refresh.
	 *
	 * Requires manage_options, the PRG message, and sanitized count args.
	 *
	 * @return array{ok_count: int, total: int}|null
	 */
	public static function summary(): ?array {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG args from refresh handler.
		if ( ! isset( $_GET['universal_geo_probe_fresh'], $_GET['universal_geo_msg'], $_GET['universal_geo_probe_ok'], $_GET['universal_geo_probe_total'] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized below.
		$message = sanitize_key( wp_unslash( $_GET['universal_geo_msg'] ) );
		if ( 'providers_refreshed' !== $message ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized below.
		if ( 1 !== absint( wp_unslash( $_GET['universal_geo_probe_fresh'] ) ) ) {
			return null;
		}

		return array(
			'ok_count' => max( 0, (int) wp_unslash( $_GET['universal_geo_probe_ok'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'total'    => max( 0, (int) wp_unslash( $_GET['universal_geo_probe_total'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);
	}
}
