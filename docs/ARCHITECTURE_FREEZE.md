# Architecture Freeze — v1.x

**Status: Universal Geo Context v1.3.0 (M8) released. Architecture frozen through M8.**

This document constitutes the permanent architectural baseline for the v1.x series. It defines the frozen contracts that must remain stable across all v1.y releases, the boundaries that separate evolution from breaking change, and the governance principles that guide future development.

---

## 1. Release baseline

**Version**: v1.3.0 (architecture baseline through M8)  
**Release purpose**: First production-ready release at v1.0.0 (M5); M6–M8 add managed downloads, admin navigation, and country simulation without breaking the v1.0 public API.  
**Architectural intent**: A stable, backwards-compatible foundation for geographic resolution, enabling v1.x to evolve features without requiring major-version bumps.

The plugin establishes and ships eight completed milestones (M1–M8):

- **M1 (v0.1.0)**: Core domain models, public API, immutable context, ContextResolver, dependency injection, four guard tests.
- **M2 (v0.2.0)**: Client IP trust boundaries (Cloudflare, proxy headers), CloudflareHeaderProvider, WooCommerceProvider, admin UI, Settings, DiagnosticsService, Site Health.
- **M3 (v0.3.0)**: Privacy floor (PrivacyGuardTest), MaxMindProvider (local `.mmdb` lookups), maxmind_db_path setting, ProviderHealthStore.
- **M4 (v0.4.0)**: ReferenceRemoteProvider (MaxMind GeoLite2 remote Web Service, disabled by default), CircuitBreaker, HttpTransport seam, remote diagnostics.
- **M5 (v1.0.0)**: Operational readiness (WP-CLI, debug_information, translation readiness, Privacy Policy integration, version parity enforcement, release tooling).
- **M6 (v1.1.0)**: Managed GeoLite2 Country database downloads; shared MaxMind credentials; `maxmind-db/reader` promoted to production dependency.
- **M7 (v1.2.0)**: Admin navigation restructuring — top-level menu, six focused pages, shared report renderer, legacy URL redirect (removed M8).
- **M8 (v1.3.0)**: Country simulation framework — post-resolution `universal_geo_context` filter, signed session cookie, administrator-only authorization; no provider, cache, or public API shape change.

Each milestone shipped in isolation; no unreviewed change rides along.

---

## 2. Core principles

The v1.0.0 architecture rests on seven non-negotiable principles, established in CLAUDE.md and enforced throughout M1–M5:

### 2.1 Deterministic behaviour

- **No randomness in resolution.** The same visitor IP, trust configuration, provider set, and cache state always produce the same result.
- **Providers are stateless.** A provider's `resolve()` depends only on its dependencies and the input IP; no provider carries request state across calls.
- **Resolution order is explicit.** ContextResolver iterates `PROVIDER_ORDER` exactly once, in the order injected. No provider "vetoes" or modifies another's result.

### 2.2 Immutable context objects

- **VisitorContext is final.** Its five properties (`country_code`, `region_code`, `source`, `confidence`, `is_cached`) are `public readonly`.
- **No side effects from reading.** Calling `universal_geo_get_context()` never mutates state, never triggers I/O, never spawns new resolution passes.
- **GeoCandidate is equally immutable.** Providers return facts only; validation and normalization happen outside the provider, in ContextResolver.

### 2.3 Dependency injection

- **Plugin is the sole composition root.** No internal service instantiates a peer; every dependency arrives by constructor injection from `Plugin::build_graph()`.
- **No service locators.** The ServiceContainer anti-pattern is forbidden; all dependencies flow through constructor parameters.
- **No hidden WordPress globals.** No provider, validator, cache, or resolver calls `get_option()`, `apply_filters()`, or `wp_remote_get()` directly — dependencies make all access explicit.

### 2.4 Composition root ownership

- **Plugin.php constructs the graph once per request.** Once `init()` completes, the resolver, cache, providers, and admin services are fixed for that request.
- **Request-scoped lifetime.** Every service lives for the duration of one request; no service persists across requests except via options/cache.
- **No modification of the graph at runtime.** The provider array is filtered at graph-build time via `universal_geo_providers` hook; once built, it never changes.

### 2.5 Separation of providers from orchestration

- **Providers are simple.** A provider receives an IP; it returns a GeoCandidate or throws; it never sees a VisitorContext.
- **ContextResolver orchestrates.** It owns ordering, caching, confidence assignment, validation, and normalization — none of which touch a provider directly.
- **Operational services are orthogonal to providers.** Managed database downloads (M6+), remote health monitoring, and cache management are services in the `UniversalGeo\*` namespace, not providers.

### 2.6 Explicit provider ordering

- **Order determines resolution.** ContextResolver iterates providers in the exact order given; the first provider to return a result wins (memoized thereafter).
- **Confidence reflects position.** The confidence table in ContextResolver lists a confidence value per provider id; no provider picks its own confidence.
- **Ordering is customizable.** The `universal_geo_providers` filter allows reordering, removing, or adding providers at graph-build time; the resolver has no opinion on the final order.

### 2.7 Privacy-first design

- **Client IPs are transient locals.** No raw IP is persisted, printed in logs, or passed across a hook boundary.
- **Diagnostics redact.** IP masking (e.g., `8.8.8.x`), provider IDs (not credential details), and generic HTTP status codes replace raw data in every diagnostic output.
- **Cache is keyed by config, never IP.** The derived-context cache's epoch is driven by Settings/provider availability changes, not client identity.
- **Credentials never leak.** Only booleans (`'configured'` / `'not configured'`), not values, appear in diagnostics; constants and settings are never echoed into WordPress output.

