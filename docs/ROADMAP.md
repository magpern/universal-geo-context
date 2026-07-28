# Roadmap

**Status: M1 Step 0 (bootstrap). Milestones will be updated as they ship.**

## Milestone timeline

- **v0.1.0 (M1)** — Core domain and public API; `RemoteAddrOnlyResolver` only.
- **v0.2.0 (M2)** — Client IP resolution, Cloudflare headers, WooCommerce integration, admin settings and diagnostics.
- **v0.3.0 (M3)** — MaxMind database support, caching with privacy floor.
- **v0.4.0 (M4)** — Remote provider (disabled by default).
- **v1.0.0 (M5)** — WP-CLI, Site Health, translation readiness, release maturity.

See the approved plan for detailed breakdown of each milestone:
/home/magpern/.claude/plans/you-are-the-lead-encapsulated-riddle.md § 23

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
