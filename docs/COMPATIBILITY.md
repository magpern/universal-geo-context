# Compatibility Matrix

**Status: M5 complete.** Version-header parity (header, `UNIVERSAL_GEO_VERSION`,
this document, and `readme.txt`'s `Stable tag`) is enforced automatically by
`tests/unit/VersionParityTest.php`, which runs on every PR/CI build — no
longer a manual check.

**Current plugin version: `1.0.0`**

## Version support

| Axis | Minimum | Tested up to |
|---|---|---|
| PHP | **8.1** | 8.4 |
| WordPress | **6.5** | 7.0 |
| WooCommerce | Optional, **10.9.4** minimum when active | 10.9 |

The WooCommerce floor is the version CI's integration matrix pins on every
leg (`.github/workflows/ci.yml`) — the practical minimum this plugin is
actually verified against, not an arbitrary number.

## Plugin header

```
Requires at least: 6.5
Requires PHP: 8.1
```

No `Requires Plugins` header. WooCommerce is optional — one provider, guarded at call time.

## Multisite

Per-site operation, no network-wide settings. Network activation is supported but untested in v1 (stated in docs).

## Known compatibility notes

See `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, and `docs/PRIVACY.md` for
environment findings and known limitations.