---

## 3. Public API freeze

**API version**: 1 (immutable; returned by `universal_geo_api_version()`)

The six public functions and one value object below comprise the entire external contract. All other code (`@internal`) is subject to change without notice.

### 3.1 Public surface

```php
/**
 * Resolve and return the current visitor's geographic context.
 *
 * @return UniversalGeo\Model\VisitorContext Always returns an object; never throws.
 */
function universal_geo_get_context(): UniversalGeo\Model\VisitorContext

/**
 * Convenience shorthand: the country code from the context.
 *
 * @return string|null  Two-letter ISO 3166-1 code, or null if unknown.
 */
function universal_geo_get_country_code(): ?string

/**
 * Convenience shorthand: the region code from the context.
 *
 * @return string|null  Subdivision-only code (e.g. 'CA', never the compound
 *                       'US-CA' form; M13, GeoValidator::region()), when the
 *                       winning provider supplied one — else null.
 */
function universal_geo_get_region_code(): ?string

/**
 * Convenience shorthand: the source (provider ID or 'unknown') that produced the context.
 *
 * @return string  'cloudflare', 'maxmind', 'woocommerce', 'remote', 'default', 'simulation', or 'unknown'.
 */
function universal_geo_get_source(): string

/**
 * Convenience shorthand: the confidence in the country determination, 0.0 to 1.0.
 *
 * @return float  Confidence assigned by ContextResolver based on provider.
 */
function universal_geo_get_confidence(): float

/**
 * Public API version.
 *
 * @return int  1 (immutable).
 */
function universal_geo_api_version(): int

/**
 * Immutable resolved visitor geographic context.
 *
 * Public type (non-@internal). Returned by universal_geo_get_context().
 * Properties: country_code (?string), region_code (?string), source (string),
 * confidence (float), is_cached (bool).
 */
class UniversalGeo\Model\VisitorContext
```

### 3.2 Stability rules

1. **Guarded calls**: Every call must be wrapped in `function_exists( 'universal_geo_get_context' )`.
2. **Timing**: Call at or after `plugins_loaded` priority 20 (the plugin boots at 10).
3. **Stable types**: Only `VisitorContext`, `GeoCandidate`, and the two interfaces (`ClientIpResolverInterface`, `GeoProviderInterface`) from `Contracts/` are stable.
4. **Backwards compatibility**: Adding new properties to `VisitorContext` is a minor-version change; removing or renaming is a major-version change. Adding new functions is a minor-version change; removing is major.
5. **Confidence never decreases**: A provider's confidence value in `ContextResolver::CONFIDENCE` will never decrease across a minor-version bump (although new providers may be added and existing confidence may increase).

---

## 4. VisitorContext freeze

**Class**: `UniversalGeo\Model\VisitorContext`  
**Mutability**: `final`; all properties are `public readonly`

### 4.1 Responsibilities

- Store the resolved geographic context for a single visitor.
- Provide a stable shape for the public API boundary.
- Enable safe serialization/deserialization (via `to_array()` / `from_array()`, SCHEMA_VERSION 1).

### 4.2 Immutability contract

- Construction validates structural shape only (uppercase country, trim source, confidence bounds [0.0, 1.0]).
- No validation of ISO 3166-1 membership happens inside VisitorContext; that is GeoValidator's job (called by ContextResolver, not inside the model).
- `with_cached(bool $is_cached)` returns a new instance (copy-on-write pattern).
- `unknown()` static factory builds the canonical unknown state (`country_code = null`, `source = 'unknown'`, `confidence = 0.0`).

### 4.3 Ownership

- ContextResolver constructs VisitorContext and assigns source + confidence.
- Plugin fires hooks at the VisitorContext boundary (filter `universal_geo_context`, action `universal_geo_context_resolved`).
- Providers never see VisitorContext; they work exclusively with GeoCandidate.

### 4.4 Compatibility rules

- **Properties are immutable**: The five properties will never be removed or renamed in a minor-version release.
- **SCHEMA_VERSION is stable**: The serialization contract (`to_array()` / `from_array()`) will not break; schema evolution uses version bumps.
- **Unknown state is canonical**: `VisitorContext::unknown()` is the sole canonical representation of "unknown country"; consumers must treat `country_code === null` as equivalent to `unknown()`.

---

## 5. ContextResolver freeze

**Class**: `UniversalGeo\Resolver\ContextResolver`  
**Mutability**: `final`; immutable after construction

### 5.1 Responsibilities

- Execute the provider chain in order, stopping at the first successful result.
- Manage request-level memoization (one resolution per request, multiple reads).
- Assign source and confidence centrally; no provider carries either.
- Validate and normalize candidates via static `GeoValidator` methods.
- Interact with the cache to store and retrieve derived contexts.

### 5.2 Orchestration guarantees

- **Deterministic order**: Iterates the injected provider array exactly once, in order, with no backtracking or vetoing.
- **Memoization**: The first call to `resolve()` or `probe()` within a request computes the result; subsequent calls return the memoized value.
- **Cache transparency**: Cache hits count as memoized results and fire hooks identically to fresh resolutions.
- **Provider isolation**: Providers receive only an IP; they never see the resolver's state, the cache, other providers, or WordPress settings.
- **Exception tolerance**: A provider's exception never propagates to the caller; ContextResolver catches it, reports failure (via the optional `$on_provider_failed` callback), and continues to the next provider.

### 5.3 Provider interaction

