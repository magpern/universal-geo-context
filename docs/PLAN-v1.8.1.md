# v1.8.1 Implementation Plan: Diagnostics Probe Isolation & Governance Cleanup

**Status**: Implementation phase  
**Release**: Maintenance / Patch (v1.8.0 → v1.8.1)  
**Not M14**: M14 remains reserved for the next substantive capability milestone.

---

## Core Architectural Requirement

**Passive observability cannot perform active probing.**

Diagnostics surfaces (Diagnostics page GET, Overview GET, Detection Inspector GET, Site Health Info/export, `wp universal-geo status`) must never trigger `ContextResolver::probe()` — no outbound remote-provider calls, no persistent provider-health writes as a side effect of passive observation.

Active probing happens only behind:
- explicit, capability-checked, nonce-verified POST admin actions, or
- explicitly-invoked CLI commands documented to contact remote providers.

---

## Implementation Clarifications

### A. Active Diagnostics API Design

**Requirement**: `DiagnosticsService::report()` must be structurally incapable of calling `ContextResolver::probe()`.

Do NOT introduce `probe_and_report()` merely because the plan names it if a simpler implementation exists.

**Decision rule**:
- `report()` can NEVER probe.
- Active probing must remain obviously explicit at the call site or through an unmistakably active internal method.
- Do not create a generalized active/passive diagnostics API.
- Do not expand the public API.
- Choose the smallest maintainable implementation.

Document the decision in ADR-0011.

### B. Truthful Report Shape

Before changing the `DiagnosticsService::report()` return value:

1. Enumerate every production consumer of `report()`.
2. Characterize the existing `providers` structure.
3. Determine what each consumer actually relies upon.
4. Inspect existing passive representations before creating another.

**Do NOT synthesize, fabricate, or label passive information as though it were a fresh probe.**

If the exact old structure cannot be reproduced truthfully:

**STOP implementation at that point.**

Report:
- The incompatible fields.
- Their current live-probe semantics.
- The passive information actually available.
- Affected consumers.
- The smallest truthful options.
- Your recommended amendment.

Do not continue until this ambiguity is resolved.

---

## Work Packages

### WP0: Baseline Re-Verification

- Fetch origin tags.
- Verify clean working tree.
- Verify `main` = `origin/main` = `v1.8.0` = e63f272bd7960f6e6c5e5aa037740c856c1ccbc8.
- Re-derive all production `ContextResolver::probe()` call sites from current source.
- Verify planning findings (double-probe, ProviderHealthStore write).
- Confirm existing explicit refresh handler is canonical.
- Derive current release requirements from .github/workflows/.
- Verify no v1.8.1 tag/branch exists.

**Result**: All baseline facts confirmed; safe to proceed to branch creation.

### WP1: Passive Diagnostics Separation

**Primary Invariant**: `DiagnosticsService::report()` must be structurally incapable of calling `ContextResolver::probe()`.

Before implementation:
- Enumerate all production consumers of `report()`.
- Characterize the current return structure.
- Inspect existing passive provider-state representations:
  - `ProviderHealthStore::read()`
  - `provider_chain()`
  - `is_provider_available()`
  - Detection Inspector provider-explanation machinery
  - M12 operational diagnostics/readiness structures
- Apply Clarification A: decide whether an active-diagnostics method is genuinely needed.
- Apply Clarification B: preserve report shape truthfully.

**Tests**:
- Unit: `report()` never calls a spied resolver's `probe()` under any input.
- Unit: active method (if introduced) calls `probe()` exactly once.
- Characterization: lock the pre-change `providers` section shape.

### WP2: Passive Admin & Site Health Isolation

**Objective**: DiagnosticsPage GET and Site Health debug-info must not probe.

- DiagnosticsPage::render() uses the passive report path only.
- Site Health add_debug_information() uses the passive report path only.
- Opening either causes zero probes, zero outbound calls, zero ProviderHealthStore writes from probing.

**Double-Probe Fix**: Diagnostics refresh sequence must become:

```
explicit POST (via shared handler)
  → exactly one probe
  → PRG redirect
  → passive landing GET
  → subsequent reload remains passive
```

Use existing PRG freshness info and/or stored health for "last refreshed" display.

**Tests**:
- Unit: DiagnosticsPage::render() + spied resolver = 0 probes.
- Unit: Site Health debug callback + spied resolver = 0 probes.
- Integration: refresh-from-Diagnostics flow = exactly 1 probe total (via call counter, not timestamp inference).

### WP3: CLI Semantics Documentation

Preserve and document:
- `wp universal-geo status` = passive
- `wp universal-geo diagnostics` = explicit-active (v1.8.1 unchanged)
- `wp universal-geo providers` = explicit-active
- `wp universal-geo context --ip=...` = explicitly probes

Ensure help text clearly states that diagnostics/providers may contact remote providers.

### WP4: Architecture Regression Guard

Add both behavioral and static-guard tests.

**Behavioral**: Use spies/fakes/call counters to prove:
- `DiagnosticsService::report()`: 0 probes
- Diagnostics page GET: 0 probes
- Site Health debug path: 0 probes
- Explicit admin refresh: exactly 1 probe
- Explicit CLI paths: exactly 1 probe (if applicable)

