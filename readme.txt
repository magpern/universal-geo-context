=== Universal Geo Context ===
Contributors: magpern
Tags: geolocation, country, geoip, woocommerce, privacy
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visitor geolocation detection and country resolution — evidence-based, privacy-respecting, and easily extensible.

== Description ==

Universal Geo Context resolves a visitor's country from a fixed chain of
providers — Cloudflare headers, a local MaxMind database, WooCommerce's own
geolocation, an optional remote MaxMind web service, and a configured
default — attributes the answer to a source, scores its confidence, and
hands back one immutable context object through a small public API.

The plugin detects geographic facts only; it never resolves currency,
language, tax, shipping, or compliance decisions on its own. Those remain
the responsibility of the site or of other plugins consuming its public
functions.

Built for WooCommerce, but works standalone: WooCommerce is one optional
provider, guarded at call time, never a hard dependency.

= Privacy =

Raw IP addresses are read for the duration of a request only and are never
stored in plain form. When the optional derived-context cache is active,
only a salted, one-way hash of the address is stored as part of a cache
key. No IP address is ever sent off-server unless an administrator
explicitly enables and acknowledges the optional remote provider. See
`docs/PRIVACY.md` in the plugin source for the full privacy model.

= WP-CLI =

* `wp universal-geo context [--ip=<ip>] [--format=<format>] [--allow-full-ip]`
* `wp universal-geo diagnostics [--format=<format>] [--allow-full-ip]`
* `wp universal-geo cache flush`

A WP-CLI process has no HTTP request, so `context --ip=` probes the
provider chain directly for that address; it does not exercise
forwarding-header trust. Verifying trusted-proxy configuration requires a
real browser request against the admin Diagnostics tab.

= Public API =

Six functions and one value object — see `docs/API.md` in the plugin
source for the full reference and worked examples:

`universal_geo_get_context()`, `universal_geo_get_country_code()`,
`universal_geo_get_region_code()`, `universal_geo_get_source()`,
`universal_geo_get_confidence()`, `universal_geo_api_version()`.

== Installation ==

1. Upload the plugin files, or install the built zip, so the plugin lives
   under `wp-content/plugins/universal-geo-context`.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Configure it under Settings → Universal Geo Context. All settings are
   optional; the plugin is safe to activate with no configuration.

== Frequently Asked Questions ==

= Does this plugin store visitor IP addresses? =

No. A raw IP address is read from the current request only and discarded
when the request ends. When the derived-context cache is enabled, only a
salted, one-way hash of the address is stored as part of a cache key —
never the address itself.

= Does this plugin require WooCommerce? =

No. WooCommerce's own geolocation is one provider in the chain, used only
when WooCommerce is active. The plugin has a working settings screen and
resolves a configured default country on any WordPress site, with or
without WooCommerce.

= Does this plugin send data to a third party? =

Only if an administrator explicitly enables the optional remote provider
(MaxMind GeoLite2 Country Web Service) and acknowledges, in the same
settings submission, that visitor IP addresses will be transferred to
MaxMind, Inc. This provider is disabled by default.

= Does this plugin resolve currency or language? =

No. It detects geographic facts only. Consumers — other plugins reading
its public functions — decide what those facts mean.

== Changelog ==

= 1.0.0 =
* WP-CLI: `context`, `diagnostics`, and `cache flush` commands.
* Site Health: a "Universal Geo Context" section on the Info screen (Tools → Site Health → Info), alongside the three existing direct tests.
* Translation readiness: `load_plugin_textdomain()`, a generated `.pot`, and a CI check that fails on drift between source strings and the committed `.pot`.
* Privacy Policy Guide integration (`wp_add_privacy_policy_content()`), including the remote-provider transfer clause only when that provider is enabled.
* Diagnostics tab labels are now human-readable instead of raw field keys, shared with the new Site Health section and the WP-CLI `diagnostics` command.
* The default-country setting now validates against a real ISO 3166-1 country list instead of accepting any two-letter shape; an unrecognized code is rejected with an admin notice and the previous value is kept.
* Release maturity: this `readme.txt`, a `LICENSE` file, an automated version-parity test, and a release-audit script that checks the built zip for stray development files.

= 0.4.0 =
* Remote provider: MaxMind GeoLite2 Country Web Service, disabled by default, requiring an explicit transfer acknowledgement plus a credential pair.
* Circuit breaker and an internal HTTP transport seam.
* Remote diagnostics section and Site Health test.

= 0.3.0 =
* Privacy floor formalized.
* `maxmind_db_path` setting and `MaxMindProvider` (local `.mmdb` country lookups).
* Provider health tracking and the MaxMind Site Health test.

= 0.2.0 =
* Client IP resolution, Cloudflare headers, WooCommerce integration.
* Admin settings and diagnostics screen.

= 0.1.0 =
* Core domain and public API.

== Upgrade Notice ==

= 1.0.0 =
No settings or stored data change on upgrade. WP-CLI, Site Health, and Privacy Policy Guide integration are additive.
