# Privacy Model

**Status: M3 milestone. Bootstrap placeholder. Will be finalized in M5.**

The privacy design is defined in the approved Revision 3 plan, § 10 (ADR-0005):
/home/magpern/.claude/plans/you-are-the-lead-encapsulated-riddle.md

## Core principle

```
Resolve client IP  →  Resolve country/region  →  Discard IP  →  Expose derived context
   (local var)          (provider call)          (scope end)      (VisitorContext)
```

Raw IPs are transient locals, never persisted as plain text.

## Privacy invariants

| # | Invariant |
|---|---|
| P1 | `VisitorContext` has no IP field and no dynamic properties. |
| P2 | No file writes an IP to an option, transient, meta, table, or cache value — only as a salted hash inside a cache key in `GeoCache.php`. |
| P3 | No error, exception or debug path emits an unmasked IP. All debug output uses `IpUtils::mask()`. |
| P4 | No outbound HTTP request carries an IP unless an administrator explicitly enabled a remote provider. |
| P5 | Diagnostics, Site Health and WP-CLI never print a complete IP address. |
| P6 | The plugin creates no custom database table. |

## GDPR framing

An IP address is personal data under GDPR. Deriving a country constitutes processing. This design minimises by never persisting. Legal basis is the site operator's to determine (consent, legitimate interest, etc.).
