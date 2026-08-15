# ADR-0004: Public API

**Status**: Accepted — retrospective documentation of an existing v1 architectural contract.

**Date**: 2026-08-15 (v1.8.1 — documented as part of governance backfill)

## Context

The plugin's public surface is intentionally minimal and frozen as of v1.0. All v1 releases maintain strict backward compatibility for this surface, allowing consumers to depend on the API without risk of breakage.

## Decision

The public API consists of exactly six functions and one value object, all defined in `src/api.php`:

```php
function universal_geo_context(): VisitorContext
function universal_geo_context_cached(): VisitorContext
function universal_geo_detect_country(): string|null
function universal_geo_detect_region(): string|null
function universal_geo_was_cached(): bool
function universal_geo_has_default_country(): bool

class VisitorContext
  - $country_code: string|null
  - $region_code: string|null
  - $source: string
  - $confidence: float
  - $is_cached: bool
  - public function to_json_schema(): string
```

### Characteristics

1. **Global functions, not classes**: accessible anywhere in WordPress without namespacing; guarded by `function_exists()` checks for safe conditional loading.
2. **Immutable value object**: `VisitorContext` is read-only; no setters or public constructors; consumers treat it as data, not as an API to extend.
3. **Evidence-only**: no function accepts or returns policy directives; all outputs are geographic snapshots.
4. **Stable return types**: all return types are guaranteed across v1.x releases.

### Stability Contract

- No public function signature change (parameters, return type).
- No `VisitorContext` property removal or type change.
- No deprecation without a full v1 release cycle of notice.
- No private→public or public→private migration without major version bump.

## Consequences

- **Dependency safety**: plugin authors and theme authors can safely add `universal-geo-context` as a required or optional dependency in `composer.json` or declared support.
- **Backward compatibility**: upgrades from one v1.x release to another are safe; the API does not break within the major version.
- **Minimal surface area**: the frozen public surface is small and well-documented, reducing the cognitive load for consumers.
