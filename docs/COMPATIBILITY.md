# Compatibility Matrix

**Status: M4 complete.** Version-header parity (header, `UNIVERSAL_GEO_VERSION`,
and this document) is checked manually until M5's automated release tests
land.

**Current plugin version: `0.4.0`**

## Version support

| Axis | Minimum | Tested up to |
|---|---|---|
| PHP | **8.1** | 8.4 |
| WordPress | **6.5** | 7.0 |
| WooCommerce | Optional | 10.9 |

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
