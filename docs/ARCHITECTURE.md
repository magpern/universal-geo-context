# Architecture

**Status: M1 complete (frozen, tag `m1`); M2 complete; M3–M6 complete (see
"M3–M5" and "M6" at the end of this document).** This document records the M1
reconciliation history verbatim, below, as the record of how the frozen
public API and composition patterns were decided. M2 replaced
`RemoteAddrOnlyResolver` with the full trust-boundary stack (`ServerRequest`,
`TrustedProxies`, `ClientIpResolver`), added the Cloudflare and WooCommerce
providers, admin settings/diagnostics, and Site Health — see
`docs/adr/0002-trusted-proxy-model.md` and
`docs/adr/0006-optional-woocommerce-integration.md` for M2's own decisions,
and `docs/HOOKS.md` / `docs/TRUSTED_PROXIES.md` / `docs/SECURITY.md` /
`docs/PRIVACY.md` for M2's current, up-to-date behaviour. The M1 narrative
below is retained as historical record and is not updated for M2 — nor for
M3, M4, or M5, each of which is instead summarized in its own short section
at the end of this document, in the same spirit.

**For v1.x contributors**: See `docs/ARCHITECTURE_FREEZE.md` for the permanent architectural contracts that must remain stable across v1.x releases, the boundaries that separate evolution from breaking change, and governance guidance for future milestones.

## M1 bootstrap scope (Step 0)

This milestone establishes:

- Directory structure
- Composer setup and autoloading (PSR-4)
- PHPCS and PHPUnit configuration
- CI pipeline (phpcs, unit, integration, build)
- Release workflow
- Documentation skeleton
- Minimal main plugin file with activation guards and compatibility declarations

## Domain models (Step 1B)

`src/Model/` (namespace `UniversalGeo\Model`) holds the plugin's immutable,
WordPress-free value objects. Per §13 of the Revision 3 plan, `GeoCandidate`
and `VisitorContext` are two of the plugin's few **stable public types** —
neither is `@internal`.

- `GeoCandidate` — geographic facts only: `country_code`, `region_code`,
  both nullable, both `public readonly` promoted properties, accessed
  directly (no getters). It performs **no validation or normalization** —
  providers may hand it any string, including malformed ones — and it
  carries no provider identity, confidence, or policy. Validating and
  normalizing a candidate's facts is `GeoValidator`'s job (Step 1F, below);
  the future resolver loop calls it on `$candidate->country_code` directly —
  `GeoValidator` never touches a `GeoCandidate` instance itself.
- `VisitorContext` — the resolved, final context: `country_code` (nullable —
  `null` means unknown), `region_code` (nullable, always `null` in v1),
  `source`, `confidence`, `is_cached`, all `public readonly`. The
  constructor validates and normalizes a non-null country to uppercase
  `^[A-Z]{2}$`, requires a non-empty trimmed `source`, and bounds
  `confidence` to `[0.0, 1.0]` (rejecting `NAN` and both infinities) — it
  does not perform full ISO-3166 membership checking or resolve geography.
  `unknown()` builds the canonical unknown state (`null` country, `null`
  region, `source = 'unknown'`, `confidence = 0.0`); `is_known()` and
  `has_region()` are read-only predicates; `with_cached()` returns a new
  instance with `is_cached` toggled. `to_array()` / `from_array()` are
  implemented now as part of the core model contract (`SCHEMA_VERSION = 1`);
  `from_array()` is tolerant hydration in the same forgiving spirit as
  `Settings::sanitize()` — malformed input never throws, it falls back to
  safe values. Their first real *consumer* (the derived-context cache) is a
  later milestone, but the methods themselves belong to the model, not to
  caching.

Both classes are `final`.

## Provider contract (Step 1C)

`src/Contracts/GeoProviderInterface.php` (namespace `UniversalGeo\Contracts`)
is the other stable public type named in §13: `get_id(): string`,
`is_available(): bool`, `resolve( string $ip ): ?GeoCandidate`. A provider
attempts one lookup and returns facts only — no caching, no validation, no
normalization, no awareness of other providers or of WordPress settings.
**One concrete provider exists** (`DefaultCountryProvider`, Step 1E, below);
no Cloudflare, MaxMind, WooCommerce, or remote provider exists yet.

## Client-IP contract, IpUtils, and the REMOTE_ADDR-only resolver (Step 1D)

`src/Contracts/ClientIpResolverInterface.php` (namespace `UniversalGeo\Contracts`,
stable per §13) is the other half of Revision 3's "two independent
abstractions": it decides *who is asking*, never *where they are*. One
method — `resolve(): ?ResolvedClientIp` — no trusted-proxy, diagnostics, or
confidence methods; those belong elsewhere or to later milestones.

`src/Http/IpUtils.php` (namespace `UniversalGeo\Http`, `final`, every method
`public static` except one internal helper) is the pure, WordPress-free IP
utility Revision 3 §4.3 assigns normalization, classification, and masking
to. Only the subset the current client-IP contract needs is implemented —
`normalize()`, `is_public()`, `mask()` — not `cidr_match()` or `describe()`,
which belong to trusted-proxy matching and diagnostics respectively (both
later milestones, and both explicitly out of scope here):

- **`normalize( string $raw ): ?string`** — trims whitespace, strips an
  IPv4 `:port` suffix, unwraps bracketed IPv6 (`[::1]` or `[::1]:8080`),
  and reduces an IPv4-mapped IPv6 address (`::ffff:a.b.c.d`) to plain
  IPv4 — the three transforms Revision 3's comment names, and no others:
  casing and IPv6 compression are preserved verbatim, no DNS resolution is
  performed, hostnames are rejected, and zone identifiers (`%eth0`) are not
  addressed (Revision 3 does not describe that form). Returns `null` for
  empty, whitespace-only, or syntactically invalid input — never a
  fabricated fallback address. Handles exactly one address token per call;
  a comma-separated list fails as a whole.
- **`is_public( string $ip ): bool`** — rejects, via an explicit hardcoded
  range table (not solely PHP's `FILTER_FLAG_NO_PRIV_RANGE`/
  `FILTER_FLAG_NO_RES_RANGE`, which do not reliably cover every range
  Revision 3 names): RFC 1918 private space, CGNAT (`100.64.0.0/10`),
  loopback, link-local, IETF protocol assignments, documentation/
  benchmarking ranges, multicast, and reserved/future-use for IPv4;
  unspecified, loopback, link-local, unique local (`fc00::/7`), multicast,
  and documentation space for IPv6. An IPv4-mapped IPv6 address is
  classified by its underlying IPv4 address. Malformed input is never
  public.
- **`mask( string $ip ): string`** — IPv4 → last octet replaced with `x`
  (`203.0.113.x`); IPv6 → first three groups plus an ellipsis
  (`2001:db8:1234:…`), matching §10's examples exactly. Never returns the
  complete address; malformed input returns the literal string `'invalid'`
  rather than leaking whatever was given.

