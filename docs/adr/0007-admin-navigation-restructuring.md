# ADR-0007 — Admin navigation restructuring

## Status

Accepted (M7 / v1.2.0)

## Context

Through M6 (v1.1.0), Universal Geo Context exposed a single monolithic
`AdminScreen` under **Settings → Universal Geo Context**. That design worked
for early milestones but became difficult to extend: settings, trusted-proxy
configuration, diagnostics, managed MaxMind actions, and first-run notices
all lived in one class with intertwined rendering and POST handlers.

M7 must stabilize admin navigation ahead of M8 (simulation) and M9
(Detection Inspector) without changing geo-resolution behavior, public APIs,
hook contracts, or the settings schema.

## Decision

1. **Replace the Settings submenu with a first-class top-level admin menu**
   registered via `add_menu_page()` (`dashicons-location-alt`,
   `manage_options`, no hardcoded position).

2. **Register six submenu pages in this order**, each implemented as a
   focused class implementing a small `Page` interface:
   - Overview (`universal-geo-context`)
   - Detection & Testing (`universal-geo-context-detection`)
   - Providers (`universal-geo-context-providers`)
   - Trusted Proxies (`universal-geo-context-trusted-proxies`)
   - Diagnostics (`universal-geo-context-diagnostics`)
   - Settings (`universal-geo-context-settings`)

   A future **Logs** page is reserved in roadmap documentation only; no slug
   or menu entry is registered in M7.

3. **Decompose `AdminScreen` into dedicated admin classes** wired explicitly
   from `Plugin::init()` — no service locators or hidden construction:
   `Menu`, page classes, `ReportRenderer`, `AdminNotices`, `FirstRunNotice`,
   and `RowLinks`.

4. **Overview is presentation-only.** Six server-rendered cards consume
   existing diagnostics/context services via `DiagnosticsService::overview_sections()`
   and `Plugin::context()`. Provider health is shown from last-known state;
   an explicit **Refresh now** POST action may call `ContextResolver::probe()`
   but nothing probes automatically on page load.

5. **Overall health on Overview** derives from the worst existing Site Health
   verdict (`DiagnosticsService::worst_site_health_status()`), sharing the
   same source of truth as Site Health tests — no duplicated policy logic.

6. **Detection & Testing and Providers pages ship informational placeholders**
   in M7 (Simulation planned v1.3.0; Live Detection planned v1.4.0). No
   probing, cookies, overrides, AJAX, or REST endpoints.

7. **Trusted-proxy configuration moves** to the Trusted Proxies page with
   all existing validation, nonces, capabilities, and PRG flows preserved.

8. **Settings retains** all non-proxy settings and M6 managed MaxMind actions;
   settings schema v5 is unchanged.

9. **Extract shared report rendering** into `ReportRenderer` during M7 so M9
   does not inherit duplicated definition-list markup.

10. **One-release legacy URL compatibility:** on `admin_init`,
    `options-general.php?page=universal-geo-context` redirects to Overview;
    the former `tab=diagnostics` query redirects to the Diagnostics page.
    Implemented in `Menu::maybe_redirect_legacy_page_url()`; **removed in M8**.

11. **Plugin row links:** action link to Overview; row meta for GitHub and
    Documentation from the plugin header URI.

## Consequences

**Good**

- Clear navigation surface for upcoming M8/M9 work without touching frozen
  resolution APIs.
- Smaller, testable admin units with explicit composition-root wiring.
- Overview gives operators an at-a-glance dashboard without automatic provider
  probes.
- Bookmarks from v1.1.0 continue to work for one release.

**Bad / trade-offs**

- Two URLs share the slug `universal-geo-context` (legacy Settings path vs
  new Overview path); legacy redirect is required until M8.
- More admin classes to maintain, offset by removed monolith complexity.
- Detection & Testing and Providers pages are placeholders until later
  milestones — intentional scope control.

## Related

- `docs/ARCHITECTURE.md` — M7 admin section
- `docs/ROADMAP.md` — M7 milestone and reserved Logs slug
- `docs/ARCHITECTURE_FREEZE.md` — unchanged public API and hook contracts
- ADR-0005 — privacy model (first-run notice user meta unchanged in spirit)
