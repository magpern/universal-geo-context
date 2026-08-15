# ADR-0011: Passive Diagnostics and Explicit Probe Boundary

**Status**: Accepted (v1.8.1)

**Date**: 2026-08-15

## Context

Observability surfaces (Diagnostics page, Site Health, CLI status commands, passive admin pages) are frequently accessed by site administrators during support or troubleshooting. These surfaces should provide useful information without triggering operational side effects—specifically, without initiating outbound HTTP calls to remote providers or writing provider-health state to persistent storage as an unintended consequence of a simple view operation.

Prior to v1.8.1, `DiagnosticsService::report()` unconditionally called `ContextResolver::probe()`, causing:
1. Unintended outbound HTTPS calls when an administrator simply viewed the Diagnostics page or Site Health Info tab.
2. Persistent provider-health writes (`universal_geo_provider_health` option) as a side effect of passive observation.
3. Confusion about when live provider testing actually occurs.

This is an operational and architectural issue, not a security vulnerability (the plugin's security model is unchanged), but it violates the principle of least surprise and can cause unexpected network load on sites with many admin users.

## Decision

### Passive Observability Invariant

**Passive surfaces never initiate `ContextResolver::probe()`.**

Passive surfaces are those that are read-only GET operations or query operations without explicit user intent to test providers:

- Diagnostics admin page (GET)
- Overview admin page (GET)
- Detection Inspector admin page (GET)
- Providers admin page (GET)
- Site Health Info tab and export (core WordPress feature)
- `wp universal-geo status` (CLI command, explicitly documented as passive)

These surfaces may:
- Read the resolved visitor context from the current request.
- Read the derived-context cache state.
- Read stored provider-health observations (from `ProviderHealthStore`).
- Read configuration and scheduler state.

They must NOT:
- Call `ContextResolver::probe()`.
- Cause outbound provider API calls.
- Write to `ProviderHealthStore` as a side effect of observation.

### Explicit Probe Boundary

Live provider probing is confined to explicitly-declared active operations:

1. **Explicit admin action**: `POST` to the shared refresh handler (`admin_post_universal_geo_refresh_providers`), protected by:
   - `manage_options` capability check.
   - Nonce verification (`check_admin_referer`).
   - PRG redirect pattern (no re-probe on landing or reload).

2. **Explicit CLI commands** (documented to contact providers):
   - `wp universal-geo providers` — full provider-chain probe.
   - `wp universal-geo context --ip=<ip>` — probe for a specific IP.
   - `wp universal-geo diagnostics` — full diagnostics report with live probe (intentionally left unchanged in v1.8.1; future versions may reconsider).

Exactly one probe occurs per explicit operation. PRG landing pages do not re-probe.

### Implementation in v1.8.1

- `DiagnosticsService::report()` is refactored to be structurally incapable of calling `probe()`.
- A new private `passive_provider_snapshot()` method builds a truthful passive representation of provider state (showing availability, but not live probe results).
- CLI `diagnostics` and other active commands explicitly call `$resolver->probe()` before calling `report()`.
- Diagnostics page and Site Health surfaces use `report()` passively (no probe).

### Regression Prevention

- `PassiveDiagnosticsGuardTest` enforces an allowlist of exactly which methods may call `probe()`.
- Behavioral tests prove that passive surfaces produce zero probe calls.
- Static code scan rejects any `->probe()` call outside the allowlist.

## Consequences

- **Operational predictability**: administrators can safely browse diagnostic surfaces without triggering network I/O.
- **Performance**: repeated visits to the Diagnostics page do not hammer remote provider APIs.
- **Clear intent**: explicit admin refresh or CLI commands are the only ways to request a fresh provider health check.
- **Long-term maintenance**: the architectural boundary is enforced by tests, making future regressions visible immediately.

## Future Considerations

The `wp universal-geo diagnostics` CLI command currently probes (documented as intentional in v1.8.1). A future release may reconsider this and make it passive, with an explicit `--force-probe` flag for administrators who want live results. This would further align all passive surfaces and might be considered for v2.0 or a future v1.x minor release.
