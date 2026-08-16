# v1.9.0 Implementation Plan: Cache-Safe Visitor Context (M14)

**Status**: Implementation phase
**Release**: Minor feature (v1.8.1 → v1.9.0)
**Milestone**: M14 — the first substantive capability milestone since v1.8.0/M13.

---

## Core Architectural Requirement

**Full-page/CDN caching can serve visitor A's geography-derived HTML to visitor B, because
cached HTML replay never re-runs PHP.** `docs/SECURITY.md` already documents this as
unsolved. M14 closes the gap with exactly one new capability: a minimal, read-only REST
endpoint that lets a consumer fetch the *current* visitor's context after cached HTML has
already loaded, without introducing a second resolution path, without becoming a policy
engine, and without expanding disclosure beyond two geographic facts.

UGC remains a fact provider only. No currency, pricing, tax, language, shipping, consent,
or personalization logic enters this plugin as part of this milestone.

---

## Frozen Decisions

These are settled, not implementation-time choices:

### A. Route

`GET /wp-json/universal-geo-context/v1/context` — GET only, `permission_callback =>
__return_true` (public, anonymous). No mutation, no CSRF-sensitive operation, no `ip=`
parameter, no CORS expansion.

### B. Controller dependency — no `Plugin` service locator

`ContextController` depends on a plain `callable`, never on `Plugin`:

```php
/** @var callable(): VisitorContext */
private $context_provider;

public function __construct( callable $context_provider ) {
    $this->context_provider = $context_provider;
}
```

Composition root (`Plugin::init()`) supplies a callable bound to `$this`. This mirrors the
existing `ContextResolver::$on_provider_failed` callable-injection precedent
(`src/Resolver/ContextResolver.php`) rather than inventing a new `Contracts/` interface
implemented by the composition root itself. `ContextController` must not import `Plugin`,
type-hint `Plugin`, or call `Plugin::instance()`.

**Amended by Amendment 1** (see below Section 0): the callable is
`array( $this, 'effective_context' )`, not `array( $this, 'context' )` as originally
written here — `context()`'s request-level memoization was found unsafe for this specific
REST use case. `ContextController`'s own design above (opaque callable, no method name
assumed) required zero changes to accommodate this.

### C. REST v1 response contract — frozen key set, not additive

```json
{
    "country_code": "SE",
    "region_code": null
}
```

Exactly these two keys, always present, values mirroring `VisitorContext::$country_code`/
`$region_code` semantics exactly (uppercase ISO 3166-1 alpha-2 or `null`; uppercase
1-3-alphanumeric subdivision code or `null`). **No third key may ever be added to `v1`.**
Any contract change — add, remove, rename, retype — requires a `/v2` namespace. The
controller maps `VisitorContext` to this shape via one explicit, named method; it must
never call `VisitorContext::to_array()` and must never reference
`VisitorContext::SCHEMA_VERSION`. `confidence`, `source`, and `is_cached` are deliberately
excluded — no worked consumer example in `docs/API.md` reads them, and `source`
specifically would leak which internal provider is active (infrastructure fingerprinting)
for no offsetting consumer benefit.

### D. No new UGC-specific hook

No `universal_geo_rest_enabled` filter or any other new public hook. WordPress's native
`rest_endpoints` filter is documented (`docs/API.md`) as the operator opt-out path.

### E. Cache semantics

Every response carries `Cache-Control: no-store`. This is emitted as the correct HTTP
instruction, not claimed as a guarantee against every possible non-compliant intermediary
— UGC cannot control a misconfigured cache that ignores the header. Deployed acceptance
(below) verifies the actual `dev.biopentra.eu` environment honors it; that is not the same
claim as "every CDN topology worldwide honors it."

### F. Trust boundary — reused unmodified

No new IP-resolution code. `ServerRequest`/`ClientIpResolver` are untouched;
`ContextController` never reads `$_SERVER` or `$_GET` for geography and never accepts a
caller-supplied IP.

### G. Managed GeoLite2 City — out of scope

Remains separately deferred under ADR-0010. Not touched by this milestone.

### H. Multisite — not proven, not broadened

