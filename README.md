# Universal Geo Context

**Visitor geolocation detection and country resolution — evidence-based, privacy-respecting, and easily extensible.**

**Status: v1.8.0 (M13) — Region & Subdivision Support.** A working WordPress plugin that resolves a
visitor's country (and, when a City-edition MaxMind database is in use,
first-level subdivision) from a fixed chain of providers (Cloudflare
headers, a local MaxMind database, WooCommerce's own geolocation, an
optional remote MaxMind web service, and a configured default), attributes
the answer to a source, scores its confidence, and hands back one immutable
context object. It never persists a raw IP address, and never sends one
off-server unless an administrator explicitly enables and acknowledges the
remote provider. The local MaxMind database can now be kept up to date
automatically, opt-in, via managed GeoLite2 Country downloads (managed City
downloads were investigated and explicitly deferred — see
`docs/adr/0010-region-subdivision-support.md`). Built for WooCommerce but
works standalone.

## Design principles

- **Evidence, not policy.** The plugin detects geo facts; consumers decide
  what they mean. It never resolves currency, language, tax, shipping, or
  compliance.
- **Privacy first.** Raw IPs are transient locals, never stored. The only
  persisted derivative is a salted hash inside a cache key. Enforced by a
  mutation-verified guard test, not review alone (`PrivacyGuardTest`).
- **Fail closed.** With no trust configuration, no forwarding header is
  read — `REMOTE_ADDR` is the answer. Configuration defaults to safe.
- **Lazy.** Nothing resolves until a consumer asks. A request that never
  calls the API costs one `plugins_loaded` closure.

## Public API

Six functions and one value object:

```php
if ( function_exists( 'universal_geo_get_context' ) ) {
    $context = universal_geo_get_context();
    if ( $context->is_known() ) {
        $country    = $context->country_code; // 'SE', 'US', etc. (ISO 3166-1 alpha-2)
        $confidence = $context->confidence;    // 0.0 – 1.0
        $source     = $context->source;        // 'cloudflare', 'maxmind', 'woocommerce', 'default', 'unknown'
    }
}
```

Full documentation: [docs/API.md](docs/API.md).

## Admin

**Universal Geo Context** in the WordPress admin sidebar, with six pages linked by a shared in-plugin navigation bar:

- **Overview** — at-a-glance dashboard with Quick Actions and actionable cards (current resolution, provider health, remote provider, trusted proxies, cache, environment).
- **Settings** — default country, cache, MaxMind account, remote provider, managed database downloads (primary configuration page).
- **Detection & Testing** — Detection Inspector (v1.4.0); country simulation for administrators (v1.3.0).
- **Providers** — per-provider diagnostic detail with configuration shortcuts (v1.4.0).
- **Trusted Proxies** — trusted-proxy CIDRs, Cloudflare preset, trust-current-peer actions.
- **Diagnostics** — full diagnostics report (live resolution, masked IPs, provider probes on demand, MaxMind managed-database status, Site Health results).

Plugin list links: **Settings** (Settings page), **Documentation**, **GitHub**.

## Settings (detail)

- **Trusted proxies** — on the Trusted Proxies page: CIDRs allowed to send client-address headers. Empty (default) trusts nothing.
- **Trust Cloudflare** — Enables the bundled Cloudflare IP ranges and the `CF-Connecting-IP` / `CF-IPCountry` headers.
- **Default country** — Fallback when every provider misses (optional; empty → unknown).
- **MaxMind database path** — Absolute path to a `.mmdb` file under the WordPress content directory. Empty = auto-detect via a managed download (if enabled) or WooCommerce's own MaxMind integration.
- **Derived cache TTL** — How long to cache a result (only with a persistent object cache).
- **MaxMind account** — One shared account id / license key pair (or the `UNIVERSAL_GEO_MAXMIND_ACCOUNT_ID` / `UNIVERSAL_GEO_MAXMIND_LICENSE_KEY` wp-config.php constants, which take precedence as a pair), used by both the remote provider and managed database downloads below.
- **Remote provider** — Disabled by default. Enabling it requires, in the same submission, an explicit acknowledgement that visitor IP addresses will be transferred to MaxMind, Inc. at `geolite.info`. Request timeout is configurable from 1–5 seconds (default 2).
- **Managed database** — on Settings: disabled by default. Once enabled, downloads, validates, and installs the official GeoLite2 Country database using the shared MaxMind account above, with an optional weekly or twice-weekly auto-update via WP-Cron. No visitor data is ever sent as part of this feature — only the site's own credentials, to fetch a published database file.

