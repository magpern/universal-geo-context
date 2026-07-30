# Roadmap

**Status: M1–M6 shipped. v1.0.0 is the first production-ready release; v1.1.0 adds managed GeoLite2 downloads.**

**Architecture governance**: See `docs/ARCHITECTURE_FREEZE.md` for the frozen contracts of the v1.x series and guidance on architectural evolution for future milestones.

## Milestone timeline

- **v0.1.0 (M1)** — Core domain and public API; `RemoteAddrOnlyResolver` only. Shipped.
- **v0.2.0 (M2)** — Client IP resolution, Cloudflare headers, WooCommerce integration, admin settings and diagnostics. Shipped.
- **v0.3.0 (M3)** — Privacy floor formalized (`PrivacyGuardTest`, ADR-0005); `maxmind_db_path` setting; `MaxMindProvider` (local `.mmdb` country lookups, soft dependency); provider health tracking and the `universal_geo_maxmind` Site Health test. Shipped.
- **v0.4.0 (M4)** — Remote provider: MaxMind GeoLite2 Country Web Service, disabled by default, requiring an explicit transfer acknowledgement plus a credential pair; `CircuitBreaker`; the internal `HttpTransport` seam (`WordPressHttpTransport` is the sole caller of `wp_safe_remote_get()`); remote diagnostics section and the `universal_geo_remote_provider` Site Health test. Shipped.
- **v1.0.0 (M5)** — Operational maturity: WP-CLI (`context`, `diagnostics`, `cache flush`); a Site Health `debug_information` section; translation readiness (`load_plugin_textdomain()`, `.pot`, a CI drift check); Privacy Policy Guide integration (`wp_add_privacy_policy_content()`); humanized diagnostics labels; real ISO 3166-1 validation for the default-country setting; `readme.txt`, `LICENSE`, an automated version-parity test, and a release-audit script. Shipped.
- **v1.1.0 (M6)** — Managed GeoLite2 Country database downloads: opt-in automatic download/validate/install via a redirect-safe two-hop fetch (credentials never reach the redirect target), `.sha256` checksum verification (confirmed against a live MaxMind account and implemented during M6J acceptance) alongside MMDB structural validation, atomic install with rollback, WP-Cron scheduling (weekly or twice-weekly); one shared MaxMind credential pair for both the remote provider and managed downloads, with backward-compatible migration from the old remote-only fields; a new `universal_geo_maxmind_managed` Site Health test; `wp universal-geo database status|download|validate|remove|restore`; new admin UI actions. `maxmind-db/reader` promoted from a dev-only to a production dependency. Shipped.

## Explicitly deferred to 1.2 or later

- Region support (via GeoLite2-City + ISO 3166-2 table)
- REST API
- Additional GeoIP data (city, postcode, timezone, ASN)
- Client-side resolution for page-cached sites
- Additional Site Health tests (cache health, empty-provider-chain)
- Additional WP-CLI commands (`providers`, `trusted-proxies --test`, `cloudflare-ranges --update`)
- Additional hooks
- Bot classification
- VPN/proxy detection
- Per-provider confidence overrides
- Network-wide multisite settings
- Splitting large services if they outgrow single files
- ADR-0001/0004 backfill