- **Constructor validates all providers**: Every element must implement `GeoProviderInterface`; an invalid provider raises `InvalidArgumentException`.
- **Provider::is_available() is checked at graph build**: Unavailable providers participate anyway (for re-enablement at runtime), but they short-circuit cleanly inside `resolve()`.
- **Provider::resolve() is called exactly once per provider**: The resolver iterates sequentially; once a provider returns non-null (a GeoCandidate) or throws, no subsequent provider is consulted.
- **GeoCandidate carries facts only**: Country code and region code, both nullable, both unvalidated (a provider may return malformed data; validation happens after the provider returns).

### 5.4 No business logic leakage

- ContextResolver contains zero business rules about geography, policy, or admin configuration.
- ContextResolver calls no WordPress function; it is framework-independent.
- ContextResolver does not read `get_option()`, does not call filters or actions, does not perform I/O, and does not construct any new service.

### 5.5 Confidence assignment

The confidence table is frozen:

| Provider ID | Confidence |
|---|---|
| cloudflare | 0.95 |
| maxmind | 0.90 |
| woocommerce | 0.85 |
| remote | 0.85 |
| default | 0.10 |
| unknown | 0.00 |
| (unlisted) | 0.85 |

Any provider registered via the `universal_geo_providers` filter that lacks an entry in this table receives the "unlisted" confidence (0.85). Confidence will not decrease in minor-version releases; new entries or increases are allowed.

---

## 6. Provider architecture

**Frozen interface**: `UniversalGeo\Contracts\GeoProviderInterface`

### 6.1 Provider model

```php
interface GeoProviderInterface {
    /**
     * @return string  The stable provider ID ('cloudflare', 'maxmind', etc.).
     */
    public function get_id(): string;

    /**
     * @return bool  Whether this provider is currently available (not a live test; a readiness gate).
     */
    public function is_available(): bool;

    /**
     * @param string $ip  An already-normalized, already-validated public IP.
     *
     * @return GeoCandidate|null  Geographic facts, or null if no data for this IP.
     * @throws Throwable  If the lookup fails; ContextResolver catches and continues.
     */
    public function resolve(string $ip): ?GeoCandidate;
}
```

### 6.2 Provider independence

- **Providers are stateless.** Each provider receives a clean IP and dependency injections; it carries no request context or shared state between calls.
- **Providers may throw.** An exception is a signal of genuine failure (network error, malformed data, permission denied); it does not stop the resolver loop, but it does fire the `universal_geo_provider_failed` action.
- **No provider orchestration.** One provider cannot peek at or veto another's result; ContextResolver is the sole orchestrator.

### 6.3 Built-in providers (ordered by default confidence)

1. **CloudflareHeaderProvider** (0.95) — Reads the `CF-IPCountry` header when the connecting IP is in Cloudflare's ranges (DNS-proxied).
2. **MaxMindProvider** (0.90) — Opens a local `.mmdb` file and queries it via the MaxMind Reader class (soft dependency).
3. **WooCommerceProvider** (0.85) — Delegates to WooCommerce's own MaxMind integration, if active.
4. **ReferenceRemoteProvider** (0.85) — Queries MaxMind's GeoLite2 Country Web Service (disabled by default, requires explicit opt-in and credentials).
5. **DefaultCountryProvider** (0.10) — Returns a configured fallback country code.
6. **Unknown resolution** (0.00) — If all providers fail or are unavailable, ContextResolver returns `VisitorContext::unknown()`.

### 6.4 Operational services ≠ providers

