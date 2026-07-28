# Hooks and Extension Points

**Status: M1 Step 0 (bootstrap). This document will be completed as milestones ship.**

Seven hooks in v1 (M1–M4), all using the `universal_geo_` namespace.

See approved Revision 3 plan, § 14, for the full list:
/home/magpern/.claude/plans/you-are-the-lead-encapsulated-riddle.md

| Hook | Type | Since | Purpose |
|---|---|---|---|
| `universal_geo_providers` | Filter | M1 | Reorder, remove, or add providers |
| `universal_geo_trusted_proxies` | Filter | M2 | Extend the trusted proxy set |
| `universal_geo_maxmind_db_path` | Filter | M3 | Override the MaxMind database path |
| `universal_geo_default_country` | Filter | M1 | Override the fallback country |
| `universal_geo_context` | Filter | M1 | Modify the final resolved context |
| `universal_geo_context_resolved` | Action | M1 | React to resolution (read-only) |
| `universal_geo_provider_failed` | Action | M2 | React to provider failures |

**Design note:** Hooks carry `VisitorContext` only. No trace object, no masked IP, no provider internals cross the boundary.
