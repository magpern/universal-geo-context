# Public API

**Status: M4 complete.** The six functions and one value object below are
the frozen, permanent public surface as of v0.1.0 and remain unchanged
through M2, M3, and M4 — the remote provider adds a new resolution source
(`source === 'remote'`, `confidence === 0.85`) but no new function, no new
value object, and no change to `universal_geo_api_version()` (still `1`).

## Public surface

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