Services such as managed GeoLite2 database downloads (v1.1+) are **not** providers. They live in the `UniversalGeo\MaxMind\` namespace (or similar) and operate orthogonally:

- They do not implement `GeoProviderInterface`.
- They do not feed into ContextResolver's iteration.
- They are orchestrated by Plugin or admin/CLI surfaces, not by the resolver loop.
- They may have side effects (downloading, writing to disk, updating options) that providers never have.

### 6.5 Cache interaction

- **Caching is ContextResolver's job.** Providers themselves never touch the cache.
- **Cache key includes configuration.** The epoch is driven by settings/provider-availability changes, not by geography.
- **Cache transparency**: A cache hit is indistinguishable from a fresh resolution to callers and hooks; both fire the same hooks with the same result.

### 6.6 Health reporting

- **ProviderHealthStore** (M3) tracks recent provider failures.
- **CircuitBreaker** (M4) manages the remote provider's failure-driven availability gate (3 failures → 5-minute break).
- Health events are recorded independently of the resolution path; a provider's failure triggers both a `universal_geo_provider_failed` action and a health store update.

---

## 7. Composition root

**File**: `src/Plugin.php`  
**Invariant**: Sole owner of the object graph; no other service instantiates internal peers.

### 7.1 Lifecycle

- **One instance per request** (singleton pattern): `Plugin::instance()` returns the same instance for the lifetime of one request.
- **Constructed at plugin load.** The main bootstrap file (`universal-geo-context.php`) calls `Plugin::instance()->init()` at `plugins_loaded` priority 10.
- **Idempotent**: `init()` can be called multiple times safely; services are constructed at most once.

### 7.2 Service construction sequence

1. `ServerRequest` — Captures `$_SERVER` snapshot; immutable thereafter.
2. `TrustedProxies` — Reads Settings; builds the trust boundary.
3. `ClientIpResolver` — Resolves the request's client IP against the trust boundary.
4. Provider instances — Each receives its dependencies.
5. `GeoCache` — Receives settings (TTL, enable flag).
6. `ContextResolver` — Receives the provider array in order, the cache, and an optional failure callback.
7. **On admin requests only** (via `is_admin()` guard):
   - `DiagnosticsService` — Receives resolver and diagnostics config.
   - `AdminScreen` — Receives diagnostics and request.
8. **On WP-CLI requests only** (via `should_register_cli()` guard):
   - `Cli\Command` — Receives resolver and diagnostics, via the same instances as admin path (only one request-scoped DiagnosticsService).
9. **On all requests** (unconditionally):
   - `UpdateScheduler` (M6+) — Registers cron hooks for managed database updates.
   - `PrivacyPolicyContent` — Registers privacy policy text (admin-only, via `is_admin()` guard).
   - **Simulation (M8+)** — `SimulationCookie`, `SimulationState`, `SimulationAuthorization`, `SimulationContextFilter`, and `SimulationAdminBar` are constructed and registered on every request. Activation is conditional; registration is not.
10. **On admin requests only** (M8+, via `should_register_admin()`):
   - `DetectionPage` (Simulation tab), `SimulationController` (nonce-protected POST handlers).

**Invariant (M8+):** `SimulationContextFilter` must never be registered only from `wp-admin`. Front-end requests from authorized administrators must receive simulated context.

### 7.3 Composition-root invariant

The following are enforced by the guard test `CompositionRootTest`:

- `Plugin` is the sole location where internal services are instantiated.
- No `@internal` class references another `@internal` class's constructor directly; all dependencies arrive via DI.
- No `@internal` class calls `new MyInternalService()` except in tests.
- A centralized `CONSTRUCTOR_CONFINED_CLASSES` list in the guard test specifies which classes are known to be safe to construct inline; any other class is flagged.

---

## 8. Settings architecture

**Option name**: `universal_geo_settings`  
**Schema version**: 5 (locked; M6 bumped it from v4 for managed GeoLite2 downloads — this
line previously read "4", a pre-existing documentation drift never corrected
in M6–M12; fixed here in M13 as an unrelated, incidental correction, not a
schema change of its own. M13 does not bump the schema; `Settings::SCHEMA_VERSION`
in code stays 5, per ADR-0010's NO-GO decision on managed GeoLite2 City
support.)

### 8.1 Ownership

- Settings are owned by `Settings` class (`src/Settings.php`).
- Admin interface is `AdminScreen` (`src/Admin/AdminScreen.php`).
- Merging submitted data against previous state is AdminScreen's job; `Settings::sanitize()` is a pure function.

### 8.2 Sanitization philosophy

- **Purity**: `Settings::sanitize()` reads an input array and returns sanitized output; it performs no I/O, no option reads, no side effects.
- **Forgiving**: Malformed input falls back to safe defaults, never throws.
- **Validation at save time**: Filesystem checks (is the maxmind_db_path readable?) happen in `AdminScreen::handle_save_settings()`, not in sanitize().

### 8.3 Backwards compatibility

- **Settings migration** (v3 → v4, M4): Legacy `remote_account_id` and `remote_license_key` keys are preserved as fallback sources; new canonical keys exist, and migration happens on the first save.
- **Compatibility period**: Legacy keys remain supported through at least v1.2.0; removal requires a major-version bump or explicit migration notice.
- **No silent data loss**: If both old and new keys are present, the new key takes precedence; the old key is not automatically deleted until the next save.

### 8.4 Option stability

- Settings option name is permanent: `universal_geo_settings`.
- No setting will be removed in a minor-version release (only deprecated, with fallbacks).
- No setting's meaning will change; if new behavior requires a setting to evolve, a new setting is added and the old one is deprecated.

---

## 9. Hook surface

**Status**: Seven public hooks, complete as of v1.0.0. No additional hooks are planned for v1.x; hook additions would be coordinated via a review process similar to architectural changes.

### 9.1 Frozen hooks

| Hook | Type | Fires | Purpose |
|---|---|---|---|
| `universal_geo_context` | Filter | Once per request, at Plugin::context() | Modify the final VisitorContext before consumers see it |
| `universal_geo_context_resolved` | Action | Immediately after the filter | React to resolution (read-only) |
| `universal_geo_providers` | Filter | At graph build, plugins_loaded 10 | Reorder/add/remove providers |
| `universal_geo_default_country` | Filter | At graph build, plugins_loaded 10 | Override fallback country |
| `universal_geo_trusted_proxies` | Filter | At first trust-gate eval, lazily | Extend trusted-proxy CIDR set (additive only) |
| `universal_geo_provider_failed` | Action | On provider exception | React to failures |
| `universal_geo_maxmind_db_path` | Filter | At graph build, plugins_loaded 10 | Override database path (code-level only) |

### 9.2 Hook semantics

- **No raw IP crosses a hook.** All hook arguments are `VisitorContext`, provider ID strings, country codes, or CIDR strings — never a raw client IP.
- **Hooks validate filter returns.** Invalid return values (wrong type, malformed data) are discarded with `_doing_it_wrong()` and the pre-filter value is kept.
- **Additive hooks only.** `universal_geo_trusted_proxies` cannot shrink the trust set; it can only extend it.

---

## 10. Cache architecture

**Class**: `UniversalGeo\Cache\GeoCache`

### 10.1 Ownership

- Cache lifecycle is owned by ContextResolver.
- Cache is request-scoped (one instance per request).
- Cache is memoized by configuration, not by visitor IP.

### 10.2 Invalidation

- **Cache epoch** (`universal_geo_cache_epoch` option): A monotonically-increasing counter incremented by `GeoCache::bump_epoch()` whenever Settings or provider availability changes.
- **Cache TTL** (configurable, default 900 seconds): After expiry, a fresh resolution is computed.
- **No IP-based eviction**: The cache makes no distinction between visitors; a given configuration state has one cached result per TTL period.

### 10.3 Determinism

- Same IP + same configuration + cache hit = same result (byte-for-byte identical VisitorContext).
- Hooks fire identically on cache hits and misses.
- Cache is transparent to callers; only the `is_cached` property distinguishes a cached result from a fresh one.

---

## 11. Diagnostics and visibility

### 11.1 Site Health integration

- **Test**: `universal_geo_maxmind` (M3) — Warns if the configured database is missing or stale (30/90 day thresholds).
- **Test**: `universal_geo_remote_provider` (M4) — Warns if the remote provider is enabled but not available, or if recent failures have tripped the circuit breaker.
- **Test**: `universal_geo_maxmind_managed` (M6+, new in v1.1) — Warns if the managed database is stale or missing (14/30 day thresholds, distinct from custom-path thresholds). M12 additionally recommends when auto-update is enabled without credentials or without a registered scheduler.
- **Test**: `universal_geo_trusted_proxy` (M2) — Critical when forwarding headers are present with a private peer and an empty trusted set.
- **Test**: `universal_geo_provider_chain` (M12) — Critical when the chain is empty; recommended when only the default fallback is available.
- **Test**: `universal_geo_cache` (M12) — Flags genuine UGC cache configuration problems only; never treats missing Redis/Memcached as a problem; never critical.
- **Debug info**: `debug_information` filter (M5) — DiagnosticsService reports full diagnostics data for the Site Health Info screen.

### 11.2 WP-CLI commands

- `wp universal-geo context [--ip=<ip>] [--format=<json|yaml>]` — Resolve a test IP or the current request.
- `wp universal-geo diagnostics [--format=<json|yaml>]` — Report full diagnostics.
- `wp universal-geo cache flush` — Clear the derived-context cache.
- Additional commands (M6+): `wp universal-geo database status|download|validate|remove|restore`.
- Additional commands (M12): `wp universal-geo status`, `wp universal-geo providers`, `wp universal-geo trusted-proxies [--test=<ip>]`.

### 11.3 Redaction principles

- **No credentials**: Only `'configured'` / `'not configured'`, never the actual account ID or license key.
- **Masked IPs**: Diagnostic output shows `8.8.8.x`, never `8.8.8.8`.
- **Generic status codes**: `'HTTP 401'`, not the raw response body or Authorization header.
- **No presigned URLs**: Redirect targets are redacted to host only (e.g., `r2.cloudflarestorage.com`), never the full signed URL.
- **No update-lock tokens** (M12): lock diagnostics expose locked/owner/timestamps only.
- **Trusted-proxy `--test`** (M12): reports matched + matching CIDR without echoing the tested IP.

---

## 12. Security architecture

### 12.1 Core principles

- **Least privilege**: The plugin reads only what it needs; no blanket `get_option()` or filter of all providers.
- **Credentials are immutable in scope**: Once resolved at graph build, credentials never leak into options, logs, or exceptions.
- **Validation at boundaries**: Settings are sanitized; options are read only inside `Plugin::build_graph()`.
- **No hidden outbound requests**: The sole HTTP call site is `WordPressHttpTransport.php` (M4), guarded by the `PrivacyGuardTest`.
- **Explicit admin actions**: Settings changes, credential storage, and destructive operations (cache flush, database remove) require a nonce and `manage_options` capability.

### 12.2 Trust boundaries

- **Client IP trust** (M2): The TrustedProxies model decides which headers to consult; the default is REMOTE_ADDR only.
- **Cloudflare preset**: Automatic if the connecting IP is in Cloudflare's published ranges and the Cloudflare header is present.
- **Remote provider** (M4): Disabled by default; requires an explicit transfer acknowledgement checkbox.
- **Managed database** (M6+): Requires credential entry and an explicit opt-in toggle.

### 12.3 Redirect safety (M6)

`MaxMind\DatabaseManager` implements a redirect-safe two-request download
flow for managed GeoLite2 downloads (confirmed against a live MaxMind
account during M6J acceptance):

1. First request to `download.maxmind.com` includes the HTTP Basic Auth header, with redirect-following disabled — it only detects a 3xx response, never follows it.
2. `RedirectValidator` validates the `Location` header strictly (https-only, no userinfo, allowlisted hosts — `r2.cloudflarestorage.com`).
3. Second request to the validated target includes **no** Authorization header, and itself disables redirect-following — no third hop is ever attempted.
4. The downloaded archive's SHA-256 digest is verified against MaxMind's `.sha256` sidecar, fetched via the identical two-hop flow, before extraction.

This prevents credentials from traveling to untrusted hosts after a
redirect, and prevents a corrupted-but-structurally-plausible archive from
being installed. `ReferenceRemoteProvider` (M4) does not download files
and uses this pattern's redirect-disabling half only (`redirection => 0`
on its single request) — it never runs a second hop, since it has no
archive to fetch.

---

## 13. Privacy architecture

### 13.1 Data boundaries

- **No visitor data is stored.** VisitorContext (country, region, source, confidence) is computed per-request, never persisted by this plugin.
- **No IP address is stored.** Request-scoped IpUtils functions normalize and classify, but the raw or masked IP is never written to WordPress options, logs, or metadata.
- **Transient locals only.** IPs live in variables inside method scopes; they are garbage-collected after resolution.

### 13.2 Remote provider disclosure (M4+)

When the remote provider is enabled:

- A `universal_geo_privacy_remote_transfer` checkbox must be checked; the act of checking is the explicit acknowledgement.
- Privacy Policy text (via `wp_add_privacy_policy_content()`, M5) discloses the transfer of IP to MaxMind.
- Credentials are never round-tripped via form fields; `type="password"` is used, never retained in the response.

### 13.4 Simulation cookie (M8+)

The simulation cookie is **not** visitor geo data and is **not** subject to the "no visitor data stored" rule in §13.1 — it is an administrator testing affordance:

- **Name**: `universal_geo_sim`
- **Scope**: Session cookie; cleared when the browser session ends or the administrator stops simulation.
- **Payload**: `{version}.{country}.{hmac}` — country code only; no IP, provider name, credentials, or real resolved country.
- **Attributes**: `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`; path/domain match WordPress `COOKIEPATH` / `COOKIE_DOMAIN`.
- **Authorization**: Cookie alone never authorizes simulation; `manage_options` and a logged-in session are re-checked on every request.
- **No database persistence**: Simulation state lives in the cookie only; no wp_options, transients, or user meta.

See `docs/PRIVACY.md` §Simulation cookie and ADR-0008.

---

## 14. Versioning policy

### 14.1 Semantic versioning (SemVer)

**MAJOR.MINOR.PATCH** (e.g., `1.0.0`, `1.1.0`, `1.0.1`):

- **MAJOR** — Breaking changes to the public API, frozen interfaces, or composition-root invariants. Rare; requires Product Owner review and a migration path in release notes.
- **MINOR** — New features, new providers, new diagnostics, new hooks, new WP-CLI commands, new admin functionality, or new Site Health tests — provided they are backwards-compatible and do not break the frozen contracts listed below.
- **PATCH** — Bug fixes, documentation, security fixes, and performance improvements that do not change observable behaviour.

### 14.2 v1.x stability guarantees

A v1.y release (y ≥ 0) will never:

- Remove a public function or change its signature.
- Remove or rename a property of VisitorContext.
- Change the meaning of a confidence value (including the simulated `1.0` override semantics).
- Change simulated `VisitorContext` field semantics (`source = simulation`, `region_code = null`, `is_cached = false`) without an ADR.
- Remove a provider from the default chain (may deprecate with fallback).
- Break ContextResolver's determinism or ordering guarantee.
- Remove a hook or change its semantics.
- Require new dependencies that were optional in v1.0.

### 14.3 Deprecation path

If a feature is deprecated (e.g., a legacy settings key, a provider, or a hook), the deprecation spans at least one minor-version release with:

- Explicit notice in the release notes.
- The old code/path continues to function.
- A `_deprecated_*()` notice fires (if applicable).
- Migration path documented.

Only after a full minor-version release does removal happen in the next minor or major version.

---

## 15. What may evolve (v1.x)

The following changes are **not** breaking and are fully compatible with v1.0.0 clients:

- **New providers**: Adding a fifth or sixth provider to the chain, with or without new fields on VisitorContext if the new provider is v1.1+ only. Existing providers are not reordered.
- **New confidence values**: A provider's confidence may increase (e.g., from 0.85 to 0.90) in a minor release without breaking; clients read only the value, not the source.
- **Managed database downloads** (M6): A new operational service in `UniversalGeo\MaxMind\` that enables automatic GeoLite2 updates. Orthogonal to the provider model.
- **Additional diagnostics**: New Site Health tests, new WP-CLI commands, new admin screens — all optional, with graceful degradation if unavailable.
- **New hooks**: Adding a new hook (e.g., `universal_geo_database_updated`) does not break existing code; consumers simply register the hook when ready.
- **Admin UI improvements**: New settings, re-layout of existing settings, new admin notices — backwards-compatible if the underlying data schema is compatible.
- **Cache improvements**: Changing cache eviction strategy, TTL defaults, or memoization details is an internal optimization if it preserves determinism.
- **Region support** (M6+): Adding `region_code` support via GeoLite2-City without removing the Country provider.
- **Translation additions**: More strings translated, more languages supported — always backwards-compatible.

---

## 16. What is frozen (checklist)

These items will never break across v1.x releases:

- ✓ **VisitorContext** — Five properties, final class, immutable.
- ✓ **ContextResolver contract** — Deterministic ordering, one-pass iteration, confidence table.
- ✓ **Provider interfaces** — `GeoProviderInterface`, `ClientIpResolverInterface`.
- ✓ **Public API** — Six functions + VisitorContext.
- ✓ **API version** — Always `1`.
- ✓ **Hook semantics** — Seven hooks, signatures unchanged.
- ✓ **Composition root** — Plugin.php, DI invariant.
- ✓ **Settings backwards compatibility** — Schema evolves; old keys migrate or remain supported.
- ✓ **Dependency injection** — No service locators; all dependencies are constructor-injected.
- ✓ **Privacy boundaries** — No IP persisted; no credentials leaked to diagnostics.
- ✓ **Cache philosophy** — Configuration-keyed, request-scoped, deterministic.
- ✓ **Diagnostics redaction** — Credentials masked, IPs masked, URLs redacted.
- ✓ **Simulation architecture (M8+)** — Post-resolution filter only; never a provider; never touches GeoCache or provider-health stores.
- ✓ **Simulation VisitorContext semantics** — `source = simulation`, `confidence = 1.0`, `region_code = null`, `is_cached = false` when active.
- ✓ **Simulation authorization** — Administrator-only; capability re-checked every request; cookie alone insufficient; fail-closed.
- ✓ **Simulation cookie model** — Signed, session-scoped, HttpOnly, Secure on HTTPS, SameSite=Lax; no IP or credentials.
- ✓ **Simulation composition root** — `SimulationContextFilter` registered unconditionally on every request; activation conditional.
- ✓ **Public API unchanged by simulation** — Same six functions and VisitorContext shape; simulation affects only the returned value.

---

## 17. Governance

### 17.1 Design review for architectural change

Any proposal that touches the frozen contracts above (sections 3–14) requires explicit Product Owner approval and an ADR or amendment before implementation.

Examples of changes requiring review:

- Removing or renaming a public function or hook.
- Adding a required parameter to a public function.
- Changing VisitorContext's property count or names.
- Altering ContextResolver's ordering guarantee.
- Adding a new composition-root service without DI.
- Relaxing the privacy boundary (e.g., storing IPs).

### 17.2 Code review checklist for v1.x PRs

Before merging a v1.x PR, confirm:

1. No frozen contract (sections 3–14) has been broken.
2. Tests pass: `composer phpcs`, `composer test:unit`, `composer test:integration`, `composer validate --strict`.
3. Guard tests pass (CompositionRootTest, PrivacyGuardTest, ImmutabilityGuardTest, NoPolicyGuardTest).
4. Version parity: plugin header, `UNIVERSAL_GEO_VERSION`, `docs/COMPATIBILITY.md`, `readme.txt` are all in sync.
5. New code is properly namespaced (`UniversalGeo\`).
6. No secrets, hardcoded credentials, or deployment-specific branding.
7. Release audit passes: `bin/release-audit.sh`.

### 17.3 Milestone discipline

- **One approved milestone at a time** (CLAUDE.md house rule).
- Before a milestone branch is created, the **previous milestone's tag is pushed and verified on origin**.
- Milestone branches are created from the released tag, not from `main`, to avoid riding unreviewed changes.
- Merges back to `main` happen only after milestone review and approval.

---

## 18. Documentation responsibility

The following documents are authoritative and must remain in sync:

| Document | Covers |
|---|---|
| `docs/ARCHITECTURE.md` | Historical M1–M5 narrative and ongoing technical decisions. |
| `docs/API.md` | Public surface, stability rules, consumer examples. |
| `docs/HOOKS.md` | Hook signatures, semantics, and firing guarantees. |
| `docs/PRIVACY.md` | Privacy model, data boundaries, disclosure. |
| `docs/SECURITY.md` | Security principles, credential handling, trust boundaries. |
| `docs/ARCHITECTURE_FREEZE.md` | **This document.** Frozen contracts for v1.x. |
| `docs/ROADMAP.md` | Milestones shipped, explicitly deferred items, future directions. |
| `docs/COMPATIBILITY.md` | Version matrix, minimum versions, known compatibility notes. |
| `docs/adr/*` | Architectural decisions (M2–M8). |
| `CLAUDE.md` | Code rules, workflow, core invariants. |

Amendments to frozen contracts require updating this document and other affected docs in the same commit.

---

## 19. Verification

This architecture freeze is verified by:

1. **Guard tests**: Four capped mutation-verified unit tests enforce composition, immutability, privacy, and policy boundaries.
2. **Version parity**: Automated test ensures plugin header, version constant, and docs all agree.
3. **Release audit**: Binary check that the built plugin contains no test suite, CI files, credentials, or development dependencies.
4. **Code review**: Every PR before merge is reviewed against the checklist in section 17.2.

---

## 20. Future reference

Contributors to v1.4.0 and beyond should:

1. Read this document before proposing architectural changes.
2. Update this document if the proposal touches frozen contracts.
3. Reference this document in release notes if a boundary shift occurs (even an allowed evolution).
4. Use this document as a guide when deciding if a feature is a minor-version addition or a major-version change.
5. Read §21 (Simulation framework) and §22 (Contributor prohibitions) before extending M8 or building M9+ features that interact with resolution.

The v1.x series is a stable, backwards-compatible platform. Stability enables confidence; confidence enables adoption. Preserve these guarantees.

---

## 21. Simulation framework (M8 / v1.3.0)

**Status: Frozen for v1.x.** Country simulation is an administrator testing affordance introduced in M8. It does not change the public API shape, provider contracts, or cache write semantics.

### 21.1 Architectural placement

Simulation is implemented as a **post-resolution context transformation**, not as a geo provider:

```
Client Request
    ↓
IP Resolution (ClientIpResolver)
    ↓
Providers (ContextResolver chain)
    ↓
GeoCache (read-only for this path)
    ↓
ContextResolver::resolve()
    ↓
universal_geo_context filter
    ↓ SimulationContextFilter @ priority 100 (optional, when authorized + active)
VisitorContext (final, per request)
    ↓
Downstream plugins / consumers
```

**Ordering invariant:**

1. After `ContextResolver` has produced a real resolution (cache hit or miss).
2. After any GeoCache lookup has completed — simulation never participates in cache keying or cache population.
3. Before downstream consumers receive `VisitorContext` via `Plugin::context()` or the public API wrappers.

**Introducing a `SimulationProvider` (or registering simulation inside the provider chain) would violate this architecture.** Providers produce evidence from request signals; simulation is an explicit administrator override of the final context. Mixing the two would break cache isolation, provider-health semantics, and the evidence-not-policy principle.

### 21.2 Cache contract

Simulation **never**:

- writes to `GeoCache`;
- modifies cached `VisitorContext` instances in place;
- invalidates cache or bumps the cache epoch;
- alters provider-health records;
- changes provider ordering or availability.

Real resolution continues to run on every request; simulation replaces only the **returned** context at filter time. Cache entries for the real resolution remain intact and are reused on subsequent requests exactly as before M8.

Cache isolation is part of the frozen v1.x contract. Any proposal to cache simulated contexts requires an ADR and is likely a major-version architectural change.

### 21.3 VisitorContext semantics when simulated

When simulation is active and authorized, `SimulationContextFilter` returns a **new** immutable `VisitorContext` (never mutates the resolver output):

| Field | Value | Rationale |
|---|---|---|
| `country_code` | Administrator-selected ISO 3166-1 alpha-2 | The purpose of simulation |
| `source` | `'simulation'` | Explicitly distinguishes override from real evidence; downstream plugins can detect and handle accordingly |
| `confidence` | `1.0` | Administrator explicitly chose the country; no probabilistic uncertainty |
| `region_code` | `null` | M8 simulates country only; region is out of scope and must not be inferred |
| `is_cached` | `false` | Simulated context is never a cache artifact; avoids conflating test state with production cache behaviour |

**Future changes to these semantics require an ADR** and Product Owner approval per §17.

### 21.4 Authorization model

Frozen rules:

- **Authenticated administrator only** — `is_user_logged_in()` and `current_user_can( 'manage_options' )`.
- **Capability checked on every request** — `SimulationState::active_country()` re-validates authorization; a stale cookie after logout or capability loss is ignored.
- **Cookie alone never authorizes simulation** — a copied or forged cookie without a live authorized session has no effect.
- **Fail-closed** — invalid signature, malformed payload, unknown country code, or failed authorization → simulation inactive; real resolved context is returned unchanged.

State changes (start, change country, stop) require nonce-protected POST via `admin_post_universal_geo_simulation_*` handlers in `SimulationController`. No public REST or AJAX control surface.

### 21.5 Cookie model

| Property | Value |
|---|---|
| Name | `universal_geo_sim` |
| Format | `{version}.{country}.{hmac32}` signed with `wp_salt( 'auth' )` |
| Lifetime | Session cookie (no `Expires`/`Max-Age`; cleared on browser close or explicit stop) |
| HttpOnly | Yes |
| Secure | Yes when `is_ssl()` |
| SameSite | `Lax` |
| Contents | Simulated country code only |
| Excluded | IP address, provider information, credentials, real resolved country |
| Persistence | Cookie only — no database, transients, or user meta |

Multisite: per-site via WordPress `COOKIEPATH` / `COOKIE_DOMAIN`; subsites do not share simulation unless cookie domain spans sites.

### 21.6 Composition root

In `Plugin::init()` (M8+):

- `SimulationContextFilter` is registered **unconditionally** on every request.
- `SimulationAdminBar` is registered unconditionally; renders only when simulation is active.
- `DetectionPage` and `SimulationController` are registered only on admin requests (`should_register_admin()`).

Registration order is intentional: simulation filter hooks at priority 100 on `universal_geo_context`, after resolver output and alongside other filter consumers documented in `docs/HOOKS.md`.

**Do not** register `SimulationContextFilter` only from `wp-admin` — front-end requests from authorized administrators must receive simulated context.

### 21.7 Public API

The public API has **not** changed in M8:

- Same six functions in `src/api.php`.
- Same `VisitorContext` properties and immutability contract.
- Same hook signatures (`universal_geo_context` existed before M8; simulation uses it).
- No provider interface changes.

Simulation affects **only the returned `VisitorContext` value** when active. `universal_geo_get_source()` may return `'simulation'` — this is a new possible **value** of an existing field, not a new API surface.

Downstream plugins consume the public API normally; no WooCommerce dependency; no downstream plugin modifications required.

### 21.8 Downstream plugin philosophy

Simulation is transparent to the API contract but **explicit in semantics**:

- Downstream plugins receive a valid `VisitorContext` through the same functions and hooks as production traffic.
- They **should** treat `source === 'simulation'` as an administrator override, not evidence of the visitor's real location.
- Universal Geo Context does not implement currency, tax, shipping, compliance, or other policy — simulation inherits that boundary.

M9 (Live Detection inspector) and later milestones may add **read-only diagnostics** that observe the resolution pipeline; they must not alter these simulation contracts.

---

## 22. Contributor prohibitions (simulation and v1.x baseline)

The following are **architectural violations** for v1.x unless explicitly approved via ADR and an amendment to this document:

| Do not… | Reason |
|---|---|
| Create a `SimulationProvider` | Simulation is post-resolution, not evidence-based provider output |
| Register `SimulationContextFilter` only in admin | Front-end consumers must see simulated context when authorized |
| Cache simulated contexts in `GeoCache` | Breaks cache isolation; conflates test state with production cache |
| Modify cached contexts in place for simulation | Immutability and cache determinism |
| Invalidate cache or bump epoch for simulation | Simulation must not affect cache lifecycle |
| Alter provider-health records for simulation | Health reflects real provider behaviour only |
| Bypass authorization (cookie-only activation) | Fail-closed administrator gate is frozen |
| Persist simulation state to the database | Cookie-only, session-scoped model |
| Store IP or provider data in the simulation cookie | Privacy and security contract |
| Introduce policy decisions in simulation | Evidence not policy — simulation overrides country only |
| Make simulation depend on WooCommerce | Optional provider pattern applies; simulation is core admin feature |
| Change VisitorContext simulation semantics without an ADR | `source`, `confidence`, `region_code`, `is_cached` values are frozen |
| Add a public REST/AJAX endpoint to control simulation | POST + nonce + `admin_post_*` only |

When in doubt, read ADR-0008 and §21 above before proposing changes.