**Static Guard**: Scan actual `->probe(` call sites and allow only specifically approved methods/call sites.  
Do NOT allowlist entire files.

**Mutation Verify**: Introduce a temporary violation, prove the guard turns red, remove it.

### WP5: Governance Cleanup

**ADRs**:
- **ADR-0001**: Plugin purpose and policy boundary (retrospective, explicit status line).
- **ADR-0004**: Public API contract (retrospective, explicit status line).
- **ADR-0011**: Passive Diagnostics Invariant (mandatory, not optional).

ADR-0001 and ADR-0004 MUST self-identify as retrospective documentation of existing decisions.  
Do NOT invent historical dates, alternatives, or rationale not evidenced by code/docs/history.

**Stale Documentation**:
- VisitorContext region docblock (line 50).
- Settings schema-count docblock (line 189).

Do NOT "fix" stale references that don't actually exist in current source.

### WP6: v1.8.1 Release Metadata

Apply VersionParityTest locations:
- Plugin header `Version:`
- `UNIVERSAL_GEO_VERSION` constant
- `docs/COMPATIBILITY.md`
- `readme.txt` Stable tag / Requires at least / Requires PHP

Regenerate POT.

Release notes: describe as a correctness/operational hardening patch, not a new capability.

---

## Testing

Run all focused tests during implementation, then the complete repository release gate:

- `composer validate --strict`
- PHPCS
- POT drift
- Architecture/privacy/security guards
- Version parity
- Unit suite (PHP 8.1/8.3/8.4)
- Integration matrix (floor/current/mixed-php-floor/mixed-wp-floor required; ceiling non-blocking)
- Release audit
- Production ZIP build/inspection

Derive exact required matrix from current `.github/workflows/ci.yml`.

## Manual Acceptance (dev.biopentra.eu)

Prove passive surfaces do not mutate state:
- Overview GET
- Detection Inspector GET
- Providers GET
- Diagnostics GET
- Site Health Info
- Site Health export

Verify explicit refresh from Overview/Providers/Diagnostics:
- POST performs active operation
- PRG landing does not re-probe
- Reload does not re-probe

**Authoritative exactly-once proof**: automated spy/fake-transport call counter (primary).  
**Corroborating evidence**: ProviderHealthStore timestamps (secondary).

Restore environment to original safe state after testing.

---

## Pre-Release Forensic Review

Before merge/tag, verify:
- No M14 work
- No resolver semantic change
- No provider-ordering change
- No first-success change
- No GeoCache contract change
- No VisitorContext contract change
- No simulation semantic change
- No M13 region semantic change
- No settings schema bump
- No public API v1 change
- No new REST/AJAX/background probing
- No credential/full-IP leakage
- No unnecessary new provider-state abstraction
- report() is structurally passive
- providers report data is truthful
- Active probe call sites match guard allowlist
- No unrelated refactoring

All commits must be scoped and clear.  
Working tree must be clean.

---

## Release Decision

If ANY required acceptance criterion fails: **STOP**. Do not merge, tag, or push.  
Report the blocker precisely.

If all gates pass: proceed with the repository's established release sequence.

Safest sequence:
1. Complete feature-branch verification
2. Merge to main
3. Push main
4. Wait for required final-main CI on exact release commit
5. Only after required CI is green, create annotated `v1.8.1` tag
6. Push tag
7. Verify tag-triggered release workflow
8. Inspect published GitHub Release ZIP

**No release tag before the exact release commit has passed its required authoritative CI gate.**

---

## Canonical Artifact Verification

After release workflow succeeds, inspect the GitHub release ZIP:
- Filename/version
- File count
- Production dependencies present
- v1.8.1 code present
- Diagnostics passive-probe fix present
- No tests/fixtures/credentials/dev-only packages

Compare local and GitHub ZIPs (functionally equivalent, not necessarily byte-identical).

---

## Final Closure Checklist

Only declare v1.8.1 complete after:

- ✓ Implementation complete
- ✓ Frozen specification satisfied
- ✓ All required tests green
- ✓ Manual acceptance complete
- ✓ Branch review clean (no M14, no unrelated changes)
- ✓ Merged to main
- ✓ Final-main CI green on exact release commit
- ✓ v1.8.1 tag points to that commit
- ✓ Tag pushed
- ✓ Release workflow green
- ✓ GitHub Release published
- ✓ Canonical ZIP inspected
- ✓ Repository clean
- ✓ No temporary QA state
- ✓ No credentials persisted
- ✓ No M14 branch/work created

Produce final report with: baseline, frozen-plan commit, branch/commits, decisions, probe_and_report reasoning, report() consumer enumeration, providers shape findings, guard allowlist, passive/active matrix, double-probe result, ProviderHealthStore result, ADR changes, test results, integration matrix, guard mutation verification, manual acceptance, security/privacy review, version/release metadata, ZIP details, final-main CI, tag/release workflow, canonical artifact details, local-vs-canonical comparison, final git state, defects found/fixed, limitations.

**Final conclusion**: v1.8.1 is officially complete and released, OR v1.8.1 is not release-ready (with blocking conditions).