The Diagnostics page shows live resolution, masked IPs, per-provider probe
results, MaxMind database metadata (including the managed database's
install/update status), remote-provider status (credential source,
circuit-breaker state, scrubbed recent failure), provider-health history,
and Site Health results.

## Deployment

See [docs/TRUSTED_PROXIES.md](docs/TRUSTED_PROXIES.md) for trust-boundary
deployment topologies and recipes.

## Milestones

- **v0.1.0 (M1)** — Core domain and public API; `RemoteAddrOnlyResolver` only. Shipped.
- **v0.2.0 (M2)** — Client IP resolution, Cloudflare headers, WooCommerce integration, admin settings and diagnostics. Shipped.
- **v0.3.0 (M3)** — Privacy floor formalized, `maxmind_db_path` setting, `MaxMindProvider`, provider health tracking, MaxMind Site Health test. Shipped.
- **v0.4.0 (M4)** — Remote provider (MaxMind GeoLite2 Country Web Service), disabled by default; circuit breaker; remote diagnostics and Site Health test. Shipped.
- **v1.0.0 (M5)** — WP-CLI (`context`, `diagnostics`, `cache flush`); a Site Health `debug_information` section; translation readiness; Privacy Policy Guide integration; humanized diagnostics labels; real ISO 3166-1 validation for the default-country setting; release maturity (`readme.txt`, `LICENSE`, automated version-parity test, release-audit script). Shipped.
- **v1.1.0 (M6)** — Managed GeoLite2 Country database downloads: opt-in automatic download/validate/install via a redirect-safe fetch, atomic install with rollback, WP-Cron scheduling; one shared MaxMind credential pair for both the remote provider and managed downloads; a new Site Health test; `wp universal-geo database status|download|validate|remove|restore`; new admin UI actions. Shipped.
- **v1.2.0 (M7)** — Admin navigation restructuring: top-level menu, six focused pages, Overview dashboard, shared report renderer, plugin row links, one-release legacy URL redirect. Shipped.
- **v1.3.0 (M8)** — Country simulation framework: administrator-only, session-scoped country override via signed cookie and post-resolution filter; Simulation tab UI; admin-bar indicator; legacy Settings URL redirect removed. Shipped.

Full timeline: [docs/ROADMAP.md](docs/ROADMAP.md).

## WP-CLI

```
wp universal-geo context [--ip=<ip>] [--format=table|json|yaml] [--allow-full-ip]
wp universal-geo diagnostics [--format=table|json|yaml] [--allow-full-ip]
wp universal-geo status [--format=table|json|yaml]
wp universal-geo providers [--format=table|json|yaml]
wp universal-geo trusted-proxies [--test=<ip>] [--format=table|json|yaml]
wp universal-geo cache flush
wp universal-geo database status|download|validate|remove|restore [--format=table|json|yaml]
```

WP-CLI has no HTTP request, so `context --ip=` probes the provider chain
directly for that address; it never exercises forwarding-header trust —
verifying trusted-proxy configuration always requires a real browser
request against the Diagnostics tab.

## Architecture

[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — the map, test strategy, and persisted-data inventory.

[docs/adr/](docs/adr/) — architectural decision records.

[docs/PRIVACY.md](docs/PRIVACY.md), [docs/SECURITY.md](docs/SECURITY.md), [docs/HOOKS.md](docs/HOOKS.md) — the privacy model, threat model, and extension points.

## License

GPL 2.0 or later.
