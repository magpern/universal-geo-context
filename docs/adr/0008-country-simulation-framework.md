# ADR-0008: Country simulation framework

Status: Accepted (M8 / v1.3.0)

## Context

Administrators need to test downstream consumers (for example Universal
Multicurrency) against arbitrary visitor countries without VPNs, proxy
manipulation, or changes to provider configuration. M7 reserved the
Detection & Testing → Simulation tab as the UI surface.

## Decision

Implement simulation as a **post-resolution filter** on
`universal_geo_context`, not as a geo provider.

### Filter, not provider

- `ContextResolver` and `GeoCache` remain unchanged.
- Real resolution, caching, and provider health are unaffected.
- When active, `SimulationContextFilter` replaces the final
  `VisitorContext` country with an administrator-selected ISO code.

### Session cookie

- Name: `universal_geo_sim`
- Payload: `{version}.{country}.{hmac}` signed with `wp_salt( 'auth' )`
- Attributes: `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`, session lifetime
- Stores only the simulated country code — no IP, credentials, or real country

### Authorization

Cookie presence alone is insufficient. Simulation applies only when:

1. valid signed cookie
2. user is logged in
3. user has `manage_options`

### Context semantics when simulated

| Field | Value |
|---|---|
| `country_code` | Simulated ISO alpha-2 |
| `region_code` | `null` |
| `source` | `simulation` |
| `confidence` | `1.0` (explicit admin override) |
| `is_cached` | `false` |

### Multisite

Simulation is **per-site**: cookie path/domain follow WordPress
`COOKIEPATH` / `COOKIE_DOMAIN`. Switching subsites does not share simulation
unless the cookie domain spans sites.

### M7 legacy redirect

The one-release redirect from `options-general.php?page=universal-geo-context`
is removed in M8 as planned.

## Consequences

- Public API shape unchanged; `universal_geo_get_source()` may return
  `simulation` for authorized administrators during an active session.
- `hash_hmac` is additionally allowed in `SimulationCookie.php`
  (PrivacyGuardTest allowlist).
- Simulation must never write to `GeoCache` or provider-health stores.
