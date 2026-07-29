# Roadmap

**Status: M1, M2, M3, and M4 shipped. Milestones will be updated as they ship.**

## Milestone timeline

- **v0.1.0 (M1)** — Core domain and public API; `RemoteAddrOnlyResolver` only. Shipped.
- **v0.2.0 (M2)** — Client IP resolution, Cloudflare headers, WooCommerce integration, admin settings and diagnostics. Shipped.
- **v0.3.0 (M3)** — Privacy floor formalized (`PrivacyGuardTest`, ADR-0005); `maxmind_db_path` setting; `MaxMindProvider` (local `.mmdb` country lookups, soft dependency); provider health tracking and the `universal_geo_maxmind` Site Health test. Shipped.
- **v0.4.0 (M4)** — Remote provider: MaxMind GeoLite2 Country Web Service, disabled by default, requiring an explicit transfer acknowledgement plus a credential pair; `CircuitBreaker`; the internal `HttpTransport` seam (`WordPressHttpTransport` is the sole caller of `wp_safe_remote_get()`); remote diagnostics section and the `universal_geo_remote_provider` Site Health test. Shipped.
- **v1.0.0 (M5)** — WP-CLI, Site Health, translation readiness, release maturity.

## Explicitly deferred to 1.1 or later

- Region support (via GeoLite2-City + ISO 3166-2 table)
- REST API
- Additional GeoIP data (city, postcode, timezone, ASN)
- Client-side resolution for page-cached sites
- Additional Site Health tests
- Additional WP-CLI commands
- Additional hooks
- Bot classification
- VPN/proxy detection
- Automatic database downloads
- Per-provider confidence overrides
- Network-wide multisite settings
- Splitting large services if they outgrow single files
