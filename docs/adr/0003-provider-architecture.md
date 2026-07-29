# ADR-0003 — Provider architecture

## Status

Accepted (M1), amended (M3, M4)

## Context

Every geographic-lookup source this plugin will ever consult — a CDN
header, a local MaxMind database, WooCommerce's own geolocation
infrastructure, a future remote API — is a different mechanism with a
different failure mode, cost, and trust level. Left to their own devices,
these sources would each need to agree on ordering, validation,
normalization, and how confident to be in their own answer. That is exactly
the kind of judgment call that must not be distributed: a provider that can
assign its own confidence, or promote itself ahead of another source, turns
the whole resolution chain into something no single place in the codebase
actually controls or can reason about.

This ADR was never written as a standalone file during M1, though the
decisions below were made then and have governed every provider since
(`CloudflareHeaderProvider`, `WooCommerceProvider`, `DefaultCountryProvider`
— M1/M2). `docs/adr/README.md` recorded the gap. M3 writes it now because
`MaxMindProvider` is the first new provider to join the chain since M2, and
the M3 architecture report requires "amending" this ADR with MaxMind's own
architectural decisions — impossible without a base document. Writing the
base document and its amendment together costs the same as writing an
amendment note with nothing to amend.

## Decision

### M1 — the base architecture

1. **Providers are pure fact carriers.** `GeoProviderInterface` is exactly
   three methods: `get_id()`, `is_available()`, `resolve(string $ip):
   ?GeoCandidate`. A provider returns a `GeoCandidate` — raw, unvalidated
   country/region facts — or `null`. It never validates, normalizes,
   caches, knows about other providers, or reads WordPress settings
   directly; those are resolver responsibilities.