The integration harness has no multisite bootstrap (`WP_TESTS_MULTISITE` is not set
anywhere in `tests/integration/bootstrap.php` or `tests/bin/install-wp.sh`). The REST
surface inherits UGC's existing, unchanged "network activation supported but untested"
framing. No new multisite claim is made for this route specifically, and no multisite
features are added to make a test possible.

---

## Section 0 — REST auth timing vs. context memoization (SUPERSEDED — see Amendment 1)

**This section's central claim — "for a stock UGC installation, the ordering guarantee
holds" — was empirically disproven during implementation.** It is left in place, unedited,
for governance history; do not act on it. See **Amendment 1**, immediately below the
original text, for the corrected finding, the fix, and why `Plugin::context()` itself was
deliberately left unchanged rather than repaired directly.

`Plugin::context()` (`src/Plugin.php`) memoizes once per request. WordPress's REST cookie
authentication (`rest_cookie_check_errors()`/`rest_cookie_collect_status()`, WP core
`wp-includes/rest-api.php`, verified directly against the actual bundled test-harness
core) determines nonce validity as a side effect of the *first* call to
`is_user_logged_in()`/`wp_get_current_user()` in the request — a determination that is
deterministic and depends only on already-available request data (the auth cookie and the
`X-WP-Nonce` header, both present in `$_SERVER`/`$_COOKIE` from process start), not on
*when* in the request lifecycle that first call happens.

For UGC's own registration path specifically: nothing in `Plugin::init()`'s unconditional
block calls `is_user_logged_in()` or `Plugin::context()` eagerly, and REST requests never
satisfy `should_register_admin()`'s `is_admin()` gate, so none of UGC's own admin/CLI/
diagnostics services are even constructed during a REST request. The only call to
`$context_provider` in a REST request is `ContextController::get_context()`'s own,
which runs after WordPress's REST dispatch has already resolved authentication
(`WP_REST_Server::dispatch()` calls `check_authentication()` before invoking any route
callback). **For a stock UGC installation, the ordering guarantee holds.**

A residual, general WordPress-ecosystem risk exists only if some *other*, third-party
plugin active on the same site independently calls `Plugin::context()` (or triggers
`is_user_logged_in()`) even earlier in the same REST request, before WordPress's own
nonce-driven downgrade path runs. This is not introduced or worsened by M14 — it is a
pre-existing property of `Plugin::context()`'s single-memoization-per-request design
(shipped since M1), and per WordPress's own deterministic nonce-checking mechanism
(verified above), an early call still resolves the *same correct* answer in practice,
because the nonce/cookie data it depends on never changes mid-request. This is documented
here rather than silently assumed, per the explicit instruction not to invent a workaround
or weaken memoization without approval — no such change is being made.

**Verification**: `tests/integration/Rest/ContextControllerTest.php` includes a scenario
that deliberately triggers `is_user_logged_in()`/`Plugin::context()` before dispatching the
REST request, for both the valid-nonce and no-nonce cases, and asserts the final response
matches what the ordering guarantee above predicts. If that test contradicts this section,
implementation stops and the conflict is reported rather than forced through.

---

## Amendment 1 — REST auth-timing correction (post-freeze)

**Status at time of writing**: implemented and test-verified; M14 resumed from this point.

### What the verification actually found

Running the exact test Section 0 promised (`test_early_context_access_can_leak_simulation_without_nonce`,
now superseded by the tests listed below) proved the opposite of Section 0's prediction.
Two facts, both confirmed directly against the bundled WordPress core source
(`wp-includes/rest-api.php`, `wp-includes/rest-api/class-wp-rest-server.php`), not assumed:

1. **The REST nonce downgrade is not a general property of `is_user_logged_in()`.** It
   lives entirely inside `rest_cookie_check_errors()`, which is only ever invoked as a side
   effect of `WP_REST_Server::check_authentication()` — itself only called from
   `serve_request()`, immediately before `dispatch()`. A bare call to
   `is_user_logged_in()`/`Plugin::context()` from anywhere else in the same request resolves
   the *true* cookie identity, completely unaffected by whether a nonce was ever sent for
   *this* request.
