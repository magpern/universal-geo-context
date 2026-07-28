# Universal Geo Context

**Visitor geolocation detection and country resolution — evidence-based, privacy-respecting, and easily extensible.**

**Status: Repository bootstrap (M1 Step 0). Core functionality planned for M1–M5.**

This repository contains the scaffolding, tests, CI, and documentation structure for a WordPress plugin that will resolve a visitor's country from a fixed chain of providers, never persist raw IP addresses, and hand back one immutable context object. Built for WooCommerce but designed to work standalone.

## Design principles (planned)

- **Evidence, not policy.** The plugin will detect geo facts; consumers will decide what they mean.
- **Privacy first.** Raw IPs will be transient locals, never stored. Cache keys will be salted hashes only.
- **Fail closed.** With no trust configuration, no forwarding header will be read. Configuration defaults to safe.
- **Lazy.** Nothing will resolve until a consumer asks. A request that never calls the API will cost one plugin-loaded closure.

## Public API (planned for M1)

Six functions and one value object (not yet implemented):

```php
// Planned in M1 Step 6
if ( function_exists( 'universal_geo_get_context' ) ) {
    $context = universal_geo_get_context();
    if ( $context->is_known() ) {
        $country = $context->country_code;  // 'SE', 'US', etc. (ISO 3166-1 alpha-2)
        $confidence = $context->confidence; // 0.0 – 1.0
        $source = $context->source;         // 'cloudflare', 'maxmind', 'default', 'unknown'
    }
}
```

Full documentation: [docs/API.md](docs/API.md) (scaffold; completed in M1).

## Settings (planned for M2)

A settings screen with:

- **Trusted proxies** — CIDRs that are allowed to send client-address headers. Empty (default) trusts nothing.
- **Trust Cloudflare** — Enable the bundled Cloudflare IP ranges and `CF-IPCountry` header.
- **Default country** — Fallback when all providers miss (optional; empty → unknown).
- **MaxMind database path** — Auto-detected via WooCommerce's integration if present.
- **Derived cache TTL** — How long to cache the result (only with a persistent object cache).

A diagnostics tab showing live resolution, masked IPs, provider details, and Site Health results.

## Deployment (planned for M2)

See [docs/TRUSTED_PROXIES.md](docs/TRUSTED_PROXIES.md) for deployment topologies and recipes (scaffold; completed in M2).

## Milestones

- **v0.1.0 (M1)** — Core domain and public API; `RemoteAddrOnlyResolver` only.
- **v0.2.0 (M2)** — Client IP resolution, Cloudflare headers, WooCommerce integration, admin settings and diagnostics.
- **v0.3.0 (M3)** — MaxMind database support, caching with privacy floor.
- **v0.4.0 (M4)** — Remote provider (disabled by default).
- **v1.0.0 (M5)** — WP-CLI, Site Health, translation readiness, release maturity.

## Architecture

[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — the map, test strategy, and persisted-data inventory.

[docs/adr/](docs/adr/) — architectural decision records (six total).

## License

GPL 2.0 or later.
