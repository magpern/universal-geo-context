# Privacy Model

**Status: M2 draft, per the milestone plan (`PRIVACY.md | M2, finalised M5`).**
Every invariant below is implemented and guard/unit-tested for the M1+M2
surface. Finalization in M5 adds `wp_add_privacy_policy_content()` and a
full privacy review covering the M3 (MaxMind) and M4 (remote provider)
additions.

## Core principle

```
Resolve client IP  →  Resolve country/region  →  Discard IP  →  Expose derived context
   (local var)          (provider call)          (scope end)      (VisitorContext)
```

The resolved client IP (`ResolvedClientIp`) is created inside
`ContextResolver::resolve()`, passed to providers by value, and goes out of
scope when the method returns. It is never assigned to a long-lived
property, never serialized, and never handed to a consumer of the plugin's
public API — `VisitorContext` has no IP field at all.

## Privacy invariants

| # | Invariant | Where enforced |
|---|---|---|
| P1 | `VisitorContext` has no IP field and no dynamic properties. | `src/Model/VisitorContext.php` — `final`, fixed constructor-promoted properties. |
| P2 | No file writes an IP to an option, transient, meta, table, or cache value as plain text — the only permitted persisted form is a salted hash inside a cache **key**, produced in exactly one file. | `src/Cache/GeoCache.php`'s key format: `"{epoch}:{config_sig}:ip:{hash}"`, `hash = substr(hash_hmac('sha256', $ip, $salt), 0, 32)`. |
| P3 | No error, exception, or debug path emits an unmasked IP. Every address in diagnostics or a Site Health field passes through `IpUtils::mask()` first. | `src/Http/IpUtils.php::mask()`; consumed by `ClientIpResolver::explain()` and `DiagnosticsService`'s report sections. |
| P4 | No outbound HTTP request carries an IP unless an administrator explicitly enabled a remote provider. | M2: no provider performs outbound HTTP at all (`WooCommerceProvider` calls `WC_Geolocation::geolocate_ip($ip, false, false)` — both fallbacks, including the API fallback, explicitly disabled). M4 will extend this invariant to the remote provider, off by default. |
| P5 | Diagnostics and Site Health never print a complete IP address. WP-CLI (M5) will follow the same rule. | Every `DiagnosticsService::report()` section and the `universal_geo_trusted_proxy` Site Health test description use masked values exclusively. |
| P6 | The plugin creates no custom database table. | No `dbDelta()` call anywhere in the codebase; the only persisted state is WordPress options (`universal_geo_settings`, `universal_geo_cache_salt`, `universal_geo_cache_epoch`) and one per-user meta key for the first-run notice dismissal. |

## Persisted-data inventory (M1+M2)

| Key | Type | Contents | Owner |
|---|---|---|---|
| `universal_geo_settings` | Option | `schema_version`, `default_country`, `trusted_proxies` (CIDRs — configuration, not personal data), `trust_cloudflare`, `derived_cache_enabled`, `derived_cache_ttl` | `Settings` |
| `universal_geo_cache_salt` | Option | 32 random bytes, base64-encoded. Generated lazily on first cache write, never eagerly. | `GeoCache` |
| `universal_geo_cache_epoch` | Option | An autoloaded integer, bumped by one on every settings save. | `GeoCache` (`bump_epoch()`) |
| `universal_geo_first_run_notice_dismissed` | User meta | `1` once a specific admin user dismisses the first-run trusted-proxy notice. Per-user, never site-wide. | `AdminScreen` |
| Derived-context cache entries (object cache only, e.g. Redis via `redis-cache`) | Object cache | The resolved `VisitorContext` (country, region, source, confidence, schema version) — **no IP** — under a key containing a salted-hash of the IP, never the IP itself. | `GeoCache` |

Nothing above is deleted on **deactivation** (house invariant: deactivation
removes nothing). **Uninstall** deletes all of it — the settings option, the
cache salt, the cache epoch, and every user's notice-dismissal meta —
permanently orphaning any residual cache-key hashes (they become
unreconstructable once the salt is gone).

## Cache-key privacy, stated honestly

A salted IP hash is still personal data under GDPR — a pseudonym, in
principle brute-forceable offline if the salt ever leaks. What the design
actually buys: the salt lives in the options table and is generated
per-site, so a dump of the object cache alone (e.g. a Redis snapshot)
cannot reverse the keys without it; the derived-cache TTL bounds how long
any given key survives; the cached **value** never contains the IP, only
the resolved country/region/source/confidence; the key never appears in any
log, admin screen, or hook payload — `GeoCache` is the only file that ever
constructs one; the layer can be disabled entirely
(`derived_cache_enabled`); and the salt is deleted on uninstall,
permanently orphaning any residue. A coarser alternative (e.g. a /24-network
key) was considered and rejected: it leaks *more* information (trivially
reversible to a real-world network) while still being wrong at network
boundaries.

## GDPR framing

An IP address is personal data. Deriving a country from it is processing.
This design minimizes exposure by design: the IP is never persisted in
plain text anywhere, never leaves the server (in v1 — no remote provider
ships enabled), and is discarded the instant a country has been derived.
The legal basis for this processing (legitimate interest, consent, etc.) is
the site operator's to determine — this plugin does not make that
determination and does not display a cookie/consent banner of its own (it
sets no cookies). Once a remote provider exists and is enabled (M4), that
is the one consequential exception to "the IP never leaves the server," and
will require the operator's explicit, informed opt-in plus updated
privacy-policy language — a decision this plugin will surface, never make
silently.

## What is masked, and how

`IpUtils::mask()`: IPv4 → last octet replaced with `x` (e.g. `203.0.113.x`);
IPv6 → first three colon-separated groups plus an ellipsis (e.g.
`2001:db8:1234:…`). `IpUtils::describe()` adds a classification on top
(e.g. `"IPv4 private (172.18.0.x)"`) — sufficient for an administrator to
diagnose a trust-boundary misconfiguration, useless to anyone else for
identifying an actual visitor.