2. **This is exactly the situation `docs/API.md`'s own documented consumer pattern
   creates.** A consumer plugin calling `universal_geo_get_country_code()` on
   `woocommerce_init`/`init` — hooks that fire on every request type, including REST
   requests, well before `check_authentication()` runs — legitimately (and correctly, in
   isolation) observes an authenticated admin's active simulation, and
   `Plugin::context()`'s per-instance memo then serves that same, now-stale result to
   every later caller in the same request/process, including our REST route's own
   `get_context()` callback — even though `check_authentication()` had separately, and
   correctly, decided *this specific request* had no valid nonce and should be anonymous.

This is a real, reproducible leak of simulated data into a request WordPress's own REST
layer had already decided to anonymize. Not a theoretical edge case: it is the documented,
designed multi-plugin usage pattern this milestone exists to serve.

### Why `Plugin::context()` was left unchanged

`Plugin::context()`'s memoization is the frozen, six-function PHP API's own behavior,
documented and relied upon since M1 (`ARCHITECTURE_FREEZE.md` §14.2/§16). Changing its
memoization semantics globally would:
- change observable behavior for every existing PHP consumer (Universal Multicurrency-style
  plugins), not just the new REST surface;
- require touching `src/api.php`'s implicit contract even though no function signature
  changes;
- conflate two genuinely different needs — "one consistent value for the whole
  request, for direct PHP callers" (correct and wanted) and "a value that reflects
  *this specific caller's own* authorization state at the moment it asks" (what REST
  needs) — inside one memoization flag.

The instruction was explicit: do not weaken memoization or change `Plugin::context()`
semantics. The fix instead adds a second, narrow, `@internal` read path.

### The fix

`src/Plugin.php`:
- Extracted the shared filter pipeline (resolve → `apply_filters('universal_geo_context', ...)`
  → validate → `do_action('universal_geo_context_resolved', ...)`) into one private method,
  `resolve_and_filter_context()` — the single implementation both paths below use, so they
  cannot become two subtly different effective-context algorithms.
- `context()` is unchanged in every externally observable way: still memoized on
  `$this->public_context`/`$this->context_resolved`, still returns the identical instance
  on a second call, still the six PHP functions' only path. Internally it now just calls
  `resolve_and_filter_context()` once and caches the result — same behavior, extracted
  implementation.
- New `public function effective_context(): VisitorContext` — `@internal`, never added to
  `src/api.php`, never bumps `universal_geo_api_version()`, no new hook. Calls
  `resolve_and_filter_context()` fresh on every call, never reading or writing
  `$public_context`/`context_resolved`. Public PHP visibility only because a callable array
  invoked from `ContextController` (a different class) requires it — this is not a new
  public *contract*, only a composition-root wiring necessity, exactly like `context()`
  itself already was for the six PHP functions.

`src/Plugin.php`'s composition-root wiring (`Plugin::init()`'s unconditional block) now
passes `array( $this, 'effective_context' )` to `ContextController`, not
`array( $this, 'context' )`. `ContextController` itself required zero changes — it was
already designed (§B) to depend on an opaque `callable`, never on `Plugin` or on any
particular method name.

### Why the expensive path is not duplicated

`resolve_and_filter_context()` calls `$this->resolver->resolve()` — `ContextResolver`'s own
existing per-instance memo (`private ?VisitorContext $memo`, unchanged, `src/Resolver/ContextResolver.php`)
already short-circuits every call after the first, regardless of which of `context()` or
`effective_context()` is calling it. Only the filter/action re-application repeats on each
`effective_context()` call — cheap, no I/O: `SimulationContextFilter::apply()` reads a
signed cookie and a capability check, nothing else. Verified directly (not assumed): calling
`effective_context()` three times against a live-provider-configured graph produces exactly
one outbound provider call.

### Hook-frequency note (documented, not a semantic change)

`universal_geo_context`/`universal_geo_context_resolved` can now fire more than once per
request in total (once per `context()` call — still at most once, memoized — plus once per
`effective_context()` call, e.g. once per REST dispatch). The filter/action's own contract
(input/output shape, purpose, "runs first and gets the last word") is unchanged; only the
number of independent evaluations per request can now exceed one. `SimulationContextFilter`
was already idempotent and side-effect-free, so this is safe for the plugin's own callback;
`docs/HOOKS.md`'s "Fires" column is updated accordingly under WP6.

### Acceptance criteria (all met, verified — see `tests/integration/Rest/ContextControllerTest.php`)

