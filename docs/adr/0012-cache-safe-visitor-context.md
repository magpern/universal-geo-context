# ADR-0012: Cache-Safe Visitor Context (REST v1)

**Status**: Accepted (M14 / v1.9.0)

**Date**: 2026-08-16

## Context

Full-page and CDN caching can serve visitor A's geography-derived HTML to visitor B,
because a cached-hit page never re-runs PHP. `docs/SECURITY.md` already documented this as
unsolved, sketching "resolve client-side via a REST endpoint" as a future direction (not
shipped in v1). M14 closes the gap with exactly one new capability: a minimal, read-only
REST endpoint a consumer's own JavaScript can call after cached HTML has already loaded,
to get the *current* visitor's context.

The forensic roadmap review preceding this milestone concluded UGC is otherwise mature for
its scope and that this is the one genuinely unsolved product-level gap remaining. Managed
GeoLite2 City support (ADR-0010) is a separate, still-deferred decision, untouched here.

## Decision

### Route and contract

`GET /wp-json/universal-geo-context/v1/context` — GET only,
`permission_callback => __return_true` (public, anonymous; the response contains no
privileged data). The response body is a **frozen, non-additive** two-key contract:

```json
{ "country_code": "SE", "region_code": null }
```

Deliberately not `VisitorContext::to_array()`: that method serves `GeoCache`'s own
round-trip, a different consumer with different needs (it includes `source`, `is_cached`,
`confidence`, `schema_version`). None of those four fields are exposed here — no worked
consumer example in `docs/API.md` reads them, and `source` specifically would leak which
internal provider (Cloudflare/MaxMind/WooCommerce/remote/default) is active on a given
site, an infrastructure-fingerprinting signal with no offsetting consumer benefit. Any
future addition, removal, rename, or type/null-semantics change to this contract requires
a `/v2` namespace — `v1`'s two keys are permanent for the lifetime of that namespace.

No `ip=` parameter, no CORS expansion, no rate limiter built by UGC (host/CDN/WAF
responsibility, as for any public route), no new UGC-specific hook (WordPress's native
`rest_endpoints` filter is documented in `docs/API.md` as the operator opt-out path — a
site-specific `universal_geo_rest_enabled` filter would be a new permanent public
compatibility contract with no demonstrated requirement it doesn't already satisfy).

### Trust boundary: reused unmodified

`ContextController` never reads `$_SERVER`/`$_GET`, never accepts a caller-supplied IP,
and performs no resolver/provider logic of its own — enforced by
`tests/unit/Guards/RestContractGuardTest.php` and the pre-existing, repo-wide
`TrustBoundaryGuardTest`. A REST request boots through the exact same
`plugins_loaded`/`ServerRequest::capture($_SERVER)` sequence as any other WordPress
request, so `ClientIpResolver`'s fail-closed trust logic applies identically with zero new
code.

### Controller dependency: callable, never `Plugin`

`ContextController` depends on a plain `callable`, never on `Plugin`:

```php
/** @var callable(): VisitorContext */
private $context_provider;

public function __construct( callable $context_provider ) {
    $this->context_provider = $context_provider;
}
```

Mirrors the existing `ContextResolver::$on_provider_failed` callable-injection precedent
rather than a new `Contracts/` interface implemented by the composition root itself.
`ContextController` never imports `Plugin`, never type-hints `Plugin`, never calls
`Plugin::instance()` — enforced by `RestContractGuardTest`.

### The REST auth-timing correction: `Plugin::effective_context()`

The original implementation wired `array( $this, 'context' )` — `Plugin::context()`, the
same memoized method the six PHP functions use. Integration testing (per the plan's own
"Section 0" investigation requirement) proved this unsafe for a REST route specifically:

WordPress's REST nonce downgrade (`rest_cookie_check_errors()`, which anonymizes a
cookie-authenticated request when no valid `X-WP-Nonce` is present) runs **only** as a
side effect of `WP_REST_Server::check_authentication()`, called from `serve_request()`
immediately before `dispatch()` — **not** as a general property of `is_user_logged_in()`.
`docs/API.md`'s own documented consumer pattern (a plugin calling
`universal_geo_get_country_code()` on `woocommerce_init`/`init` — hooks that fire on every
request type, including REST, well before `check_authentication()` runs) can therefore
legitimately observe the true, un-downgraded authentication state and memoize it via
`Plugin::context()`'s per-instance cache. Every later caller in the same
request/process — including this route's own `get_context()` callback — then received that
same, now-stale result, even on a REST dispatch `check_authentication()` had separately,
correctly decided should be anonymous. Proven empirically with a live test before being
accepted as a defect, not merely reasoned about: see
`tests/integration/Rest/ContextControllerTest.php`.

