# ADR-0009: Detection Inspector explanation architecture

Status: Accepted (M9 / v1.4.0)

## Context

M8 shipped country simulation and froze the resolver, cache, and public API
contracts. Administrators still lacked a structured way to understand *why*
a visitor context was resolved a particular way without reading PHP, logs,
or running manual probes.

M7 reserved the Detection and Providers admin pages as placeholders. M9
must populate them as read-only diagnostics without altering detection
behaviour, provider contracts, cache writes, or simulation semantics
(ADR-0008, `ARCHITECTURE_FREEZE.md` §21).

## Decision

Introduce a dedicated **explanation layer** under `UniversalGeo\Explanation\`
that transforms existing resolver and diagnostics outputs into immutable
explanation models consumed by admin renderers.

### Separation from resolution

- `ContextResolver`, `GeoCache`, and providers are unchanged.
- Explanation code **never** writes cache, changes provider health, or
  overrides `VisitorContext`.
- The UI (`DetectionInspectorRenderer`, `DetectionPage`, `ProvidersPage`)
  contains **no explanation logic** — it renders models only.

### Observational data sources

On a normal page load the inspector uses:

- `ContextResolver::resolve()` and `Plugin::context()` (real vs effective)
- `SimulationState` (simulation observability)
- `DiagnosticsService::inspector_sections()` (no live probe)
- `GeoCache::describe()` (read-only cache peek)
- `ContextResolver::provider_chain()` and inferred provider rows

Live `ContextResolver::probe()` runs only in the explicit Refresh now POST
handler. The redirect carries a one-shot `universal_geo_probe_fresh`
presentation flag (plus ok/total counts); GET requests label the page as
post-refresh but do not probe again (PrivacyGuard forbids transients).

### Core types

| Type | Role |
|---|---|
| `ResolutionExplanation` | Top-level immutable snapshot |
| `ResolutionStage` | One timeline step with status |
| `ProviderExplanation` | One provider row |
| `ResolutionTimelineBuilder` | Pipeline timeline from sections + contexts |
| `ProviderExplanationBuilder` | Probe or inferred provider rows |
| `DetectionInspectorService` | Orchestrator |
| `ExplanationFormatter` | Status label helpers |
| `DetectionInspectorRenderer` | wp-admin HTML renderer |

### Refresh reuse

Provider refresh reuses the M7 `admin_post_universal_geo_refresh_providers`
handler in `OverviewPage`. A hidden `universal_geo_redirect_page` field
returns administrators to Overview, Detection, or Providers after refresh.

## Consequences

- No public API change; no new hooks required for M9 core.
- `ContextResolver` gains read-only introspection methods
  (`provider_chain`, `is_provider_available`, `confidence_for_provider`).
- `GeoCache` gains read-only `describe()` — no write-path change.
- Diagnostics page still runs live probe on load (unchanged M6 behaviour);
  the new Detection Inspector does not.
- M8 simulation contracts remain untouched; inspector shows real vs
  effective context when simulation is active.

## Rejected alternatives

| Alternative | Why rejected |
|---|---|
| Probe on every Detection page load | Violates performance requirement; may hit remote provider |
| Persist probe results in transients | PrivacyGuard rejects `set_transient()` |
| Persist probe in GeoCache | Violates cache isolation and M8 freeze |
| Embed explanation logic in page templates | Not reusable; mixes concerns |
| REST/AJAX diagnostics endpoints | Expands attack surface; POST+PRG sufficient |

## Related

- ADR-0007 (admin navigation — page placeholders)
- ADR-0008 (simulation — real vs effective context)
- `docs/ARCHITECTURE_FREEZE.md` §21–§22