- A. `Plugin::context()`'s memoization and simulation-awareness: byte-for-byte unchanged
  (`test_plugin_context_memoization_is_unchanged`).
- B–D. Normal REST behavior (anonymous, valid-nonce+simulation, no-nonce+no-early-access):
  unchanged, still passing.
- E. Early `Plugin::context()` access + no REST nonce → REST response now returns **real**
  context, not the leaked simulated value
  (`test_early_context_access_no_nonce_returns_real_context_not_leaked_simulation`).
- F. Early `Plugin::context()` access + valid REST nonce → REST response still correctly
  returns the **simulated** country (`test_early_context_access_with_valid_nonce_still_sees_simulated_country`)
  — proves `effective_context()` is independently authorization-sensitive in both
  directions, not merely hardcoded to ignore prior state.
- G. Three `effective_context()` calls against a live-provider-configured graph produce
  exactly one outbound provider call (`test_effective_context_does_not_repeat_provider_resolution`).

All 12 tests in that file pass; the full unit suite (2208 tests) and full integration
suite (128 tests) pass; PHPCS is clean repo-wide.

---

## Work Packages

### WP1: `ContextController`

`src/Rest/ContextController.php`, namespace `UniversalGeo\Rest`. Per §B (DI) and §C
(contract). Must not: import/type-hint `Plugin`; call `Plugin::instance()`; access
`$_SERVER`/`$_GET`; accept an IP override; resolve providers directly; access `GeoCache`
directly; apply simulation itself; implement policy.

### WP2: Composition root

Wire `ContextController` only from `Plugin::init()`'s existing unconditional registration
block (`src/Plugin.php`, alongside `SimulationContextFilter`/`SimulationAdminBar`/
`update_scheduler->register(...)`), using `array( $this, 'context' )`. No new interface,
no new service locator. Does not touch `ContextResolver`, `ServerRequest`,
`ClientIpResolver`, `GeoCache`, `VisitorContext`, providers, simulation, or `src/api.php`.

### WP3: Public surface discipline

No `universal_geo_rest_enabled` filter. Document the core `rest_endpoints` opt-out recipe
in `docs/API.md`. No settings UI. No REST mutation/control endpoint. No AJAX endpoint. No
CORS changes. No `ip=` parameter.

### WP4: REST contract regression protection

Unit tests establishing the exact two-key contract and null semantics
(`tests/unit/Rest/ContextControllerTest.php`), enabled by the callable-based DI (no
WordPress bootstrap required). A structural source-scan guard (mirroring
`TrustBoundaryGuardTest`/`PassiveDiagnosticsGuardTest`) asserting
`src/Rest/ContextController.php` never contains the literal strings `to_array(`,
`SCHEMA_VERSION`, or `Plugin::instance()`. No `VisitorContext` subclass/mock tricks.

### WP5: Integration

`tests/integration/Rest/ContextControllerTest.php`: route registration; GET-only; anonymous
HTTP 200 with the exact two-key body; unknown context gives two nulls; no prohibited
fields (`source`, `is_cached`, `confidence`, `schema_version`, IP, proxy chain,
credentials); `no-store` header present; no arbitrary-IP behavior; route registers outside
the admin/CLI mutual-exclusion gates; no `PassiveDiagnosticsGuardTest` regression.
Simulation scenarios: (A) admin + active simulation + valid `X-WP-Nonce` → simulated
country, region null; (B) same request without a nonce → real context, per the WP core
nonce-downgrade mechanism (§10); (C) anonymous request with a simulation cookie present →
real context; (D) the Section 0 early-memoization scenario. If (D) contradicts §10's
predicted ordering: STOP, do not continue toward release.

### WP6: Documentation and ADR

`docs/adr/0012-cache-safe-visitor-context.md` per the required-contents list below.
Updates to `docs/API.md`, `docs/SECURITY.md`, `docs/PRIVACY.md`, `docs/COMPATIBILITY.md`,
`docs/ROADMAP.md`, `docs/ARCHITECTURE_FREEZE.md` §15, ADR index. No unrelated general doc
cleanup mixed in.

### WP7: Version/release metadata