2. **`ContextResolver` owns every judgment call**, centrally: ordering (the
   array it was constructed with, iterated exactly once, never re-sorted),
   validation and normalization (`GeoValidator::country()`/`region()`,
   applied uniformly to every provider's candidate), confidence
   (`ContextResolver::CONFIDENCE`, keyed by provider id — a provider cannot
   assign its own), and source attribution (`get_id()`, read exactly once,
   only for the winning candidate).
3. **A provider can never mint its own confidence or promote itself.** The
   confidence table is closed: a filter-registered provider (via
   `universal_geo_providers`) whose id isn't in the table receives a fixed,
   capped "unlisted" confidence rather than being trusted implicitly.
4. **Fixed provider order, documented not enforced by `ContextResolver`.**
   `ContextResolver::PROVIDER_ORDER` is a documentation-only constant — "the
   order `Plugin.php` builds the default array in" — never read inside the
   resolver's own loop. `Plugin.php` is the single source of ordering
   truth; the `universal_geo_providers` filter's returned array order *is*
   resolution order, replacing what might otherwise have been a
   `provider_order` setting.
5. **Short-circuit resolution, full-visibility diagnostics.**
   `ContextResolver::resolve()` stops at the first candidate whose country
   validates — a later provider's conflicting answer is never seen in
   normal operation. `probe()` exists specifically to make that visibility
   gap not a blind spot: it runs every provider, no short-circuit, no
   cache, no memo, so an administrator diagnosing "why did I get country
   X" can see every provider's raw verdict side by side.
6. **A provider can never fatal a page view.** Every `resolve()` call is
   wrapped in `try/catch(Throwable)` inside the resolver loop; a throwing
   provider is treated exactly like a miss, and the chain continues.
7. **Settings-derived values arrive pre-decided, never as a live
   dependency.** `DefaultCountryProvider` takes a single scalar
   (`string $default_country`), not a `Settings` instance —
   `CloudflareHeaderProvider`'s constructor established the same pattern
   one step earlier. `Plugin.php` reads configuration once and hands
   providers plain values; no provider ever queries `Settings` or `get_option()`
   itself.
8. **`GeoProviderInterface` has no generic "needs a public IP" flag.** The
   public-address gate (ADR-0002 decision 8) is each IP-based provider's own
   responsibility inside `resolve()`, not something the frozen three-method
   interface can express generically or something `ContextResolver`
   pre-filters by inspecting `ResolvedClientIp::is_public`.

### M3 amendment — MaxMind as a soft-dependency provider

9. **MaxMind is a provider like any other**, filling the previously-empty
   `'maxmind'` slot in `PROVIDER_ORDER` (confidence 0.90, between
   `cloudflare` at 0.95 and `woocommerce` at 0.85) — no special-cased
   integration layer, following the exact precedent ADR-0006 established
   for WooCommerce.
10. **The soft dependency on `MaxMind\Db\Reader` is runtime-only, never
    bundled.** `maxmind-db/reader` is a dev-only Composer dependency (test
    runtime for the real `Reader` class); `bin/build-zip.sh` already
    refuses to ship dev vendors. At runtime, the class is provided — or
    not — by whatever else is installed on the site (most commonly
    WooCommerce's own vendored copy, when WooCommerce's MaxMind
    integration is present). `MaxMindProvider::is_available()` checks
    `class_exists()` before ever assuming the class exists.
11. **A 1.1 companion-plugin escape hatch remains open, deliberately
    unbuilt in v1.** Nothing in this architecture prevents a future,
    separate plugin from vendoring `maxmind-db/reader` itself purely to
    supply the runtime class on a site with no WooCommerce MaxMind
    integration — that plugin would need to do nothing but exist and be
    active; `MaxMindProvider`'s `class_exists()` check already covers it.
    Building that companion plugin is out of v1's scope entirely, named
    here only so the soft-dependency design is understood to accommodate
    it without modification.
12. **The effective database path has three trust levels, not two.** A
    `wp-config.php` constant (`UNIVERSAL_GEO_MAXMIND_DB`) is a code-level
    override — the highest trust level, may point anywhere on disk,
    unreachable from an admin-panel attacker. The admin setting and the
    WooCommerce-auto-detected candidate are both *option-derived* — a
    strictly lower trust level, since an attacker who can write options
    (a lesser privilege than editing `wp-config.php`) must not gain a
    file-read primitive; both are constrained under `WP_CONTENT_DIR`,
    revalidated at graph construction, not merely at the moment the admin
    form was submitted. The `universal_geo_maxmind_db_path` filter is a
    third, code-level surface — uncontained like the constant, but
    (unlike the constant) always consulted unless the constant already
    won, and hardened identically to every other defensive filter in this
    codebase (`filtered_default_country()`, `filtered_providers()`):
    non-string results are discarded with `_doing_it_wrong()`, the
    pre-filter value is kept.
13. **Diagnostics gets a dedicated metadata surface, not database access
    of its own.** `MaxMindProvider::metadata(): ?MaxMindMetadata` is a
    concrete method beyond the three-method interface contract (the
    interface itself is not widened) — the same instance `ContextResolver`
    uses is injected into `DiagnosticsService`, so admin diagnostics reads
    the already-open reader's own metadata block rather than opening a
    second one. This is the identical shape decision 7's confidence table
    already established: a capability that must exist exactly once lives
    on the concrete class, never duplicated by a second consumer
    independently deriving it.
14. **Region stays out of scope even for a City database.** MaxMind's City
    databases carry subdivision data; `MaxMindProvider::resolve()` reads
    `country.iso_code` only and always returns a `null` region — region
    support (ISO 3166-2, GeoLite2-City) is explicitly 1.1, and diagnostics
    surfaces an informational note when a City database is detected rather
    than silently under-using it.

### M4 amendment — the remote provider

15. **`ReferenceRemoteProvider` is a provider like any other**, filling the
    previously-empty `'remote'` slot in `PROVIDER_ORDER` (unchanged
    confidence `0.85`, between `woocommerce` and `default`) — the identical
    "no special-cased integration layer" precedent decisions 1 and 9 already
    established, extended a second time.
16. **Transport and provider responsibilities are split, frozen, and never
    blurred.** An internal one-method interface, `HttpTransport`, executes
    exactly one outbound GET and converts the raw result into a
    `TransportResponse` value object or a scrubbed `TransportException` —
    nothing more. `ReferenceRemoteProvider` alone builds the hardcoded
    request URL, adds HTTP Basic authentication, classifies the response
    status code, and parses the JSON body. `WordPressHttpTransport` is the
    only production file permitted to call `wp_safe_remote_get()`
    (`PrivacyGuardTest` rule 8) and is barred, like every other file, from
    the four already-forbidden remote-HTTP primitives (rule 6) — the
    allowlist is per-file *and* per-function, never a blanket exemption.
17. **A dedicated `CircuitBreaker`, not a bespoke retry mechanism, gates
    every attempt.** `closed` → `open` (three consecutive failures) →
    `half_open` (after a 300-second cooldown, exactly one trial) →
    `closed`/`open` again. No retries exist at any layer — a denied attempt
    is a miss, not a queued retry, and the resolver's existing
    `catch(Throwable)` boundary (decision 6) is what actually degrades a
    thrown failure to "continue the chain," not anything new in
    `CircuitBreaker` itself.
18. **Credential precedence is pair-wise, resolved exactly once.**
    `Plugin::build_graph()` decides, in one place, whether the
    `UNIVERSAL_GEO_REMOTE_ACCOUNT_ID`/`UNIVERSAL_GEO_REMOTE_LICENSE_KEY`
    constants or the `remote_account_id`/`remote_license_key` settings pair
    is in effect — never one constant combined with one setting — and hands
    `ReferenceRemoteProvider` only the resulting plain scalars, the same
    "settings-derived values arrive pre-decided" pattern decision 7
    established. `DiagnosticsService` receives only the resulting source
    scalar (`'constants'`/`'settings'`/`'none'`) and neither calls
    `defined()` nor re-derives this precedence itself.
19. **The structural transfer acknowledgement is a pure sanitization rule,
    not a runtime check.** `Settings::sanitize()` forces `remote_enabled` to
    `false` unless `remote_transfer_acknowledged` is `true` in the same
    input — the provider can never observe an enabled-without-acknowledged
    state, so `ReferenceRemoteProvider` itself has no acknowledgement logic
    of its own to duplicate or drift from `Settings`'s.
20. **`is_available()` is a defense-in-depth double-check, not the sole
    gate.** Mirroring `MaxMindProvider`'s own precedent (a corrupt/absent
    database degrades to "no reader, no attempt" even if `resolve()` were
    somehow called without an `is_available()` check first),
    `ReferenceRemoteProvider::resolve()` re-checks its own enabled/credential
    state at its own top, before ever consulting the circuit breaker or the
    transport — a disabled or misconfigured instance makes zero outbound
    attempts under any call pattern, not merely the one the resolver loop
    happens to use.

