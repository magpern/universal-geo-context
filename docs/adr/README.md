# Architectural Decision Records

**Status: M4 — 0002 and 0006 written and accepted; 0003 and 0005 written and
amended (M3, and again M4). Unchanged through M5.** 0001/0004 remain
documented only in the M1 milestone plan and `docs/ARCHITECTURE.md` — a
pre-existing gap from M1, noted here rather than silently left unmentioned;
M1 is frozen (tagged `m1`) and this document does not retroactively alter
it. Nothing in M3, M4, or M5 amends 0001 or 0004; M5 (operational tooling)
introduced no architectural decision requiring a new or amended ADR, and
the six-ADR cap stands — 0001/0004 remain the recorded 1.1 backfill.

Six ADRs will be written across M1–M3, amended further in M4; ADR-0007
added in M7 for admin navigation:

| ADR | Title | Milestone | File |
|---|---|---|---|
| 0001 | Plugin purpose and boundaries | M1 | not yet written |
| 0002 | Trusted proxy model | M2 | [0002-trusted-proxy-model.md](0002-trusted-proxy-model.md) |
| 0003 | Provider architecture | M1, amended M3, amended M4 | [0003-provider-architecture.md](0003-provider-architecture.md) |
| 0004 | Public API | M1 | not yet written |
| 0005 | Privacy model | M3, amended M4 | [0005-privacy-model.md](0005-privacy-model.md) |
| 0006 | Optional WooCommerce integration | M2 | [0006-optional-woocommerce-integration.md](0006-optional-woocommerce-integration.md) |
| 0007 | Admin navigation restructuring | M7 | [0007-admin-navigation-restructuring.md](0007-admin-navigation-restructuring.md) |

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
