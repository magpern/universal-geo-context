=== Universal Geo Context ===
Contributors: magpern
Tags: geolocation, country, geoip, woocommerce, privacy
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.4.0
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

The local MaxMind database can be kept up to date automatically: an
opt-in managed-download feature fetches, validates, and installs the
official GeoLite2 Country database using the site's own MaxMind account,
with weekly or twice-weekly auto-update via WP-Cron.

= Privacy =

Raw IP addresses are read for the duration of a request only and are never
stored in plain form. When the optional derived-context cache is active,
only a salted, one-way hash of the address is stored as part of a cache
key. No IP address is ever sent off-server unless an administrator
explicitly enables and acknowledges the optional remote provider. The
optional managed-download feature never sends visitor data of any kind —
only the site's own MaxMind credentials, to fetch a published database
file. See `docs/PRIVACY.md` in the plugin source for the full privacy
model.

= WP-CLI =

* `wp universal-geo context [--ip=<ip>] [--format=<format>] [--allow-full-ip]`
* `wp universal-geo diagnostics [--format=<format>] [--allow-full-ip]`
* `wp universal-geo cache flush`
* `wp universal-geo database status|download|validate|remove|restore [--format=<format>]`

A WP-CLI process has no HTTP request, so `context --ip=` probes the
provider chain directly for that address; it does not exercise
forwarding-header trust. Verifying trusted-proxy configuration requires a
real browser request against the admin Diagnostics page.

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
3. Configure it under **Universal Geo Context** in the WordPress admin sidebar. All settings are
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

= Does the managed database download feature send visitor data anywhere? =

No. It sends only the site's own MaxMind account credentials to MaxMind's
download endpoint to fetch a published database file — no visitor IP or
other visitor data is ever part of that request. This is disabled by
default and entirely separate from the remote provider above.

= Does this plugin resolve currency or language? =

No. It detects geographic facts only. Consumers — other plugins reading
its public functions — decide what those facts mean.

== Changelog ==

= 1.4.0 =
* Detection Inspector: the Detection & Testing → Detection tab explains the current request — effective vs real context, resolution timeline, provider results, cache state, trusted proxies, and environment — without changing detection.
* Providers page: per-provider diagnostic detail with configuration shortcuts; credentials never shown.
* Explanation layer (`UniversalGeo\Explanation\`) separates observational models from admin renderers.
* Provider refresh reuses the M7 POST handler; live probe runs only after explicit Refresh now (one-shot via redirect flag).
* Read-only introspection: `GeoCache::describe()`, `ContextResolver::provider_chain()` and related helpers.

= 1.3.0 =
* Country simulation: administrators can override the visitor country for their browser session only, via a signed HttpOnly cookie and the existing `universal_geo_context` filter (source `simulation`).
* Detection & Testing → Simulation tab: start, change, and stop controls; real vs effective country display; ISO country selector.
* Admin bar indicator when simulation is active, linking to the Simulation tab.
* Removed the one-release legacy redirect from `options-general.php?page=universal-geo-context` (planned for M8).

= 1.2.0 =
* Admin navigation: a top-level **Universal Geo Context** menu replaces the former Settings submenu, with six pages — Overview, Detection & Testing, Providers, Trusted Proxies, Diagnostics, and Settings.
* Overview dashboard: six status cards (Current Resolution, Providers, Remote Provider, Trusted Proxies, Cache, Environment) using existing diagnostics data; optional explicit provider refresh; overall health derived from Site Health verdicts.
* Trusted-proxy configuration moved to its own page; all other settings and managed MaxMind actions remain on Settings.
* Shared diagnostics report renderer extracted for reuse across Overview, Trusted Proxies, and Diagnostics pages.
* Plugin list links: Settings action link to Overview; Documentation and GitHub row meta from the plugin URI.
* One-release bookmark compatibility: the former Settings URL redirects to Overview (diagnostics tab redirects to Diagnostics); removed in v1.3.0.
* Detection & Testing and Providers pages ship informational placeholders for upcoming simulation and inspection features.

= 1.1.0 =
* Managed GeoLite2 Country database downloads: opt-in, admin-triggered or scheduled (weekly/twice-weekly via WP-Cron), download/validate/install of the official database with atomic install and automatic rollback on failure.
* Redirect-safe download flow: MaxMind account credentials are sent only to MaxMind's own download endpoint and never reach the storage host it redirects to.
* Checksum verification: every downloaded database is verified against MaxMind's published `.sha256` checksum, in addition to structural validation, before it is installed.
* One shared MaxMind account (account id / license key) now used by both the remote provider and managed downloads, with backward-compatible migration from the previous remote-only credential fields.
* New `universal_geo_maxmind_managed` Site Health test and a new Diagnostics tab section for managed-database status.
* New WP-CLI subcommands: `wp universal-geo database status|download|validate|remove|restore`.
* New admin UI: a shared MaxMind account section and a managed-database section with download/validate/remove/restore actions.
* `maxmind-db/reader` is now a required dependency, so local MaxMind database lookups work on any site, independent of WooCommerce.

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

= 1.2.0 =
No settings schema change. Trusted-proxy fields remain in the same option; only the admin screen location changes. Old Settings bookmarks redirect automatically for one release.

= 1.1.0 =
No settings or stored data are changed or removed on upgrade. Managed database downloads are disabled by default; existing remote-provider credentials are migrated automatically, non-destructively, to the new shared MaxMind account fields the first time settings are saved.

= 1.0.0 =
No settings or stored data change on upgrade. WP-CLI, Site Health, and Privacy Policy Guide integration are additive.