`1.9.0` in every location `VersionParityTest`/`bin/build-zip.sh`/`bin/release-audit.sh`
actually check — not a manually-guessed list. Regenerate the `.pot` file via
`composer make-pot`.

---

## ADR-0012 required contents

- Read-only REST surface; no relationship to the simulation *control* surface (which
  remains POST + nonce + `admin_post_*` only, per `ARCHITECTURE_FREEZE.md` §22).
- Independent, frozen REST v1 contract (two keys, no `/v1` additions ever).
- Trust boundary reused unmodified; callable DI, no `Plugin` dependency in the controller.
- No `to_array()`/`SCHEMA_VERSION` coupling.
- PHP API v1 (`src/api.php`, `universal_geo_api_version()`) unchanged.
- `Cache-Control: no-store` is an HTTP policy UGC emits, not a guarantee against
  non-compliant intermediaries.
- Deployed acceptance validates `dev.biopentra.eu` only, not every CDN topology.
- Multisite: not proven, no new claim.
- No new UGC hook.
- Consumer owns all policy.

---

## Automated Verification Gate

Before release acceptance, run the complete current gate as defined by the repository's
actual workflows (not a hardcoded historical list): `composer validate --strict`, PHPCS,
POT drift check, `VersionParityTest`, all architecture guard tests (`CompositionRootTest`,
`PrivacyGuardTest`, `TrustBoundaryGuardTest`, `NoPolicyGuardTest`, `ImmutabilityGuardTest`,
`PassiveDiagnosticsGuardTest`, plus the new REST structural guard), the full unit suite on
PHP 8.1/8.3/8.4, the full integration matrix (floor, current, mixed-php-floor,
mixed-wp-floor, ceiling), `bin/release-audit.sh`, and a production ZIP build/inspection.
Use `.github/workflows/ci.yml` as the authority for which jobs are required vs.
`continue-on-error`.

---

## Mandatory Deployed HTTP/Cache Acceptance (dev.biopentra.eu)

PHPUnit cannot prove the real HTTP/proxy/CDN path. Before merge/tag/release, verify against
the actual deployed environment (disposable external/browser tooling only — never
permanent Node/Playwright repository infrastructure):

1. Anonymous external GET returns HTTP 200 through the real SWAG/proxy chain.
2. Body is exactly the two-key contract, correct null/string semantics.
3. `Cache-Control: no-store` reaches the actual external client.
4. Repeated requests through the real proxy path show no shared-cache reuse (inspect
   `Cache-Control`/`Age`/`CF-Cache-Status`/equivalent headers; do not spoof
   `X-Forwarded-For` or weaken trusted-proxy config to manufacture a second identity —
   per-IP resolution correctness is already proven at the unit-test layer).
5. A real full-page-cached HTML page's own JS can still fetch fresh context.
6. Admin simulation + valid `X-WP-Nonce` → simulated country, region null.
7. Same session without the nonce → real-context behavior, matching WP5/integration.
8. Anonymous requests cannot inherit/activate simulation.
9. No IP, proxy chain, credentials, provider name/source, diagnostic detail, `is_cached`,
   `confidence`, or `schema_version` in the response.
10. No new PHP/browser console errors attributable to M14.
11. Restore simulation/QA state afterward.

State explicitly that this proves the specific `dev.biopentra.eu` deployment, not every
possible CDN topology.

---

## Pre-Release Forensic Review

Before merge, diff the full branch against the updated main baseline and confirm: only
approved M14 scope; no M15 work; no Managed City work; PHP API v1/`VisitorContext`/
`ContextResolver`/provider order/trust boundary/`GeoCache` contract/simulation-control
surface all unchanged; no settings schema bump; no new hook; no CORS expansion; no
arbitrary-IP endpoint; no bundled JS; no policy logic; `ContextController` never depends
on `Plugin`; REST schema exactly two fields, frozen; no `to_array()` coupling; multisite
claim not broadened; no credential/full-IP leakage; no unrelated refactoring; working tree
clean.

---

## Release Decision

If any required gate or the mandatory deployed acceptance fails: **STOP**. Do not merge,
tag, or release. Report the blocker precisely. If everything passes: fast-forward merge to
main (only if valid), push, wait for exact-commit CI green, then tag `v1.9.0` on that exact
commit — never before CI is green on the final main commit.