`Plugin::context()` itself was deliberately left unchanged — it is the frozen six-function
PHP API's own behavior since M1, and changing its memoization globally would alter
observable behavior for every existing PHP consumer, not only the new REST surface, and
would conflate two different needs (one stable value per request for direct callers, vs. a
value reflecting *this specific caller's* authorization state at the moment it asks).

Instead, `src/Plugin.php` extracts the shared filter pipeline (resolve → apply
`universal_geo_context` → validate → fire `universal_geo_context_resolved`) into one
private `resolve_and_filter_context()` method, used by both:
- `context()` — unchanged behavior, still memoized on `$public_context`/`$context_resolved`.
- `effective_context()` — new, `@internal`, never memoized, wired into `ContextController`
  in place of `context()`. Not part of the public API: absent from `src/api.php`, no
  `universal_geo_api_version()` bump, no new hook. Public PHP visibility only because a
  callable array invoked from a different class (`ContextController`) requires it.

The expensive part — provider resolution, `GeoCache` — is unaffected: both methods call
`$this->resolver->resolve()`, and `ContextResolver`'s own existing per-instance memo
already absorbs repeat calls regardless of caller. Verified directly: three
`effective_context()` calls against a live-provider-configured graph produce exactly one
outbound provider call.

`universal_geo_context`/`universal_geo_context_resolved` can now fire more than once per
request in total (once per `context()` call, still at most once; plus once per
`effective_context()` call). The filter/action's own contract — input/output shape,
purpose, "runs first and gets the last word" — is unchanged; only the number of
independent evaluations per request can exceed one. `SimulationContextFilter` was already
idempotent and side-effect-free, so this is safe for the plugin's own callback;
`docs/HOOKS.md`'s "Fires" column documents this.

### Simulation: no new control surface, unchanged semantics

This route has no start/change/stop capability and does not reopen the
`ARCHITECTURE_FREEZE.md` §22 prohibition on a public REST/AJAX *control* surface for
simulation (which remains POST + nonce + `admin_post_*` only). `effective_context()` calls
the exact same `universal_geo_context` filter `SimulationContextFilter` already hooks —
region remains unconditionally `null` under simulation, matching the frozen contract,
with no special-casing in `ContextController`.

A valid `X-WP-Nonce` is required for an authenticated admin's simulation to be reflected —
without one, WordPress's own REST cookie authentication anonymizes the request before
`ContextController` ever runs. Documented as a real, non-obvious behavior consumer JS
needs to account for, not an error condition.

### Cache semantics: a policy UGC emits, not a guarantee it can enforce

Every response carries `Cache-Control: no-store`. This is the correct HTTP instruction
that the response must not be stored by browser, reverse-proxy, or shared caches — UGC
cannot guarantee every intermediary worldwide honors it, only that it emits the correct
signal and that the *specific deployed acceptance target* (`dev.biopentra.eu`) was verified
to honor it. Operators running other, unverified caching layers must independently confirm
their own layer respects it.

### Multisite: not proven, not broadened

The integration harness has no multisite bootstrap (`WP_TESTS_MULTISITE` is not set
anywhere in `tests/integration/bootstrap.php` or `tests/bin/install-wp.sh`). This route
inherits UGC's existing, unchanged "network activation supported but untested" framing —
no stronger claim is made for it specifically, and no multisite features were added to
make a test possible.

## Consequences

- Consumers (Universal Multicurrency-style plugins) gain a correct way to get fresh,
  per-visitor context after cached HTML has already loaded, closing the one genuine
  product gap the forensic review identified.
- UGC remains a fact provider only — the response is two geographic facts, no policy.
- `Plugin` gains one new `@internal` public method; its frozen, six-function public
  contract and `context()`'s own memoization are unchanged.
- A REST-specific class of bug (cross-request-shape authorization staleness) is now a
  known, tested pattern in this codebase — any future REST/AJAX surface added to UGC
  should default to a non-memoized effective-context read, not `Plugin::context()`.

## Related

- ADR-0008 (country simulation framework) — the filter/cache-isolation guarantees this ADR
  relies on unchanged.
- ADR-0010 (region/subdivision support) — managed GeoLite2 City remains separately
  deferred, untouched by this milestone.
- ADR-0011 (passive diagnostics invariant) — the most recent prior example of a
  regression-guard-enforced architectural boundary in this codebase; `RestContractGuardTest`
  follows the same source-scan-guard pattern `PassiveDiagnosticsGuardTest` established.
- `docs/PLAN-v1.9.0.md` — the frozen implementation specification and its Amendment 1,
  which records the empirical finding and correction this ADR formalizes.
