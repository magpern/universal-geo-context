# Compatibility Matrix

**Status: M1 Step 0 (bootstrap). This document will be completed in M5.**

## Version support (v1.0.0+)

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

See the plan for environment findings and known limitations:
/home/magpern/.claude/plans/you-are-the-lead-encapsulated-riddle.md § 3, § 25
