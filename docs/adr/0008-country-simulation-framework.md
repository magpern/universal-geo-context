# ADR-0008: Country simulation framework

Status: Accepted (M8 / v1.3.0) — **frozen for v1.x** (see `docs/ARCHITECTURE_FREEZE.md` §21)

## Context

Administrators need to test downstream consumers (for example Universal
Multicurrency) against arbitrary visitor countries without VPNs, proxy
manipulation, or changes to provider configuration. M7 reserved the
Detection & Testing → Simulation tab as the UI surface.

The design had to preserve the v1.0 evidence model: providers produce
geo facts; consumers apply policy. Simulation is a testing affordance, not
a new kind of evidence.

## Decision

Implement simulation as a **post-resolution filter** on
`universal_geo_context`, not as a geo provider.

### Why simulation is not a provider

Providers answer: "What country does the evidence suggest for this request?"
Simulation answers: "What country should downstream code see for this
administrator's test session?"

A provider in the chain would:

- participate in cache keying and cache population;
- affect provider-health and ordering semantics;
- blur the line between measured evidence and administrator intent;
- require special-casing inside `ContextResolver` (violating one-pass determinism).

Post-resolution filtering keeps the resolver and cache paths identical to
pre-M8 behaviour while giving administrators a controlled override at the
last mutable point before consumers.

**Introducing a `SimulationProvider` is an architectural violation for v1.x.**

### Filter, not provider

- `ContextResolver` and `GeoCache` remain unchanged.
- Real resolution, caching, and provider health are unaffected.
- When active, `SimulationContextFilter` replaces the final
  `VisitorContext` country with an administrator-selected ISO code.

### Why cache isolation is mandatory

Caching a simulated context would:

- pollute production cache entries with test data;
- make cache hits return stale simulated countries after simulation stops;
- break the invariant that `is_cached` reflects real resolution memoization.

Therefore simulation **never** writes GeoCache, modifies cached instances,
invalidates cache, or alters provider-health records. Real resolution always
runs; only the filtered return value changes.

### Session cookie

- Name: `universal_geo_sim`
- Payload: `{version}.{country}.{hmac}` signed with `wp_salt( 'auth' )`
- Attributes: `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`, session lifetime
- Stores only the simulated country code — no IP, credentials, provider name, or real country

Cookie design rationale: session scope limits exposure; HMAC prevents tampering;
HttpOnly reduces XSS theft; no server-side persistence avoids audit and GDPR
surface for a transient admin testing feature.

### Authorization model

Cookie presence alone is insufficient. Simulation applies only when:

1. valid signed cookie
2. user is logged in
3. user has `manage_options`

Re-checked on every request (`SimulationState`). Fail-closed: any failure
returns the real resolved context. State changes require nonce-protected
POST via `admin_post_universal_geo_simulation_*` — no public control surface.

### Context semantics when simulated

| Field | Value | Rationale |
|---|---|---|
| `country_code` | Simulated ISO alpha-2 | Purpose of the feature |
| `region_code` | `null` | Country-only scope in M8 |
| `source` | `simulation` | Explicit non-evidence marker for downstream plugins |
| `confidence` | `1.0` | Administrator explicitly chose the country |
| `is_cached` | `false` | Never a cache artifact |

Future changes to these values require a new ADR.

### Downstream plugin philosophy

- Public API shape unchanged; consumers call the same six functions.
- Simulation is **transparent to the API** but **explicit in semantics**
  (`source === 'simulation'`).
- Downstream plugins must not treat simulation as proof of real visitor
  location; Universal Geo Context does not implement currency, tax, or
  compliance policy for simulated or real contexts.
- No WooCommerce dependency; no downstream plugin modifications required.

### Composition root

`SimulationContextFilter` is registered unconditionally on every request in
`Plugin::init()`. Activation is conditional; registration is not. Admin UI
(`DetectionPage`, `SimulationController`) registers only on admin requests.

### Multisite

Simulation is **per-site**: cookie path/domain follow WordPress
`COOKIEPATH` / `COOKIE_DOMAIN`. Switching subsites does not share simulation
unless the cookie domain spans sites.

### M7 legacy redirect

The one-release redirect from `options-general.php?page=universal-geo-context`
is removed in M8 as planned.

## Rejected alternatives

| Alternative | Why rejected |
|---|---|
| **SimulationProvider** in the provider chain | Would affect cache, health, and ordering; conflates evidence with override |
| **Cache simulated contexts** | Pollutes production cache; breaks `is_cached` meaning |
| **Cookie-only authorization** | Fail-open if cookie copied; violates administrator-only gate |
| **Database-persisted simulation state** | Unnecessary persistence; widens privacy/audit surface |
| **IP embedded in cookie** | Violates privacy model; unnecessary for country override |
| **Public REST/AJAX simulation API** | Expands attack surface; admin POST + nonce is sufficient |
| **WooCommerce-gated simulation** | Simulation is core admin testing; WooCommerce remains optional |
| **Register filter only in wp-admin** | Front-end consumers would miss simulated context on storefront tests |

## Future extension points

Allowed evolution (with ADR if touching frozen semantics):

- **M9 — Live Detection inspector (v1.4.0):** Read-only admin probes of
  `ContextResolver` and provider chain on the Detection tab; does not modify
  simulation contracts.
- **M9 — Providers detail pages:** Read-only provider inspection; orthogonal
  to simulation.
- **Region simulation:** Would require new ADR for `region_code` semantics;
  not in M8 scope. **Confirmed still out of scope as of M13** (ADR-0010):
  M13 activated real region resolution for MaxMind City-edition databases,
  but deliberately did not touch simulation. `region_code = null` when
  simulation is active remains exactly as frozen above — a real,
  region-capable context is never mutated by simulation and is restored
  unchanged once simulation stops.
- **Additional simulation metadata in admin UI:** Labels, warnings, audit
  logging — provided they do not change public API or cache behaviour.

Not allowed without major-version review:

- SimulationProvider or in-chain override
- Caching or persisting simulated state beyond session cookie
- Policy decisions (currency, tax, etc.) inside simulation layer

## Consequences

- Public API shape unchanged; `universal_geo_get_source()` may return
  `simulation` for authorized administrators during an active session.
- `hash_hmac` is additionally allowed in `SimulationCookie.php`
  (PrivacyGuardTest allowlist).
- Simulation must never write to `GeoCache` or provider-health stores.
- Architecture freeze document (`docs/ARCHITECTURE_FREEZE.md` §21–§22)
  codifies these contracts for all future v1.x milestones.
