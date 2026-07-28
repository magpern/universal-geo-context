# Public API

**Status: M1 Step 0 (bootstrap). This document will be completed in M1 Step 6.**

The public API contract is defined in the approved Revision 3 plan, § 13:
/home/magpern/.claude/plans/you-are-the-lead-encapsulated-riddle.md

## What will ship in M1

Six functions and one value object:

```php
universal_geo_get_context(): UniversalGeo\Model\VisitorContext
universal_geo_get_country_code(): ?string
universal_geo_get_region_code(): ?string       // always null in v1
universal_geo_get_source(): string
universal_geo_get_confidence(): float
universal_geo_api_version(): int
```

All functions:
- Wrapped in `function_exists()` guard
- Never throw, never fatal
- Return a value in every context (including before boot, where they return the unknown context)

## Stability rules (M1)

1. Guard every call with `function_exists( 'universal_geo_get_context' )`.
2. Call at or after `plugins_loaded` priority 20 (the plugin boots at 10).
3. Only these types are stable: `VisitorContext`, `GeoCandidate`, and the two interfaces in `Contracts/`.
4. The `VisitorContext` FQCN is frozen. Adding properties is a minor version; removing or renaming is major.

## Consumer examples

M1 will not include worked examples; they arrive in M5 when both consumers (Universal Multicurrency and AI Multilingual) are live.