`src/Model/ResolvedClientIp.php` (namespace `UniversalGeo\Model`,
**`@internal`** per §4.2 — it never leaves resolution, unlike `GeoCandidate`/
`VisitorContext`) is `ClientIpResolverInterface::resolve()`'s return type:
`ip`, `header` (`'REMOTE_ADDR' | 'CF-Connecting-IP' | 'X-Forwarded-For' | 'X-Real-IP'`),
`chain_verified`, `is_public`, all `public readonly`. It did not appear in
Step 1B despite Revision 3's own recommended build order (§26) pairing it
with `VisitorContext`/`GeoCandidate` — that gap was closed in Step 1D, since
`ClientIpResolverInterface` cannot be implemented at all without it.
`masked(): string` is now implemented, delegating entirely to
`IpUtils::mask( $this->ip )` — no masking logic is duplicated on the model.

`src/Http/RemoteAddrOnlyResolver.php` (namespace `UniversalGeo\Http`) is the
"fail-closed default M2 layers trust on top of" (§23): it reads
**exclusively** `$_SERVER['REMOTE_ADDR']`, delegates all normalization and
classification to `IpUtils`, and constructs `ResolvedClientIp` with
`header = 'REMOTE_ADDR'` and `chain_verified = true` always (REMOTE_ADDR is
the directly-observed TCP peer, not a forwarded claim). Every forwarding/CDN
header (`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`, `Forwarded`,
`True-Client-IP`, `Client-IP`, any other) is ignored unconditionally — there
is no trusted-proxy configuration here to ever enable them. Missing,
non-string, or anything `IpUtils::normalize()` rejects resolves to `null` —
never a fabricated fallback IP. Private, loopback, CGNAT, ULA, and
documentation addresses are **not** rejected from resolving: REMOTE_ADDR may
legitimately be an internal proxy (this VPS's own Docker bridge peer), and
this class's job is safe source selection, not proof of public routability
— they are, however, now *correctly classified* as non-public via
`is_public`. Class is `final`; it is *replaced* by the full
`ClientIpResolver` in M2 (§23), not extended by it, once `ServerRequest` and
`TrustedProxies` exist.

## Default country provider (Step 1E)

`src/Providers/DefaultCountryProvider.php` (namespace `UniversalGeo\Providers`,
`@internal` per §13 rule 4 — only `Model`/`Contracts` types are stable) is
the terminal entry in Revision 3's fixed provider chain: `PROVIDER_ORDER`'s
last element and `CONFIDENCE`'s `'default' => 0.10` entry. When every other
provider misses, this is what answers instead of leaving the visitor
`unknown` — if the site operator configured one.

`__construct( string $default_country )` — a single scalar, not a `Settings`
instance. Revision 3's `CloudflareHeaderProvider` description (§7) is the
precedent: providers receive *pre-decided values* via constructor injection
("the same two modes as §6 step 4a, decided once by `ClientIpResolver` and
injected, not re-derived"), never a live settings/service reference — the
composition root (`Plugin.php`) is responsible for reading configuration
once and handing providers plain values. This sidesteps the open question of
whether `Settings` (currently a Step 1A static utility with no instance
form) satisfies `ContextResolver`'s eventual `Settings $settings`
constructor parameter — `DefaultCountryProvider` does not depend on that
being resolved, now or later, and needs no redesign when it is.

- **`get_id()`** — always the literal string `'default'`.
- **`is_available()`** — `true` exactly when the configured string is
  non-empty. An empty string means "unconfigured" — `Settings::default_country`'s
  own documented contract ("Empty produces `unknown`, not a guess", §11) —
  not "invalid". No format/ISO-3166 validation happens here.
- **`resolve( string $ip )`** — `$ip` is read nowhere in the method body;
  the interface requires the parameter, but this provider answers purely
  from configuration. Returns `new GeoCandidate( $default_country, null )`
  when available, `null` otherwise. No `source`, `confidence`, or any other
  field is attached — `GeoCandidate` remains the plain two-field carrier
  from Step 1B, unmodified.
- **Country validation ownership**: structural/ISO-3166 validation is
  `GeoValidator::country()`'s job (Step 1F, below), applied uniformly to
  *every* provider's candidate by the future resolver loop (§7:
  `GeoValidator::country(candidate->country_code)`, discarded-not-down-scored
  on failure) — not per-provider. A malformed configured value (e.g.
  `'SWE'`) is `is_available() === true` and flows into a `GeoCandidate`
  unvalidated; only the future resolver loop calling `GeoValidator` would
  discard it (verified directly: `GeoValidator::country('SWE') === null`).
  Duplicating that check inside the provider would diverge from that single
  source of truth.
- **Region**: always `null`. `Settings`' current (frozen, Step 1A) schema
  has no region-related key at all, and Revision 3 defines no default-region
  setting.

**Updated by Step 1I**: `DefaultCountryProvider` is now registered in
`Plugin.php`'s provider array and reachable at runtime via the public API
— see the runtime composition section below. Wiring it required
`ContextResolver` to exist to consume the array, which it now does.

## Country/region validation — GeoValidator (Step 1F)

`src/Resolver/GeoValidator.php` (namespace `UniversalGeo\Resolver`, `final`,
`@internal` per §13 rule 4, no constructor, every method `public static`) is
Revision 3 §4.4's exact declared contract — **two independent static
methods operating on raw strings, not on a `GeoCandidate` instance**:

```php
GeoValidator::country( ?string $raw ): ?string
GeoValidator::region( ?string $raw, string $country ): ?string
```

This centralizes the geographic validation every provider's candidate goes
through uniformly in the future resolver loop (§7) — it is not, and must
never become, a per-provider concern.

- **`country()`** — trims whitespace, uppercases, then checks membership in
  an **embedded** ISO 3166-1 alpha-2 allowlist (250 entries: the 249
  standard codes plus `XK`/Kosovo, explicitly named by §8) — never derived
  from `WC()->countries` or any external package, since a sales list an
  administrator can restrict must never filter geographic evidence.
  Membership alone enforces the two-letter uppercase shape; no separate
  regex step exists. Explicitly rejects `EU`, `AP`, `XX`, `ZZ`, `T1`, `A1`,
  `A2`, `O1` (common GeoIP placeholder/reserved values — every one of them
  is structurally two uppercase letters, so only real ISO membership
  catches them, not a regex) and `UK`/`UN` (not real ISO codes; the UK's
  actual code is `GB`). `null`, empty, and whitespace-only input all return
  `null` — never a fabricated country.
- **`region()`** — trims, uppercases, strips a leading `"{$country}-"`
  prefix if present (`'SE-AB'` with country `'SE'` → `'AB'`), then requires
  the remainder to match `^[A-Z0-9]{1,3}$` — **syntactic only**, per §8: no
  ISO 3166-2 subdivision table exists yet (lands in 1.1 with the first real
  region source). `$country` is trusted as already-validated (the caller is
  expected to call `country()` first) and is used only for prefix-stripping,
  never re-validated here. An invalid region does not invalidate the
  country — per §7's algorithm, region validation failure is independent of
  country validation failure; the caller combines the two results itself.

Not implemented: no `validate(GeoCandidate): ?GeoCandidate` method, no
`GeoValidationResult` type, no `ValidatorInterface`, no country/region
combined-pair rejection method. Revision 3's actual declared API is the two
string-in/string-out static methods above; `GeoValidator` never constructs,
inspects, or mutates a `GeoCandidate`, never assigns `source` or
`confidence`, never constructs a `VisitorContext`, never reads `$_SERVER` or
any client-IP data, and has no WordPress dependency whatsoever.

## Derived-context cache — GeoCache (Step 1G)

`src/Cache/GeoCache.php` (namespace `UniversalGeo\Cache`, `final`,
`@internal` per §13 rule 4) implements Revision 3 §9 Layer 3 exactly. It is
`ContextResolver`'s third and final constructor dependency, unconditionally
injected — nothing about the class is temporary.

```php
GeoCache::__construct( bool $enabled, int $ttl_seconds, string $config_sig )
GeoCache::get( string $ip ): ?VisitorContext
GeoCache::set( string $ip, VisitorContext $context ): void
```

- **Constructor** — three scalars, none of them `Settings`: `$enabled` is
  the `derived_cache_enabled` setting, `$ttl_seconds` is `derived_cache_ttl`
  (both §11's "only two knobs exposed"), `$config_sig` is a pre-computed
  hash of the settings that affect resolution. All three are extracted (and,
  for `config_sig`, computed) once by the composition root — this class
  never reads `Settings` or raw options for any of them, matching the same
  "pre-decided value, injected not re-derived" pattern already used for
  `DefaultCountryProvider`.
- **Enabled / no-op degradation** — active only when *both* `$enabled` is
  true *and* `wp_using_ext_object_cache()` is true (§9: without a real
  persistent object cache, `wp_cache_*` is per-request only, so caching
  would achieve nothing beyond what `ContextResolver`'s own request memo
  already does). When inactive, `get()` returns `null` and `set()` does
  nothing — no WordPress function is called at all, not even key-building.
  `ContextResolver` never branches on enabled state itself; the no-op is
  entirely internal.
- **Storage backend** — `wp_cache_get()`/`wp_cache_set()` (the WordPress
  *object* cache), group `'universal_geo'` — explicitly **not**
  `get_transient()`/`set_transient()`, which §9 rejects by name ("transients
  would write to `wp_options` on every anonymous visitor — genuine write
  amplification").
- **Cache key** — exactly `"{epoch}:{config_sig}:ip:{hash}"`, `hash =`
  the first 32 hex characters of `hash_hmac('sha256', $ip, $salt)` — §9's
  literal format, byte for byte. `$ip` is trusted to already be normalized
  by the caller; `GeoCache` does not call `IpUtils` itself.
- **Salt** — 32 random bytes (`random_bytes(32)`, base64-encoded for
  DB-safe storage), read from the `universal_geo_cache_salt` option — the
  exact option name already anticipated in Step 1A's frozen
  `SettingsTest.php` negative-ownership test. **Generated and persisted
  lazily, on first actual cache operation** (read or write — key-building
  needs the salt either way), never eagerly at construction, matching the
  plugin-wide "nothing resolves until a consumer asks" principle.
- **Epoch** — read from `universal_geo_cache_epoch` (same
  already-anticipated option name), defaulting to `1` if unset.
  `GeoCache` only **reads** it; bumping it on every settings save is the
  future admin-save handler's job (§9), not this class's — confirmed absent
  from `GeoCache`'s own responsibilities here.
- **Negative-result caching** — `set()` inspects `$context->is_known()`:
  a known context uses the configured `$ttl_seconds`; an unknown one uses a
  fixed, internal `300` — never the configured TTL, never caller-supplied —
  so a consistently-failing provider chain isn't retried every request
  (§9). This keeps the negative-result TTL "internal" (§9's own word),
  exposed through neither the constructor nor `set()`'s signature.
- **`is_cached` handling** — `set()` always persists with
  `is_cached` forced to `false` (via `$context->with_cached(false)`) before
  storing, regardless of what the caller passed in, so a stale `true` flag
  can never be embedded and replayed. `get()` applies `->with_cached(true)`
  to whatever it reconstructs before returning it — a hit is unambiguously
  "from cache" the moment `get()` found something, so that transformation
  belongs here, not in `ContextResolver`.
- **Malformed / incompatible entries** — always treated as an ordinary
  miss (`get()` returns `null`), never an exception, never
  `VisitorContext::unknown()`: a non-array payload, a missing or mismatched
  `schema_version` (checked against `VisitorContext::SCHEMA_VERSION`
  explicitly, before ever calling `from_array()`), or a payload
  `VisitorContext::from_array()` can't make sense of (its own Step 1B
  tolerant-hydration contract — falls back to safe values, never throws).
  A stale `epoch`/`config_sig` never even reaches this logic: since both are
  baked into the key itself, a configuration change simply produces a
  different key, so old entries are unreachable by construction, not
  detected and rejected.
- **Payload shape** — exactly `VisitorContext::to_array()`'s existing,
  unmodified Step 1B shape (`schema_version`, `country_code`, `region_code`,
  `source`, `confidence`, `is_cached`). No `ResolvedClientIp`, no raw IP, no
  `GeoCandidate`, no provider diagnostics are ever stored — `GeoCache` never
  even imports those types.
- **What it is IP-publicness-agnostic about**: `GeoCache` has no opinion on
  whether an IP is public or private — that gate (§6 step 5) happens
  upstream, before `ContextResolver` ever calls `GeoCache` at all. It caches
  whatever it's given, keyed by whatever IP string it's given.
- **What it does not do**: no request-level memoization (that's Layer 1,
  `ContextResolver`'s own `?VisitorContext` property); no provider
  iteration, confidence assignment, or country/region validation; no cache
  flush/reset method (not part of the current public API — a future WP-CLI
  "cache flush" command, §26 M5, is a separate, more consequential operation
  than anything built here); no epoch mutation.

## ContextResolver (Step 1H) — implemented, matching the reconciled model

`src/Resolver/ContextResolver.php` (namespace `UniversalGeo\Resolver`,
`final`, `@internal` per §13 rule 4) is now implemented, exactly matching
the permanent three-argument constructor the prior reconciliation
determined. The reconciliation evidence below is kept as the record of
*why* the constructor takes the shape it does; everything past it describes
the actual, tested implementation.

**Implementation clarification (architecture reconciliation, not a Revision 3
edit).** Earlier steps reported Revision 3 §4.4's constructor pseudocode
literally:

```
ContextResolver::__construct(
    ClientIpResolverInterface $client_ip_resolver,
    GeoProviderInterface[]    $providers,
    GeoValidator              $validator,
    GeoCache                  $cache,
    Settings                  $settings
)
```

Read against the rest of Revision 3 as a whole, this line does not survive
literally. It is the least precise sentence in §4.4, not a separately
verified fact: `GeoProviderInterface[] $providers` is the only entry written
with an actual parameter name; the other four are bare type names — a
notational inconsistency inside one line that signals shorthand, not a
literal signature. Four independent passages contradict a literal reading of
`GeoValidator` and `Settings` as constructor-injected instances:

| # | Passage | Says |
|---|---|---|
| 1 | §2's architecture diagram | Exactly **three** boxes hang off `ContextResolver`: `ClientIpResolver`, the fixed provider chain, `GeoCache`. `Settings` appears nowhere in the diagram. `GeoValidator` appears only as the arrow-label "`GeoCandidate ──► validate/normalize`" between the provider chain and `VisitorContext` — a process step, not a boxed dependency. |
| 2 | §4.4, the line immediately below the constructor | `GeoValidator // pure static, WordPress-free` — stated as a direct fact about the class one line after it was named in the constructor list. |
| 3 | §7's resolver algorithm | `country = GeoValidator::country(candidate->country_code)` — literal PHP static-call syntax (`::`), never `$this->validator->country(...)`. |
| 4 | §16's mocking strategy | Names exactly three test seams needing substitution (`ServerRequest`, `GeoProviderInterface`, `HttpTransport`). `GeoValidator` is absent — consistent with it being a pure static function, not an injected collaborator ever needing a double. |

`Settings` has no equivalent "pure static" sentence attached to the
constructor line, so its case rests on convergent evidence rather than one
direct contradiction: the actual, frozen Step 1A `Settings` class is 100%
static (`defaults()`, `sanitize()`, `install()`, `uninstall()` — no
instance, no constructor); §9 assigns every cache-relevant setting (`TTL`,
`derived_cache_enabled`, salt, epoch, `config_sig`) to `GeoCache` itself,
not to `ContextResolver`; and the provider-injection pattern already
implemented twice (`DefaultCountryProvider`'s Step 1E report, citing
`CloudflareHeaderProvider`'s §7 description: "decided once... and injected,
not re-derived") shows Revision 3's actual house style is composition-root
extraction of scalar values, never handing a live settings object to a
downstream consumer. No passage in §7's algorithm identifies a
`ContextResolver`-level use of any setting that isn't already owned by a
provider (`default_country` → `DefaultCountryProvider`) or by `GeoCache`
(everything cache-related). Tracing the algorithm end to end, `ContextResolver`
itself appears to need **no** direct settings value of its own.

**Classification of the five constructor entries:**

| Entry | Classification | Reasoning |
|---|---|---|
| `ClientIpResolverInterface` | Normative | Named, has real per-request instance behaviour (`RemoteAddrOnlyResolver`/future `ClientIpResolver`), appears in the §2 diagram as a boxed dependency |
| `GeoProviderInterface[] $providers` | Normative | Only entry with an explicit parameter name; boxed in the §2 diagram; confirmed by §7's loop |
| `GeoValidator` | **Illustrative shorthand, not literal** | Contradicted by its own "pure static" annotation, §7's `::` usage, §16's silence, and the §2 diagram showing it as a process arrow, not a box |
| `GeoCache` | Normative | Boxed in the §2 diagram; has genuine per-request/persistent instance state (salt, epoch, live cache connection) |
| `Settings` | **Illustrative shorthand, not literal** | Absent from the §2 diagram; the real class is static-only; every setting §9 names is owned by `GeoCache`, not passed through `ContextResolver` |

**Conclusion — GeoValidator dependency decision:** **B.** `ContextResolver`
calls `GeoValidator::country()`/`GeoValidator::region()` directly as static
methods inside `resolve()`/`probe()`. It is never constructed, never
injected, never held as a property.

**Conclusion — Settings dependency decision:** No direct `Settings`
dependency. Every setting `ContextResolver`'s own algorithm needs is already
resolved and injected into one of its *other* dependencies before they reach
it: `default_country` → extracted once by `Plugin.php`, injected into
`DefaultCountryProvider`'s constructor (done, Step 1E); every cache setting
(`derived_cache_enabled`, `derived_cache_ttl`, salt, epoch, `config_sig`
inputs) → owned by `GeoCache`, extracted once by `Plugin.php` when
constructing it. `Settings::sanitize()`/`Settings::defaults()` remain called
by static reference wherever a raw persisted option needs cleaning (their
existing, frozen, unchanged contract) — `ContextResolver` calls neither.

**Permanent constructor** (three real object dependencies):

```php
ContextResolver::__construct(
    ClientIpResolverInterface $client_ip_resolver,
    array                     $providers,   // GeoProviderInterface[], validated element-by-element, order preserved
    GeoCache                  $cache
)
```

**Permanent public API**, confirmed normative (§4.4, §9, §12):

- `resolve(): VisitorContext` — **no arguments.** Memoized: one private,
  in-memory `?VisitorContext` property, set on the first successful call
  within a request and returned unchanged on every subsequent call in the
  same request (§9 Layer 1 — "Ten consumers in one request cost one
  resolution").
- `probe( ?string $ip = null ): array` — `$ip` is a manual override for
  diagnostics (test resolution against an arbitrary address rather than the
  live request's). Bypasses **both** the memoization property and `GeoCache`
  entirely; runs every provider with no short-circuit, so conflicting
  candidates are visible (§4.4, §12). **Exact return shape is not specified
  anywhere in Revision 3** — only its behaviour (every provider, no memo, no
  cache) is normative. The literal array shape is deferred to whichever step
  builds `DiagnosticsService`, its first real consumer (§26 M2 step 8) — not
  invented here.
- `reset(): void` — "tests + CLI" (§4.4/§9). Clears **only** the in-memory
  memoization property. It does not flush `GeoCache`'s persistent layer:
  §26's M5 WP-CLI step lists "context / diagnostics / cache flush" as
  distinct commands, implying the persistent cache has its own, separate
  flush operation — a live Redis flush is a materially different, more
  consequential action than clearing one in-process property, and conflating
  them under one `reset()` would be a scope-widening invention this analysis
  does not make.

**Provider array contract:** `array $providers`, PHPDoc-typed
`GeoProviderInterface[]`. Every element validated `instanceof
GeoProviderInterface` in the constructor (`InvalidArgumentException`
otherwise — matching the pattern already used and then deliberately removed
from the earlier premature `ContextResolver`, now applicable again once the
other two dependencies exist). Empty arrays are allowed (structurally valid;
`resolve()` would fall through to `VisitorContext::unknown()`). Order is
preserved exactly as given — per §4.4's own note, `ContextResolver` "never
consults `PROVIDER_ORDER` inside the loop"; that constant is **documentation
of the order `Plugin.php` builds the default array in**, not something read
at runtime by `ContextResolver` itself. `DefaultCountryProvider` is not
structurally required to be present — an empty or partial array is valid,
just produces `unknown` more often. `array_values()` re-indexing (the
pattern used in the removed `ContextResolver` draft) remains the right
approach: it guarantees iteration order matches insertion order regardless
of the caller's array keys.

`ContextResolver` injects `GeoCache` unconditionally (never
optional/nullable) — §9's "honest no-op" on a plain LAMP host is `GeoCache`'s
own internal degradation; `ContextResolver` never branches on it.

### resolve() algorithm, as implemented

```
1. memo set?                                    → return it unchanged
2. client_ip_resolver->resolve() → null?         → memoize+return VisitorContext::unknown();
                                                    no cache call, no provider call
3. cache->get(ip) → hit?                         → memoize+return it unchanged (already is_cached = true)
4. provider loop, injected order, short-circuit at first candidate whose
   country validates (see below)
5. no candidate won                              → VisitorContext::unknown()
6. cache->set(ip, context)                       → unconditional: GeoCache decides the TTL
                                                    (normal vs. the internal 300s negative TTL)
                                                    from is_known() itself; no TTL is passed
7. memoize, return
```

Steps 9–10 of Revision 3 §5's full algorithm (`apply_filters('universal_geo_context', …)`
and `do_action('universal_geo_context_resolved', …)`) are **not implemented
here** — hooks are explicitly out of scope for this step (they require
`src/api.php` and the public function surface, later M1 work). `resolve()`
memoizes and returns immediately after the cache write instead.

**Provider loop, per provider, in injected order:**

```
1. is_available()  → false: skip, no resolve() call
2. resolve(ip)      → wrapped in try/catch: "a provider can never fatal a
                       page view" (§7) — any Throwable is treated exactly
                       like a miss, skip to the next provider
3. null candidate?  → skip
4. GeoValidator::country(candidate->country_code) → null?  → skip (an
                       invalid country discards the whole candidate; it is
                       never down-scored — Revision 3 §8)
5. GeoValidator::region(candidate->region_code, $country) → used as-is,
                       including null (an invalid region does NOT discard a
                       valid country — Revision 3 §7)
6. get_id() called exactly once, only for the winning candidate → becomes
                       both `source` and the CONFIDENCE lookup key
7. return new VisitorContext(country, region, provider_id, confidence, false)
                       → short-circuits the whole loop
```

**Source and confidence** — `source` is always the winning provider's
`get_id()`, read exactly once. `confidence` is `self::CONFIDENCE[$provider_id]
?? self::UNLISTED_PROVIDER_CONFIDENCE` — the exact Revision 3 §8 table
(`cloudflare` 0.95, `maxmind` 0.90, `woocommerce` 0.85, `remote` 0.85,
`default` 0.10, `unknown` 0.00 — reproduced verbatim as a class constant even
though `'unknown'` is never looked up through it, since `VisitorContext::unknown()`
hardcodes its own 0.0 directly) and `UNLISTED_PROVIDER_CONFIDENCE = 0.85`.
Neither `GeoCandidate` nor any provider ever supplies either value.

**`PROVIDER_ORDER`** is reproduced as a class constant verbatim from §4.4,
documenting the order `Plugin.php` will eventually build the default array
in — **never read inside the loop**. Injected array order always wins,
confirmed by a dedicated test using provider ids (`'zzz'` before `'aaa'`)
that would sort the opposite way under any alphabetical or `PROVIDER_ORDER`-
matching bias.

**Public/private IP policy — a judgment call, reasoned explicitly.**
`GeoProviderInterface` has no `needs_ip()`-style flag; §6 step 5's "the
resolver then SKIPS IP-based providers" describes behaviour with no method
on the frozen interface to implement it generically. Concluded: an IP-based
provider is responsible for its own "this IP isn't usable" decision inside
its own `resolve()` (e.g. returning `null` for a private/unroutable address),
not something `ContextResolver` pre-filters by inspecting
`ResolvedClientIp::is_public`. `ContextResolver` therefore calls every
available provider identically regardless of `is_public` — which is exactly
what keeps `DefaultCountryProvider` (explicitly IP-independent) usable no
matter the client IP's publicness, and no Revision 3 passage restricts
`GeoCache` reads/writes to public IPs only, so caching also proceeds
identically regardless. Verified by dedicated tests using a private IP
(`10.0.0.5`, `is_public = false`).

**Unknown-context conditions, all covered:** no client IP resolved; empty
provider array; every provider unavailable; every provider misses or throws;
every returned country fails `GeoValidator::country()`. All of them produce
`VisitorContext::unknown()` (never a hand-built duplicate shape), which is
memoized and — except for the "no client IP" case — still written to the
cache, using `GeoCache`'s own internal negative TTL.

### probe() — the one architecture gap this step fills

Revision 3 specifies `probe()`'s *behaviour* exactly (§4.4, §12: every
provider, no short-circuit, no memo, no cache) but **never its return
shape** — confirmed again by a fresh, targeted re-search this step (no
`DiagnosticsService`, no probe-result example, no diagnostics payload
schema exists anywhere in the plan; that service is a later M2 milestone
and is the shape's real first consumer). This is the sole place this
milestone had to decide something Revision 3 leaves open, and it decided
the smallest internally coherent shape rather than a broad public schema:

```php
probe( ?string $ip = null ): array   // list, one entry per provider, injected order

[
    'provider'     => string,       // get_id()
    'available'    => bool,         // is_available()
    'country_code' => string|null,  // raw (invalid) value for 'invalid_country'; validated value for 'ok'; null otherwise
    'region_code'  => string|null,  // validated value for 'ok' only; null otherwise
    'reason'       => string,       // 'unavailable' | 'failed' | 'miss' | 'invalid_country' | 'ok'
]
```

No raw IP, `ResolvedClientIp`, provider object, exception, stack trace, or
cache key ever appears in a probe result. `$ip`, when explicitly given, is
normalized via `IpUtils::normalize()` and used as-is — the client-IP
resolver is not consulted at all in that case (verified: zero calls). When
`$ip` is `null`, the client-IP resolver is consulted exactly like `resolve()`
would. If no usable IP results either way (malformed explicit `$ip`, or no
resolvable client IP), `probe()` visits no provider and returns `[]` —
mirroring `resolve()`'s own "no request context" short-circuit rather than
inventing different behaviour for the diagnostic path. `probe()` never
reads or writes `$this->memo` and never calls `$this->cache` — confirmed by
dedicated tests showing a `probe()` call does not affect a subsequent
`resolve()`'s provider-call count, and never appears in the fake
object-cache's call log.

### reset()

Clears only `$this->memo` (set back to `null`). Does not flush `GeoCache`,
does not touch its salt or epoch options, does not reconstruct any injected
dependency. The next `resolve()` call re-runs the full process — including
consulting `GeoCache` again, which may still produce a hit if the earlier
write survived (verified: after `reset()`, a second `resolve()` re-invokes
the client-IP resolver but not the provider, because the cache entry from
the first resolution is still there).

`GeoValidator::country()`/`region()` are called **inside** `ContextResolver`
by static reference — they never appear in `Plugin.php`'s composition graph
at all, consistent with not being a constructor argument. `Settings` is
likewise never passed to `ContextResolver` — see the next section for
exactly how its values actually reach the object graph.

**Dependency-readiness table — all satisfied, and now wired together (Step 1I):**

| Dependency | Exists? | Injectable form ready? | Wired into `Plugin.php`? |
|---|---|---|---|
| `ClientIpResolverInterface` | Yes (Step 1D) | Yes | **Yes** |
| `GeoProviderInterface[]` | Yes (Step 1C/1E) | Yes | **Yes** |
| `GeoCache` | Yes (Step 1G) | Yes | **Yes** |
| *(`GeoValidator` — not a constructor argument; called statically, complete since Step 1F)* | | | |
| *(`Settings` — not a constructor argument; values reach providers/`GeoCache` via `Plugin.php`)* | | | |

## Runtime composition and public API (Step 1I)

`src/Plugin.php`'s `init()` now eagerly **constructs** the full M1 object
graph (construction is cheap — no I/O); **resolution** stays lazy, exactly
per Revision 3 §5 ("nothing resolves. Zero cost for requests that never
ask."). This is the opposite of what an earlier draft of this milestone
assumed (a lazily-constructed resolver) — §5's own lifecycle pseudocode is
explicit that `Plugin::init()` builds the object graph, and only the first
`universal_geo_get_context()` call actually resolves anything.

```
Plugin::init()
  settings = Settings::sanitize( get_option( Settings::OPTION_NAME, false ) )   [Plugin.php only]

  providers = [ new DefaultCountryProvider( settings['default_country'] ) ]     [done, Step 1E]

  config_sig = hash( 'sha256', settings['default_country'] )                   [deterministic; see below]
  cache      = new GeoCache( true, 900, config_sig )                           [done, Step 1G]

  resolver = new ContextResolver(
      new RemoteAddrOnlyResolver(), providers, cache
  )                                                                             [done, Step 1H]

  $this->resolver = $resolver   // stored; resolve() is NOT called here
```

- **`config_sig`** hashes the one currently-existing setting that affects
  resolution (`default_country`) — `sha256`, full hex digest, no
  truncation. The smallest deterministic signature satisfying §9's "hashes
  the settings that affect resolution", since no other resolution-affecting
  setting exists yet. Verified indirectly (not via a public accessor — none
  exists): two `Plugin` instances built from identical settings share a
  cache hit; changing `default_country` produces a cache miss instead.
- **`derived_cache_enabled` / `derived_cache_ttl`** — passed as the literal
  values `true` / `900`, Revision 3 §11's documented defaults. **Flagged
  gap, not silently absorbed**: `Settings`' current, frozen two-key schema
  does not yet expose either as administrator-configurable — that is a
  future Settings-schema expansion, explicitly out of this step's scope
  (`Settings.php` is frozen). Until that expansion exists, the default is
  the only value that could possibly apply, since there is no admin UI to
  set anything else either.
- **`universal_geo_providers` and `universal_geo_default_country`** (§14)
  are **not applied** in this step's composition, even though `Plugin.php`
  composition is in scope — deliberately: the task defining this step named
  exactly two hooks (`universal_geo_context`, `universal_geo_context_resolved`)
  as in scope, not all seven from §14. Applying a provider-reordering filter
  with exactly one provider, or a default-country filter before any
  documented consumer exists, would be speculative. Deferred, not forgotten.
- Client-IP resolution is `RemoteAddrOnlyResolver` only — no
  `ServerRequest` snapshot (that class doesn't exist; M2 scope), no
  `TrustedProxies`, no forwarding-header trust of any kind.

### The public API — `src/api.php`, six functions, frozen from here

Loaded via Composer `autoload.files` (`composer.json`'s `autoload.files`
now lists `src/api.php`) — not a manual `require` in the bootstrap file,
matching §3's directory-tree comment exactly. Global scope, not namespaced
(matches every `universal_geo_*()` call throughout Revision 3). Every
declaration guarded with `function_exists()`.

```php
universal_geo_get_context(): UniversalGeo\Model\VisitorContext
universal_geo_get_country_code(): ?string
universal_geo_get_region_code(): ?string      // always null in v1
universal_geo_get_source(): string
universal_geo_get_confidence(): float
universal_geo_api_version(): int              // 1
```

The five non-`api_version()` functions all delegate to
`Plugin::instance()->context()`, sharing exactly one resolution per request
— verified by a test asserting only one `GeoCache` write occurs no matter
how many of the five are called. `universal_geo_api_version()` is pure (no
`Plugin` access at all) and works even before boot.

### Hooks — fired by `Plugin::context()`, not `ContextResolver`

`Plugin::context()`, not the frozen `ContextResolver`, is where
`'universal_geo_context'` (filter) and `'universal_geo_context_resolved'`
(action) fire — a deliberate placement decision, not a Revision 3 literal
reading. §5's own numbered `resolve()` pseudocode lists them as steps 9–10
of `ContextResolver::resolve()`, but four separate facts point away from
implementing them there: `ContextResolver` was built, tested (62 tests), and
documented across two prior milestones as strictly framework-independent,
calling no WordPress function at all; §14's required re-validation behaviour
explicitly needs `_doing_it_wrong()`, itself a WordPress function; this
task's own "HOOK OWNERSHIP" instructions treat the hook's home as an open
question to determine, not a given; and moving them to the API boundary
achieves the *identical observable behaviour* §5 describes without touching
a frozen, already-tested contract. `ContextResolver.php` was not modified.

- **Filter timing**: `Plugin::context()` calls `$resolver->resolve()`, then
  `apply_filters( 'universal_geo_context', $context )` immediately — "runs
  first and gets the last word" (§14).
- **Re-validation** (§14, exactly): a filter result that is not a
  `VisitorContext`, or is one with a known country that fails
  `GeoValidator::country()` (structurally valid per `VisitorContext`'s own
  constructor, e.g. `'XX'`, but not a real ISO country — exactly the gap
  re-validation exists to close), is discarded; the original, pre-filter
  context is kept; `_doing_it_wrong()` fires.
- **Action timing**: `do_action( 'universal_geo_context_resolved', $filtered )`
  fires immediately after, receiving the already-filtered value — "cannot
  change it" (§14).
- **Once per request**: both fire at most once per `Plugin` instance — a
  dedicated `$context_resolved` flag, separate from `ContextResolver`'s own
  internal memo (since a filter can hand back a *different* object than
  `ContextResolver` produced). Verified directly: three `context()` calls,
  one filter invocation.
- **Cache hits included**: both fire on a cache hit exactly as on a fresh
  resolution — "a consumer must never see filtered contexts only on
  misses" (§14). Verified: pre-warm the cache with an unfiltered instance,
  then observe the filter still firing on a second, fresh `Plugin` instance
  that hits that cache entry.
- **Unknown results included**: both fire for `VisitorContext::unknown()`
  exactly like a known result.
- **No raw IP crosses the boundary**: the sole argument in both cases is a
  `VisitorContext` — structurally incapable of carrying an IP (Step 1B's
  frozen contract has no such field).
- **Not implemented this step, and not claimed**: `universal_geo_providers`,
  `universal_geo_trusted_proxies`, `universal_geo_maxmind_db_path`,
  `universal_geo_default_country`, `universal_geo_provider_failed` — five of
  §14's seven hooks, out of this step's named scope.

### Not exposed publicly: reset() and probe()

Revision 3 §13's six functions include **no** reset or probe helper — this
milestone confirmed that by reading §13 verbatim rather than assuming one
existed. `ContextResolver::reset()` remains "tests + CLI" (§4.4) and
`probe()` remains `@internal` diagnostics; neither is reachable from
`src/api.php`. Tests reset `Plugin`'s static singleton via reflection
between cases — no new public reset method was added to production code
solely for test convenience.

## Next steps

`GeoCache`-and-`ContextResolver` are now reachable end-to-end for the one
concrete provider that exists. The next steps are: a real client-IP trust
boundary (`ServerRequest`, `TrustedProxies`, the full `ClientIpResolver`
replacing `RemoteAddrOnlyResolver`, M2), the Cloudflare/MaxMind/WooCommerce
providers, `DiagnosticsService` (probe()'s first real consumer, which will
also settle its exact result-shape expectations), the remaining five §14
hooks, WP-CLI (`reset()`'s and `probe()`'s intended consumer), and the
`Settings`-schema expansion that will replace this step's hardcoded
`derived_cache_enabled`/`derived_cache_ttl` defaults with real
administrator-configurable values.

(This section is M1's own forward-looking text, retained as historical
record per this document's own stated policy above — not all of it played
out exactly as written; see "M3–M5" below for what actually shipped. In
particular, WP-CLI (M5) ended up consuming `probe()` but not `reset()` —
`reset()` remains "tests only", used nowhere in production code.)

## M3–M5 (current)

Summarized in the same spirit as the M2 paragraph above — current,
up-to-date behavior lives in the docs named per milestone below, not
re-narrated here.

**M3 — "MaxMind and caching, the privacy floor".** `MaxMindProvider` (local
`.mmdb` country lookups, soft dependency on the `MaxMind\Db\Reader` reader
class); the `maxmind_db_path` setting (syntactic sanitize only — filesystem
validation lives exclusively in `AdminScreen::handle_save_settings()`);
`ProviderHealthStore`; the `universal_geo_maxmind` Site Health test; the
fourth and final capped guard test, `PrivacyGuardTest`, formalizing the
privacy floor M1/M2 already satisfied by review alone. See
`docs/adr/0005-privacy-model.md` and `docs/PRIVACY.md`.

**M4 — "Remote provider".** `ReferenceRemoteProvider` (MaxMind GeoLite2
Country Web Service), disabled by default, requiring an explicit transfer
acknowledgement plus a credential pair in the same settings submission;
`CircuitBreaker`; the `HttpTransport` seam
(`Providers/Remote/WordPressHttpTransport.php` is the sole caller of
`wp_safe_remote_get()` anywhere in `src/`, enforced by `PrivacyGuardTest`);
the `universal_geo_remote_provider` Site Health test. No new public hook,
no public API change. See `docs/adr/0003-provider-architecture.md`'s M4
amendment and `docs/SECURITY.md`.

**M5 — Operational maturity → v1.0.0.** No architecture change: no new
provider, no new hook, no public API change, `ContextResolver`'s signature
and logic untouched. Four additions, each a consumer of the existing
composition root/services rather than a new layer:

- `Cli\Command` (`src/Cli/`) — the three WP-CLI commands
  (`context`/`diagnostics`/`cache flush`), constructed and registered by
  `Plugin::init()` on the symmetric WP-CLI-only path
  (`should_register_cli()`), the exact counterpart to `AdminScreen`'s
  admin-only registration. Reuses `ContextResolver::probe()` (already
  public) and `DiagnosticsService::report()`/`field_labels()` — no
  duplicated resolution or diagnostics logic.
- `Privacy\PrivacyPolicyContent` (`src/Privacy/`) — builds the text
  `Plugin::init()` registers via `wp_add_privacy_policy_content()` on
  `admin_init`, on the admin-only path alongside `AdminScreen`.
- `DiagnosticsService::add_debug_information()` — a second registration
  (`debug_information`, alongside the existing `site_status_tests` filter)
  on the same class, reusing `report()` verbatim for the Site Health Info
  screen.
- `load_plugin_textdomain()` (`universal-geo-context.php`, registered
  ahead of the PHP-version guard) plus a generated `.pot`
  (`languages/universal-geo-context.pot`, `bin/make-pot.sh`) and a CI check
  that fails on drift between source strings and the committed file.

Plus two small, contained fixes discovered during the M5 repository audit,
neither an architecture change: `Settings::sanitize_country()` now checks
real ISO 3166-1 membership via `GeoValidator::country()` (previously shape
only), and `AdminScreen::handle_dismiss_notice()` now checks
`manage_options` before its nonce, matching every other `admin_post_*`
handler in that class. See `docs/COMPATIBILITY.md`, `docs/PRIVACY.md`, and
`readme.txt` for M5's release-maturity surface (version parity,
`bin/release-audit.sh`).

## M6 — Managed GeoLite2 Country database downloads (v1.1.0)

Summarized in the same spirit as M2–M5 above. An **operational service
wrapped around** the existing, frozen provider architecture — not a
redesign of it: `VisitorContext`, `ContextResolver`'s contract, both
frozen interfaces, the public API, the seven public hooks, and the
composition-root/cache/privacy architecture established through M1–M5 are
all unchanged. See `docs/ARCHITECTURE_FREEZE.md` for the frozen contracts
this milestone was built to preserve.

New namespace `UniversalGeo\MaxMind\` (`src/MaxMind/`), deliberately
separate from `src/Providers/` — keeping "operational service around the
provider" structurally distinct from provider architecture, per
`ARCHITECTURE_FREEZE.md` §6.4 ("operational services are not providers").

- **`DatabaseManager`** — orchestrates download → extract → validate →
  atomic install → rollback → cleanup, and the `remove`/`restore`/
  `validate_installed` actions. Owns the managed directory
  (`{uploads}/universal-geo-context/maxmind/`) and the
  `universal_geo_maxmind_update_state` option.
- **`ArchiveExtractor`** — extracts the single expected
  `GeoLite2-Country.mmdb` entry from an untrusted `.tar.gz` via `PharData`,
  matching by exact basename only (never `extractTo()`), making path
  traversal structurally impossible rather than merely filtered.
- **`RedirectValidator`** — stateless, static-only (like `IpUtils`), gates
  the redirect-safe download flow's second hop: https-only, no userinfo,
  an exact-suffix host allowlist.
- **`UpdateLock`** — a cooperative, option-backed lock guarding
  `DatabaseManager`'s three action methods against admin/cron/CLI
  triggering concurrently, the same ownership shape `CircuitBreaker`
  already established.
- **`UpdateScheduler`** — WP-Cron registration and reconciliation for two
  new custom `cron_schedules` intervals (weekly, twice-weekly) matching
  GeoLite2 Country's real publication rhythm.
- **`Cli\DatabaseCommand`** — `wp universal-geo database status|download|validate|remove|restore`,
  the symmetric counterpart to `Cli\Command`.

**Redirect-safe download flow**: the Basic Auth header reaches
`download.maxmind.com` and only that host. `HttpTransport` gained two
methods, `get_redirect_location()` and `download()`, both confined to
`WordPressHttpTransport.php` like `get()` (`PrivacyGuardTest` rule 8): the
first hop carries credentials and only detects a redirect without
following it; `RedirectValidator` independently validates the target; the
second hop fetches the validated target with an empty headers array. No
third hop is ever attempted — a redirect loop is structurally impossible,
not merely detected. See `docs/SECURITY.md` for the full design rationale.

**Shared MaxMind credentials**: one canonical pair,
`maxmind_account_id`/`maxmind_license_key` (schema v5), consumed by both
the remote lookup provider and managed downloads —
`Plugin::resolved_maxmind_credentials()` (renamed from
`resolved_remote_credentials()`) resolves canonical constants → legacy
constants → canonical settings, in that order. The legacy
`remote_account_id`/`remote_license_key` fields remain in the schema as a
deprecated fallback/migration source; see `docs/COMPATIBILITY.md`.

**Path-resolution precedence** (`Plugin::resolved_maxmind_db_path()`) gains
a new tier between the settings path and WooCommerate auto-detection:
constant → settings path → `DatabaseManager::installed_path()` (when
`maxmind_managed_enabled`) → WooCommerce auto-detect → filter. The method
now returns `array{path, source}` instead of a bare string, so diagnostics
can report which tier resolved the effective path.

**Diagnostics/Site Health**: a new `maxmind_managed` report section, and
the fourth v1.x Site Health test, `universal_geo_maxmind_managed`, with
its own 14/30-day thresholds — distinct from the custom-path MaxMind
test's 30/90-day constants, since a managed database updates itself
automatically. Critical only when the overall resolved MaxMind source is
genuinely unavailable, not merely because the managed database itself is
stale — a working higher-precedence custom path covering the gap keeps
the test at `recommended`.

**Checksum verification**: implemented following live-account confirmation
during M6J acceptance. `DatabaseManager::fetch_checksum()` fetches
`CHECKSUM_ENDPOINT` (MaxMind's `.sha256` sidecar — the same
`download.maxmind.com` host, the same Basic Auth, the same redirect-safe
two-hop shape as the archive itself, confirmed to redirect through the
identical `RedirectValidator` host allowlist) and compares the reported
digest, via `hash_equals()`, against `hash_file()`'s own computation over
the downloaded archive — a gate that runs after a successful archive fetch
and before extraction, alongside (never instead of) the MMDB structural
validation (`Reader`-open + metadata + non-throwing lookup). Fails closed:
an unreachable checksum endpoint, a malformed response, an unexpected
filename, or a mismatch all abort the update with the previously
installed database left untouched. See `docs/SECURITY.md` for the
threat-model entry.

**`maxmind-db/reader`** is promoted from a dev-only to a production
Composer dependency — the local MaxMind provider's own feature is now
usable on any site, not only ones where WooCommerce happens to provide the
reader class.

## M7 — Admin navigation restructuring (v1.2.0)

Summarized in the same spirit as M2–M6 above. **No geo-resolution,
provider, cache, public API, or hook change** — an admin-only restructuring
of how existing services are presented and how POST handlers are organized.
See ADR-0007 and `docs/ARCHITECTURE_FREEZE.md`.

The monolithic `AdminScreen` is removed. `Plugin::init()` constructs and
registers these admin classes explicitly on the existing admin-only path
(`should_register_admin()`):

| Class | Responsibility |
|---|---|
| `Menu` | Top-level menu + six submenus |
| `OverviewPage` | Six-card dashboard; explicit Refresh now POST only |
| `DetectionPage` | Live Detection placeholder (M9); Simulation tab (M8) |
| `SimulationAdminBar` | Admin-bar indicator when simulation is active (M8) |
| `ProvidersPage` | Informational placeholder (M9) |
| `TrustedProxiesPage` | Trusted-proxy settings and trust actions |
| `DiagnosticsPage` | Full diagnostics report via `ReportRenderer` |
| `SettingsPage` | All other settings + managed MaxMind admin actions |
| `ReportRenderer` | Shared definition-list rendering for report sections |
| `AdminNotices` | PRG notice query-arg rendering |
| `FirstRunNotice` | First-run admin notice + dismiss handler |
| `RowLinks` | Plugin list action/meta links |

**Menu structure:** `add_menu_page()` with `dashicons-location-alt`,
capability `manage_options`, no hardcoded position. Submenu slugs are
centralized in `AdminPageSlugs`. A future **Logs** page slug is reserved in
the roadmap only — not registered in M7.

**Overview:** `DiagnosticsService::overview_sections()` supplies card data
without calling `ContextResolver::probe()`. Current resolution uses
`Plugin::context()` for the active request only. Overall health badge uses
`DiagnosticsService::worst_site_health_status()` — the worst of the four
existing Site Health verdict methods, including managed MaxMind.

**Legacy compatibility:** `Menu::maybe_redirect_legacy_page_url()` on
`admin_init` redirected `options-general.php?page=universal-geo-context` to
Overview and `tab=diagnostics` to Diagnostics. **Removed in M8 (v1.3.0).**

### M8 — Country simulation framework (v1.3.0)

Summarized in the same spirit as M2–M7 above. **No public API shape change,
no new provider, no geo-cache write, no settings schema migration** — an
administrator-only post-resolution filter plus signed session cookie. See
ADR-0008.

| Class | Responsibility |
|---|---|
| `SimulationCookie` | Signed HttpOnly cookie (`universal_geo_sim`) |
| `SimulationState` | Cookie read + per-request authorization |
| `SimulationAuthorization` | `manage_options` + logged-in gate |
| `SimulationContextFilter` | `universal_geo_context` @ priority 100 |
| `SimulationController` | Nonce-protected POST start/change/stop |
| `CountryCatalog` / `CountryNames` | ISO alpha-2 selector labels |
| `SimulationAdminBar` | Visible badge when simulation is active |

When active, the filter returns a new `VisitorContext` with
`source = simulation`, `confidence = 1.0`, `region_code = null`,
`is_cached = false`. Real resolution and cache entries are unchanged.
Multisite: per-site via WordPress `COOKIEPATH` / `COOKIE_DOMAIN`.

### Simulation lifecycle (architectural overview)

The simulation framework sits **after** real geo resolution and **before** any consumer reads context. It is frozen for v1.x — see `docs/ARCHITECTURE_FREEZE.md` §21 and ADR-0008.

```
Client Request
       ↓
IP Resolution          ClientIpResolver + TrustedProxies
       ↓
Providers              ContextResolver chain (Cloudflare → MaxMind → WooCommerce → Remote → Default)
       ↓
GeoCache               Read-only lookup; real resolution cached here
       ↓
ContextResolver        Produces VisitorContext (real evidence)
       ↓
universal_geo_context  SimulationContextFilter @ priority 100 (optional)
       ↓
VisitorContext         Final immutable value (real or simulated)
       ↓
Downstream Plugins     universal_geo_get_context(), hooks, WooCommerce extensions, etc.
```

**Stage notes:**

| Stage | Role | Simulation interaction |
|---|---|---|
| IP Resolution | Derives client IP from trusted headers | Unaffected; simulation does not alter IP |
| Providers | Produce country evidence from IP/signals | Unaffected; full chain still runs |
| GeoCache | Memoizes real resolution per configuration | Unaffected; simulation never reads/writes cache for override |
| ContextResolver | Selects first confident provider result | Unaffected; produces real context before filter |
| SimulationContextFilter | Post-resolution override | Replaces returned context when authorized + active cookie |
| VisitorContext | Public contract to consumers | Same shape; simulated values use frozen semantics |
| Downstream plugins | Policy and UX | Consume API normally; may inspect `source === 'simulation'` |
