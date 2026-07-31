<?php
/**
 * Suggested privacy-policy text for the WordPress Privacy Policy Guide.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Privacy;

/**
 * Builds the text `Plugin` registers with `wp_add_privacy_policy_content()`
 * (M5 — the F6 resolution recorded in the M4 report and docs/PRIVACY.md's
 * own "M5 will additionally add wp_add_privacy_policy_content()" note).
 *
 * Pure: takes the one fact that changes what the text may truthfully say
 * (whether the remote provider is enabled) as a constructor argument,
 * builds a fixed string, and calls no WordPress function beyond
 * translation helpers. The remote-transfer paragraph is included only when
 * `$remote_enabled` is true — a site with the remote provider off must
 * never have its privacy policy claim a transfer that cannot happen.
 *
 * @internal
 * @final
 */
final class PrivacyPolicyContent {

	/**
	 * Stores the one fact that decides whether the remote-transfer paragraph applies.
	 *
	 * @param bool $remote_enabled The current `remote_enabled` setting.
	 */
	public function __construct(
		private readonly bool $remote_enabled
	) {
	}

	/**
	 * Builds the suggested policy text, in the simple HTML
	 * `wp_add_privacy_policy_content()` expects.
	 *
	 * @return string
	 */
	public function build(): string {
		$paragraphs   = array();
		$paragraphs[] = sprintf(
			'<p>%s</p>',
			esc_html__(
				'This site uses Universal Geo Context to detect a visitor\'s country. The visitor\'s IP address is read from the request only for the duration of that request and is never stored in plain form.',
				'universal-geo-context'
			)
		);
		$paragraphs[] = sprintf(
			'<p>%s</p>',
			esc_html__(
				'When the derived-context cache is active, only a salted, one-way hash of the IP address is stored as part of a cache key — the address itself is never persisted.',
				'universal-geo-context'
			)
		);

		if ( $this->remote_enabled ) {
			$paragraphs[] = sprintf(
				'<p>%s</p>',
				esc_html__(
					'This site has enabled the optional remote provider: visitor IP addresses are sent to MaxMind, Inc. (geolite.info) to look up a country. This transfer only happens because an administrator explicitly enabled it and acknowledged the transfer.',
					'universal-geo-context'
				)
			);
		}

		$paragraphs[] = sprintf(
			'<p>%s</p>',
			esc_html__(
				'Site administrators may use an optional country simulation feature for testing. When active, a signed browser cookie stores only a selected ISO country code for that administrator\'s session. The cookie contains no IP address, is not used for ordinary visitors, and is not stored in the database.',
				'universal-geo-context'
			)
		);

		return implode( "\n", $paragraphs );
	}
}
