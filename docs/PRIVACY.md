# Privacy Model

**Status: Finalized (M4).** Every invariant below (P1–P6) is implemented
and guard-tested (`tests/unit/Guards/PrivacyGuardTest.php`,
`docs/adr/0005-privacy-model.md`) for the full M1–M4 surface, including
MaxMind resolution, provider-health recording, and the remote provider. M4
ships the one consequential exception this plugin's design named in advance
(see "GDPR framing" below): a remote provider that, once an administrator
explicitly enables and acknowledges it, sends the visitor IP off-server to
derive a country. It remains disabled by default. M5 will additionally add
`wp_add_privacy_policy_content()` (a WordPress privacy-tools integration,
not a new invariant).

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
| P4 | No outbound HTTP request carries an IP unless an administrator explicitly enabled a remote provider. | `WooCommerceProvider` calls `WC_Geolocation::geolocate_ip($ip, false, false)` with both fallbacks disabled; `MaxMindProvider` is a purely local file read — neither performs outbound HTTP. `PrivacyGuardTest` enforces this by absence for `wp_remote_get`/`wp_remote_post`/`wp_remote_request`/`curl_init`/URL-fetching `file_get_contents` everywhere in `src/`, with no allowlist exception anywhere, ever (rule 6). The one function capable of outbound HTTP, `wp_safe_remote_get()`, is confined to a single file, `src/Providers/Remote/WordPressHttpTransport.php` (rule 8) — and `ReferenceRemoteProvider` itself only ever reaches that file when enabled, acknowledged, and credentialed (`is_available()`, re-checked defensively at the top of `resolve()` too). |
| P5 | Diagnostics and Site Health never print a complete IP address, and (M4) never print a credential value. WP-CLI (M5) will follow the same rule. | Every `DiagnosticsService::report()` section and all three Site Health test descriptions (`universal_geo_trusted_proxy`, `universal_geo_maxmind`, `universal_geo_remote_provider`) use masked values exclusively — the MaxMind database path and the remote provider's endpoint host are server configuration, not personal data, and are shown unmasked; provider-health messages (including the remote provider's) are scrubbed of IP-shaped tokens before they are ever persisted (see `ProviderHealthStore`, below). The remote section reports only a `credentials_present` boolean and a `credential_source` enum (`constants`/`settings`/`none`) — the account id and license key values themselves never appear in diagnostics, a hook payload, an exception message, or a Site Health field. |
| P6 | The plugin creates no custom database table. | No `dbDelta()` call anywhere in the codebase; the only persisted state is WordPress options and two per-user meta keys (below). |

## Persisted-data inventory (M1–M4)

| Key | Type | Contents | Owner |
|---|---|---|---|
| `universal_geo_settings` | Option | `schema_version`, `default_country`, `trusted_proxies` (CIDRs — configuration, not personal data), `trust_cloudflare`, `derived_cache_enabled`, `derived_cache_ttl`, `maxmind_db_path` (a filesystem path — configuration, not personal data), and (M4) `remote_enabled`, `remote_transfer_acknowledged` (booleans), `remote_account_id`, `remote_license_key` (the site's own MaxMind web-service credentials — configuration/secrets belonging to the site operator, not personal data of any visitor, but never exposed in diagnostics regardless), `remote_timeout` (seconds, 1–5, default 2 — the page-view latency bound, G10) | `Settings` |
| `universal_geo_cache_salt` | Option | 32 random bytes, base64-encoded. Generated lazily on first cache write, never eagerly. | `GeoCache` |
| `universal_geo_cache_epoch` | Option | An autoloaded integer, bumped by one on every settings save. | `GeoCache` (`bump_epoch()`) |
| `universal_geo_provider_health` | Option | Non-autoloaded. A bounded record per provider id (`last_error_class`, `last_error_message`, `approx_count`, `last_seen_at`) — never a raw IP; every message is scrubbed of IP-shaped tokens and truncated before being written, and the approximate count is throttled (at most one write per 300s per unchanged error signature) rather than proportional to traffic. As of M4 this includes the `remote` provider id, scrubbed identically. | `ProviderHealthStore` |
| `universal_geo_remote_circuit` | Option | Non-autoloaded. The remote provider's circuit-breaker state: `state` (`closed`/`open`/`half_open`), `failure_count`, `opened_at` — no IP, no credential, no response body. | `CircuitBreaker` (writes); `Settings` (deletes on uninstall) |
| `universal_geo_first_run_notice_dismissed` | User meta | `1` once a specific admin user dismisses the first-run trusted-proxy notice. Per-user, never site-wide. | `AdminScreen` |
| Derived-context cache entries (object cache only, e.g. Redis via `redis-cache`) | Object cache | The resolved `VisitorContext` (country, region, source, confidence, schema version) — **no IP** — under a key containing a salted-hash of the IP, never the IP itself. | `GeoCache` |

Nothing above is deleted on **deactivation** (house invariant: deactivation
removes nothing). **Uninstall** now closes the M2/M3 gap this document
previously recorded (M4): `uninstall.php` calls all three owning classes —
`Settings::uninstall()` (its own option, `universal_geo_provider_health`, and
`universal_geo_remote_circuit`), `GeoCache::uninstall()` (the cache salt and
epoch options), and `AdminScreen::uninstall()` (the first-run notice meta,
for every user via `delete_metadata()`'s `$delete_all` flag). After a full
uninstall, no `universal_geo_*` option or user meta this plugin owns
remains — the "uninstall is all-or-nothing" house invariant (CLAUDE.md) is
now fully satisfied, not merely partially.

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
plain text anywhere, never leaves the server by default, and is discarded
the instant a country has been derived. The legal basis for this processing
(legitimate interest, etc.) is the site operator's to determine — this
plugin does not make that determination and does not display a
cookie/consent banner of its own (it sets no cookies).

The remote provider (M4) is the one consequential, deliberately-surfaced
exception to "the IP never leaves the server." It ships disabled and stays
disabled until an administrator does all of the following, in the same
settings submission: checks the acknowledgement that visitor IP addresses
will be transferred to MaxMind, Inc. at `geolite.info`; enables the
provider; and supplies a credential pair. `Settings::sanitize()` enforces
this structurally — `remote_enabled` cannot itself sanitize to `true`
unless the acknowledgement did too, in the same input — so the exception
can never be reached by a partial or accidental configuration. This plugin
surfaces the decision; it never makes it silently, and the resulting
privacy-policy update remains the site operator's own responsibility.

## What is masked, and how

`IpUtils::mask()`: IPv4 → last octet replaced with `x` (e.g. `203.0.113.x`);
IPv6 → first three colon-separated groups plus an ellipsis (e.g.
`2001:db8:1234:…`). `IpUtils::describe()` adds a classification on top
(e.g. `"IPv4 private (172.18.0.x)"`) — sufficient for an administrator to
diagnose a trust-boundary misconfiguration, useless to anyone else for
identifying an actual visitor.
