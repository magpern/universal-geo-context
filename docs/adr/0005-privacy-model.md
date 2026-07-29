# ADR-0005 — Privacy model

## Status

Accepted (M3), amended (M4)

## Context

This plugin exists specifically to avoid the common mistake of treating an
IP address as something safe to log, cache, or display verbatim. Every
milestone since M1 has followed a set of privacy invariants by convention
and code review (`docs/PRIVACY.md`); nothing enforced them automatically.
M3 adds `MaxMindProvider`, the plugin's first new IP-consuming code since
`WooCommerceProvider`, and `ProviderHealthStore`, its first new persisted
option since the M1/M2 cache/settings options — the moment those invariants
stop being optional and become the fourth, final, mutation-verified guard
test (`PrivacyGuardTest`), completing the cap Revision 3 §2 set at four.

## Decision

1. **No raw IP is ever persisted, anywhere, in any form.** The only
   permitted persisted derivative of an IP address is a salted HMAC-SHA256
   hash inside a `GeoCache` cache **key** — never a cache value, never an
   option, never user meta, never a log line. `PrivacyGuardTest` enforces
   this structurally: `hash_hmac()` may appear only in
   `src/Cache/GeoCache.php`, and every object-cache primitive
   (`wp_cache_set`/`get`/`delete`/`add`) is confined to that same file.
2. **Salted HMAC cache keys, stated honestly.** A salted IP hash is still
   personal data under GDPR in principle — a pseudonym, brute-forceable
   offline if the salt ever leaks. The design does not claim otherwise. What
   it actually buys, unchanged from M1/M2 (`docs/PRIVACY.md`): the salt is
   generated per-site and lives in the options table, so a bare object-cache
   dump cannot reverse the keys without it; the cached *value* never
   contains the IP; the TTL bounds key lifetime; caching can be disabled
   entirely; and the salt is deleted on uninstall, permanently orphaning any
   residue.
3. **No transients, ever.** `set_transient()`/`get_transient()` write to
   `wp_options` on every call — on an anonymous, uncached front end that is
   write amplification proportional to traffic. `GeoCache` uses the object
   cache exclusively and degrades to an honest no-op without a persistent
   one, rather than falling back to a transient. `PrivacyGuardTest` asserts
   this by absence: neither function may appear anywhere in `src/`.
4. **Masked diagnostics, no exceptions.** Every address surfaced in
   `DiagnosticsService`'s report, the trusted-proxy Site Health test, or (M3)
   the MaxMind/provider-health sections passes through `IpUtils::mask()`
   first. `ProviderHealthStore` extends this to exception text: a provider's
   failure reason (class + message) is scrubbed of IPv4/IPv6-shaped tokens
   and truncated before being persisted, because an exception message is
   free-form text a provider's underlying library controls, not a value this
   plugin already validated — the live `universal_geo_provider_failed`
   action's payload is unchanged (existing public behavior; consumers were
   already warned it may contain arbitrary exception text), but the
   *persisted* copy is never taken on faith.
5. **A persistent object cache is required for any of this to help.**
   `GeoCache::is_active()` requires both the administrator's setting and
   `wp_using_ext_object_cache()`. Without one, per-request memoization inside
   `ContextResolver` is the only caching that happens — correct, just not
   worth hashing for. This is not a privacy control on its own, but it
   bounds *how much* pseudonymized data can accumulate: no persistent object
   cache means no persisted derivative of an IP survives past the request.
6. **Provider-health data is scrubbed, bounded, and owned by
   `ProviderHealthStore`, not `DiagnosticsService`.** Revision 3 §9 describes
   the provider-health record as owned by `DiagnosticsService`, but that
   class is constructed only on real wp-admin requests
   (`Plugin::should_register_admin()`), while provider failures happen on
   front-end requests — as written, the record would never be populated
   where it matters. `ProviderHealthStore` is instead a small,
   always-constructed service (`Plugin::build_graph()`, every request) that
   owns the `universal_geo_provider_health` option outright.
   `DiagnosticsService` is injected the store and only *reads* it, the same
   read-only relationship it already has with `ContextResolver::probe()`.
   This corrects, not merely implements, Revision 3's stated ownership — see
   `docs/adr/0003-provider-architecture.md`'s M3 amendment for the same
   correction from the provider-architecture side.
7. **Provider-health writes are bounded and throttled, never proportional to
   traffic.** A permanently failing provider (a corrupt database, every
   anonymous visitor) must not turn into one `update_option()` call per
   uncached request — exactly the write-amplification concern decision 3
   already rejects transients for. `ProviderHealthStore::record()` persists
   only when the scrubbed error signature (class + scrubbed message hash)
   changes, or when at least 300 seconds have elapsed since the last
   persisted write for that provider. The stored `approx_count` is
   therefore approximate by design and documented as such — an exact count
   would require exactly the write-per-request behavior this decision
   avoids. The option itself is single, non-autoloaded, and holds a fixed,
   bounded shape (one small record per known provider id, stale ids pruned
   on write) — never an unbounded event log.
8. **The option-writer allowlist is explicit, not inferred.**
   `update_option()`/`add_option()` may appear only in `src/Settings.php`,
   `src/Cache/GeoCache.php`, and `src/Diagnostics/ProviderHealthStore.php` —
   every option this plugin ever writes, by design. `PrivacyGuardTest`
   enforces this by file allowlist, the same pattern
   `TrustBoundaryGuardTest`/`NoPolicyGuardTest` already established. Landing
   this rule surfaced one real M2 drift: `src/Admin/AdminScreen.php` called
   `update_option()` directly in three places rather than through
   `Settings`, despite `Settings`'s own docblock already claiming sole
   ownership of its option. M3 corrects this as part of formalizing the
   floor: `Settings::save()` is now the one write path, and `AdminScreen`
   calls it instead of `update_option()` directly — behavior-identical,
   required by this ADR's own rule 8, not a speculative refactor.
