# Architectural Decision Records

**Status: M2 — 0002 and 0006 written and accepted.** 0001/0003/0004 are
documented in the M1 milestone plan and `docs/ARCHITECTURE.md` but have not
yet been written as standalone ADR files — a pre-existing gap from M1,
noted here rather than silently left unmentioned; M1 is frozen (tagged `m1`)
and this document does not retroactively alter it. 0005 remains M3.

Six ADRs will be written across M1–M3:

| ADR | Title | Milestone | File |
|---|---|---|---|
| 0001 | Plugin purpose and boundaries | M1 | not yet written |
| 0002 | Trusted proxy model | M2 | [0002-trusted-proxy-model.md](0002-trusted-proxy-model.md) |
| 0003 | Provider architecture | M1, amended M3 | not yet written |
| 0004 | Public API | M1 | not yet written |
| 0005 | Privacy model | M3 | not yet written |
| 0006 | Optional WooCommerce integration | M2 | [0006-optional-woocommerce-integration.md](0006-optional-woocommerce-integration.md) |

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
