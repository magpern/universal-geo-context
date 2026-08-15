# Architectural Decision Records

**Status: v1.8.1 — 0001, 0002, 0003, 0004, 0005, 0006, 0007, 0008, 0009, 0010, 0011
written and accepted.** ADRs 0001 and 0004 are retrospective documentation of
existing v1 architectural contracts, backfilled in v1.8.1 governance cleanup.
ADR-0011 formalizes the passive diagnostics boundary established in v1.8.1.

Six ADRs were written across M1–M3, amended further in M4; ADR-0007 added
in M7 for admin navigation, ADR-0008 in M8 for simulation, ADR-0009 in M9
for the Detection Inspector, ADR-0010 in M13 for region/subdivision
support, ADR-0011 in v1.8.1 for passive diagnostics boundary:

| ADR | Title | Milestone | File |
|---|---|---|---|
| 0001 | Plugin purpose and boundaries | M1, backfilled v1.8.1 | [0001-plugin-purpose-and-boundaries.md](0001-plugin-purpose-and-boundaries.md) |
| 0002 | Trusted proxy model | M2 | [0002-trusted-proxy-model.md](0002-trusted-proxy-model.md) |
| 0003 | Provider architecture | M1, amended M3, amended M4, amended M13 | [0003-provider-architecture.md](0003-provider-architecture.md) |
| 0004 | Public API | M1, backfilled v1.8.1 | [0004-public-api.md](0004-public-api.md) |
| 0005 | Privacy model | M3, amended M4 | [0005-privacy-model.md](0005-privacy-model.md) |
| 0006 | Optional WooCommerce integration | M2 | [0006-optional-woocommerce-integration.md](0006-optional-woocommerce-integration.md) |
| 0007 | Admin navigation restructuring | M7 | [0007-admin-navigation-restructuring.md](0007-admin-navigation-restructuring.md) |
| 0008 | Country simulation framework | M8 | [0008-country-simulation-framework.md](0008-country-simulation-framework.md) |
| 0009 | Detection Inspector explanation architecture | M9 | [0009-detection-inspector-explanation-architecture.md](0009-detection-inspector-explanation-architecture.md) |
| 0010 | Region/subdivision support and provider ownership | M13 | [0010-region-subdivision-support.md](0010-region-subdivision-support.md) |
| 0011 | Passive diagnostics and explicit probe boundary | v1.8.1 | [0011-passive-diagnostics-invariant.md](0011-passive-diagnostics-invariant.md) |

## Format

House Nygard format (markdown):

```
# ADR-NNNN — Title

## Status
Proposed | Accepted | Deprecated | Superseded by ADR-XXXX

## Context
Why was this decision needed?

## Decision
What was decided?

## Consequences
What are the results, good and bad?

## Related
Links to other ADRs or documentation.
```