9. **`VisitorContext` is structurally incapable of carrying an IP.** No
   property named `ip`, `ip_address`, or `client_ip` may exist on the class
   — reflection-checked, not source-text-checked, since the risk here is a
   property *existing*, not a particular function call referencing it.

### M4 amendment — the remote provider's one deliberate exception

10. **The remote provider is the one place in this codebase an IP
    deliberately leaves the server — and only when an administrator has
    explicitly enabled and acknowledged it.** Every other invariant in this
    ADR still applies unchanged: the IP reaching `ReferenceRemoteProvider`
    is the same already-resolved local variable `ContextResolver` hands
    every provider, never persisted, never logged, and discarded the
    instant the request completes. What M4 adds is a fifth option writer,
    `src/Providers/Remote/CircuitBreaker.php` — a reviewed, intentional
    exception this ADR's own M3 consequence anticipated by name ("cannot
    add a fifth option writer... without this guard going red first"). Its
    persisted state (`state`, `failure_count`, `opened_at`) contains no IP,
    no credential, and no response body.
11. **The outbound-HTTP prohibition (P4) gains its first and only allowlist
    entry, per-file and per-function.** `PrivacyGuardTest` rule 6
    (`wp_remote_get`/`wp_remote_post`/`wp_remote_request`/`curl_init`) is
    unchanged and has no exception anywhere, ever. Rule 8 is new and
    narrower: only `wp_safe_remote_get()`, only inside
    `src/Providers/Remote/WordPressHttpTransport.php` — mutation-verified
    both ways (an unauthorized provider file calling it fails the guard; the
    one authorized file calling a rule-6 function instead *also* fails the
    guard, proving the allowlist cannot be read as a blanket file
    exemption).
12. **Credentials are configuration, not personal data — but are still never
    exposed.** The account id and license key belong to the site operator,
    not to any visitor, so they fall outside this ADR's IP-specific
    invariants by definition. They still receive their own floor:
    `DiagnosticsService` and the `universal_geo_remote_provider` Site Health
    test expose only a `credentials_present` boolean and a
    `credential_source` enum, never the values; the settings-tab fields
    never round-trip the stored value; and credential precedence
    (constants vs. settings, pair-wise, never mixed) is resolved exactly
    once, in `Plugin::build_graph()`, not re-derived by any consumer.
13. **The structural transfer acknowledgement replaces a runtime consent
    check with a sanitization-time impossibility.** `Settings::sanitize()`
    forces `remote_enabled` to `false` unless
    `remote_transfer_acknowledged` is `true` in that same input — the
    forbidden source token is "consent" (`NoPolicyGuardTest`'s own word
    list), so this ADR and every implementing file use "transfer
    acknowledgement" throughout. There is no code path in which the
    provider can be enabled without the acknowledgement having been true in
    the very save that enabled it.

## Consequences

- The four-guard cap Revision 3 §2 set (`NoPolicyGuardTest`,
  `ImmutabilityGuardTest`, `TrustBoundaryGuardTest`, `PrivacyGuardTest`) is
  now met, not exceeded — `CompositionRootTest` remains deliberately
  uncounted, per its own docblock.
- `PrivacyGuardTest`'s option-writer allowlist tolerates
  `ProviderHealthStore.php`'s absence until 3D, then requires it as a real
  writer once it exists — avoiding the vacuous-pass failure mode
  `CompositionRootTest` already guards against for construction sites.
- Residual GDPR exposure is unchanged from M1/M2 and stated honestly, not
  hidden: a salted hash is still personal data in principle. Nothing in M3
  changes that; M3 only makes the boundary around it machine-enforced.
- The "cannot add a fifth option writer... without this guard going red
  first" consequence was exercised, not merely stated: M4's
  `CircuitBreaker.php` is exactly that fifth writer, added deliberately and
  reviewed here, with `PrivacyGuardTest`'s own non-vacuity test requiring it
  to be a real one.
- M4 delivers the one exception this document named in advance: the IP
  never leaves the server *by default*, and only leaves it at all once an
  administrator has made an affirmative, structurally-enforced choice — not
  a checkbox that merely defaults to unchecked, but one `Settings::sanitize()`
  itself refuses to honor without the acknowledgement present in the same
  submission.
- Uninstall's all-or-nothing gap (recorded as a known M2/M3 issue in
  `docs/PRIVACY.md`) is closed as of M4: `Settings::uninstall()`,
  `GeoCache::uninstall()`, and `AdminScreen::uninstall()` together delete
  every `universal_geo_*` option and user meta this plugin owns, including
  the new circuit-breaker option.

## Related

- `docs/PRIVACY.md` — the full persisted-data inventory and GDPR framing
  this ADR formalizes into a guard test.
- `docs/adr/0002-trusted-proxy-model.md` — decision 8's provider self-guard
  pattern, reused by `MaxMindProvider` (M3) and `ReferenceRemoteProvider` (M4).
- `docs/adr/0003-provider-architecture.md` — the M3 amendment records the
  same `ProviderHealthStore` ownership correction from the provider side;
  the M4 amendment records `ReferenceRemoteProvider`/`CircuitBreaker`/
  `HttpTransport`'s own architecture, the provider-side counterpart of this
  ADR's M4 amendment.
- `docs/SECURITY.md` — the threat model this privacy floor defends
  alongside the trust boundary, including (M4) SSRF and API-key disclosure.
