# Roadmap

**Status: M1–M13 shipped through v1.8.0. Architecture frozen through M8 — see `docs/ARCHITECTURE_FREEZE.md`.**

**Admin UX (v1.x)**: M10 (navigation polish) and M11 (Universal Admin Design System) complete the foundational admin infrastructure. M12 adds operational readiness without further shell redesign. M13 adds region/subdivision support without any admin shell change. Future milestones should concentrate on functionality — additional providers, testing capabilities, enterprise features, and the deferred items below — rather than further admin shell work unless a concrete gap appears.

## Next milestone

None currently approved (house rule: one milestone at a time). Managed GeoLite2 City downloads remain a documented, evidence-gated candidate — see ADR-0010 and **Explicitly deferred** below.

## Milestone timeline

- **v0.1.0 (M1)** — Core domain and public API; `RemoteAddrOnlyResolver` only. Shipped.
- **v0.2.0 (M2)** — Client IP resolution, Cloudflare headers, WooCommerce integration, admin settings and diagnostics. Shipped.
- **v0.3.0 (M3)** — Privacy floor formalized (`PrivacyGuardTest`, ADR-0005); `maxmind_db_path` setting; `MaxMindProvider` (local `.mmdb` country lookups, soft dependency); provider health tracking and the `universal_geo_maxmind` Site Health test. Shipped.
- **v0.4.0 (M4)** — Remote provider: MaxMind GeoLite2 Country Web Service, disabled by default, requiring an explicit transfer acknowledgement plus a credential pair; `CircuitBreaker`; the internal `HttpTransport` seam (`WordPressHttpTransport` is the sole caller of `wp_safe_remote_get()`); remote diagnostics section and the `universal_geo_remote_provider` Site Health test. Shipped.
- **v1.0.0 (M5)** — Operational maturity: WP-CLI (`context`, `diagnostics`, `cache flush`); a Site Health `debug_information` section; translation readiness (`load_plugin_textdomain()`, `.pot`, a CI drift check); Privacy Policy Guide integration (`wp_add_privacy_policy_content()`); humanized diagnostics labels; real ISO 3166-1 validation for the default-country setting; `readme.txt`, `LICENSE`, an automated version-parity test, and a release-audit script. Shipped.
- **v1.1.0 (M6)** — Managed GeoLite2 Country database downloads: opt-in automatic download/validate/install via a redirect-safe two-hop fetch (credentials never reach the redirect target), `.sha256` checksum verification (confirmed against a live MaxMind account and implemented during M6J acceptance) alongside MMDB structural validation, atomic install with rollback, WP-Cron scheduling (weekly or twice-weekly); one shared MaxMind credential pair for both the remote provider and managed downloads, with backward-compatible migration from the old remote-only fields; a new `universal_geo_maxmind_managed` Site Health test; `wp universal-geo database status|download|validate|remove|restore`; new admin UI actions. `maxmind-db/reader` promoted from a dev-only to a production dependency. Shipped.
- **v1.2.0 (M7)** — Admin navigation restructuring: replace the monolithic Settings-submenu `AdminScreen` with a top-level **Universal Geo Context** menu and six focused pages (Overview, Detection & Testing, Providers, Trusted Proxies, Diagnostics, Settings). Overview dashboard (six cards, no auto-probe); shared `ReportRenderer`; trusted-proxy settings moved to their own page; Detection & Testing and Providers placeholders; plugin row links; one-release legacy URL redirect from `options-general.php?page=universal-geo-context` (removed M8). No new geo-resolution behavior, public API changes, or settings schema migration. Shipped.
- **v1.3.0 (M8)** — Country simulation framework: administrator-only, session-scoped country override via signed cookie and post-resolution `universal_geo_context` filter; Simulation tab UI; admin-bar indicator; legacy Settings URL redirect removed. No provider, cache, or public API shape changes. Shipped. **Architecture frozen in `docs/ARCHITECTURE_FREEZE.md` §21–§22.**
- **v1.4.0 (M9)** — Detection Inspector: explanation layer, Detection tab (timeline, real vs effective context, provider/cache/proxy sections), Providers detail page, explicit refresh only probe; no detection, cache, simulation, or public API changes. Shipped.
- **v1.5.0 (M10)** — Admin UX polish: shared in-plugin navigation on every page, page descriptions, Quick Actions on Overview, actionable dashboard cards, contextual page actions, presentation-only admin stylesheet. Shipped.
- **v1.6.0 (M11)** — Universal Admin Design System adoption: branded shell, icon navigation, statistics grid, settings cards, provider cards, timeline, sticky save bars, scoped `ugc-ui-*` CSS/JS. Presentation-only — no runtime, API, settings schema, or resolver behaviour changes. Shipped. **M11 complete.**
- **v1.7.0 (M12)** — Operational Hardening & Consumer Readiness: internal `OperationalStatus` model (ready/degraded/action_required/unavailable + consumer_usable); Site Health provider-chain and cache tests; managed-DB credential/scheduler messaging; `wp universal-geo status|providers|trusted-proxies`; Overview/Diagnostics readiness UX; diagnostics enrichment (simulation, scheduler, lock, previous DB); Settings nested-form fix; Overview cache and Inspector health mapping fixes. No public API change. Shipped. **M12 complete.**
- **v1.8.0 (M13)** — Region/subdivision support: `MaxMindProvider` now reads `subdivisions[0].iso_code` from City-edition `.mmdb` records (manual `maxmind_db_path` or WooCommerce auto-detection), activating the `region_code` pipeline that already existed end to end (`GeoValidator::region()`, `ContextResolver`, `GeoCache`, `universal_geo_get_region_code()`, admin/CLI surfaces). Subdivision-only contract (e.g. `CA`, never `US-CA`); no ISO 3166-2 membership table; no cross-provider region enrichment; simulation and cache format unchanged; missing region never affects readiness. Managed GeoLite2 City downloads investigated (13B0) and explicitly deferred (NO-GO) — see ADR-0010. No public API, resolver, or cache contract change. Shipped. **M13 complete.**

## Explicitly deferred to a future milestone

- **Logs admin page** — slug `universal-geo-context-logs` reserved in documentation; not registered until a future milestone defines log storage and retention
- **Managed GeoLite2 City downloads** — investigated in M13 (ADR-0010, 13B0) and given an explicit NO-GO: the extracted-database size left only ~2.3% headroom under the existing archive-safety cap, and several install/rollback/uninstall safety guarantees can only be proven by implementing and live-account-testing 13B1 itself. A future milestone may revisit this once a deliberate cap decision is made.
- ISO 3166-2 subdivision membership table (region validation stays syntactic-only)
- REST API
- Additional GeoIP data (city, postcode, timezone, ASN)
- Client-side resolution for page-cached sites
- Additional hooks
- Bot classification
- VPN/proxy detection
- Per-provider confidence overrides
- Network-wide multisite settings
- Splitting large services if they outgrow single files
- ADR-0001/0004 backfill
- Public readiness API (`universal_geo_get_health` / similar) unless a concrete consumer requires it
