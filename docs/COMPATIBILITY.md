# Compatibility Matrix

**Status: M9 complete.** Version-header parity (header, `UNIVERSAL_GEO_VERSION`,
this document, and `readme.txt`'s `Stable tag`) is enforced automatically by
`tests/unit/VersionParityTest.php`, which runs on every PR/CI build — no
longer a manual check.

**Current plugin version: `1.4.0`**

## Version support

| Axis | Minimum | Tested up to |
|---|---|---|
| PHP | **8.1** | 8.4 |
| WordPress | **6.5** | 7.0 |
| WooCommerce | Optional, **10.9.4** minimum when active | 10.9 |

The WooCommerce floor is the version CI's integration matrix pins on every
leg (`.github/workflows/ci.yml`) — the practical minimum this plugin is
actually verified against, not an arbitrary number.

`maxmind-db/reader` is a required (production) Composer dependency as of
M6 — previously dev-only, since the local MaxMind provider depended on
WooCommerce (or another companion plugin) to supply the `MaxMind\Db\Reader`
class at runtime. `MaxMindProvider`'s own `class_exists()` guard is
unchanged, kept as defense-in-depth.

`ext-phar` is a soft dependency of the managed-database feature (M6):
`ArchiveExtractor` uses `PharData` to extract the downloaded `.tar.gz`
archive, guarded by `class_exists( PharData::class )`. `ext-phar` ships
enabled by default in the overwhelming majority of PHP builds; on the rare
build where it is disabled, managed downloads fail cleanly
(`ArchiveException::extraction_unsupported()`) rather than fatally — every
other feature is unaffected.

## Plugin header

```
Requires at least: 6.5
Requires PHP: 8.1
```

No `Requires Plugins` header. WooCommerce is optional — one provider, guarded at call time.

## Multisite

Per-site operation, no network-wide settings. Network activation is supported but untested in v1 (stated in docs).

## Credential-field compatibility period (M6)

The legacy `remote_account_id`/`remote_license_key` settings keys and the
legacy `UNIVERSAL_GEO_REMOTE_ACCOUNT_ID`/`UNIVERSAL_GEO_REMOTE_LICENSE_KEY`
wp-config.php constants remain supported as deprecated fallback/migration
sources through at least v1.2.0. `Settings::sanitize()` migrates a legacy
settings pair into the new canonical `maxmind_account_id`/
`maxmind_license_key` fields automatically, non-destructively, the first
time it runs against data that has both present and the canonical fields
still blank. Removal of the legacy names is not scheduled by this
document; it would be called out explicitly in whichever future milestone
proposes it.

## Known compatibility notes

See `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, and `docs/PRIVACY.md` for
environment findings and known limitations.

## Country simulation (M8)

Country simulation is **transparent to downstream plugins** that consume the
public API normally:

| Question | Answer |
|---|---|
| API shape changed? | **No** — same six functions and `VisitorContext` properties |
| Provider changes required? | **No** — simulation is not a provider |
| WooCommerce required? | **No** — simulation is core admin functionality |
| Downstream plugin modifications? | **No** — call `universal_geo_get_context()` as today |
| New `source` value? | **Yes** — `universal_geo_get_source()` may return `'simulation'` when an authorized administrator has an active session; treat as override, not real visitor geo |
| Cache behaviour for consumers? | Unchanged — consumers see `is_cached = false` on simulated context |
| Multisite | Per-site cookie; no network-wide simulation settings |

The only requirement for downstream plugins is to consume Universal Geo Context
through the documented public API. Plugins that branch on `source` may add an
optional `'simulation'` case; plugins that ignore `source` receive the
simulated country code transparently.

See `docs/ARCHITECTURE_FREEZE.md` §21 and ADR-0008 for frozen contracts.

## Detection Inspector (M9)

Admin-only diagnostics on the Detection and Providers pages. No public API,
provider, cache, or simulation contract changes. See ADR-0009.