## Consequences

- Every provider added since M1 (`CloudflareHeaderProvider`,
  `WooCommerceProvider`, `MaxMindProvider`, and now `ReferenceRemoteProvider`)
  has satisfied the same three-method contract with zero interface
  changes — the strongest evidence the M1 architecture was sized correctly
  the first time.
- A provider author (including a third party registering one via
  `universal_geo_providers`) never needs to understand confidence,
  ordering, or validation — only "can I look something up right now" and
  "what did I find."
- `ContextResolver` remains WordPress-free and its constructor signature
  frozen at three required arguments plus one optional callback (M2) —
  MaxMind's addition touched `Plugin.php`'s composition and
  `DiagnosticsService`'s injection, never `ContextResolver` itself; the
  remote provider's addition (M4) is the second confirmation of the same
  invariant — `ContextResolver.php` has zero diff for M4.
- The path-resolution trust-level distinction (decision 12) is the direct
  ancestor of `docs/SECURITY.md`'s "arbitrary file read via a MaxMind
  database path" threat-model row moving from "future" to
  "mitigated-as-shipped."
- The companion-plugin escape hatch (decision 11) is named but not
  designed in detail — a genuine future decision, not a commitment this
  ADR makes on 1.1's behalf.

## Related

- `docs/adr/0002-trusted-proxy-model.md` — decision 8's provider self-guard
  pattern, reused verbatim by `MaxMindProvider`.
- `docs/adr/0005-privacy-model.md` — the privacy floor MaxMind's path
  resolution and provider-health recording both operate inside.
- `docs/adr/0006-optional-woocommerce-integration.md` — the "provider, not
  a special integration layer" precedent this ADR's decision 9 follows.
- `docs/SECURITY.md` — the arbitrary-file-read threat model decision 12
  defends against, and (M4) the SSRF/API-key-disclosure/DoS rows decisions
  16–18 defend against.
- `docs/HOOKS.md` — `universal_geo_maxmind_db_path`'s full signature and
  hardening behavior.
- `docs/PRIVACY.md` — the GDPR framing decision 19's structural
  acknowledgement rule implements.
