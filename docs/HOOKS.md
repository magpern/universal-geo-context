# Hooks and Extension Points

**Status: M5 complete.** All seven v1 hooks are shipped (M1 shipped two, M2
added four, M3 added the seventh and last: `universal_geo_maxmind_db_path`).
M4 adds no new public hook — the remote provider is wired entirely through
existing surfaces (`universal_geo_providers` sees it in its fixed slot;
`universal_geo_provider_failed` fires for its failures identically to any
other provider). M5 adds no new public hook either — WP-CLI and Site
Health's `debug_information` both consume `DiagnosticsService::report()`
directly, not a hook; the seven-hook v1 surface remains closed.

All hooks use the `universal_geo_` namespace; filters are nouns, actions are
`{subject}_{past-participle}`.

| Hook | Type | Since | Fires | Purpose |
|---|---|---|---|---|
| `universal_geo_context` | Filter | 0.1.0 | Once per request, at the `Plugin::context()` boundary — including on derived-cache hits. | Modify the final resolved `VisitorContext` before any consumer sees it. Runs first and gets the last word on the value. |
| `universal_geo_context_resolved` | Action | 0.1.0 | Immediately after the filter above, receiving the already-filtered value. | React to resolution (read-only — cannot change the value). |
| `universal_geo_providers` | Filter | 0.2.0 | Once, when `Plugin::init()` builds the object graph (`plugins_loaded` priority 10). | Reorder, remove, or add providers. **The filtered array's order IS resolution order** — this is the advanced-customization surface that replaced a `provider_order` setting. |
| `universal_geo_default_country` | Filter | 0.2.0 | Once, at graph build, before `DefaultCountryProvider` is constructed. | Override the configured fallback country. |
| `universal_geo_trusted_proxies` | Filter | 0.2.0 | Lazily, on the first trust-gate evaluation each request (inside `ClientIpResolver`), not at graph build. | Extend the trusted-proxy CIDR set. **Additive only** — starts from an empty array and is unioned with the admin's own configuration; it can never shrink or replace what the admin configured. |
| `universal_geo_provider_failed` | Action | 0.2.0 | Whenever a provider's `resolve()` throws, from inside the resolver loop (`ContextResolver::resolve()` or `::probe()`). | React to provider failures — logging, alerting, metrics. As of 0.3.0 this same event also (separately) records a scrubbed copy into `ProviderHealthStore`; this action's own payload is unchanged. |
| `universal_geo_maxmind_db_path` | Filter | 0.3.0 | Once, at graph build (`Plugin::build_graph()`, inside `resolved_maxmind_db_path()`), after the settings/WooCommerce-derived candidate has been resolved and containment-checked. **Not fired at all when `UNIVERSAL_GEO_MAXMIND_DB` is defined** — the constant wins outright and this filter is never consulted that request. | Override the effective MaxMind database path — a code-level surface, unlike the setting/WooCommerce sources, not constrained under `WP_CONTENT_DIR`. |

## Signatures

```php
// Filters
apply_filters( 'universal_geo_context', VisitorContext $context ): VisitorContext
apply_filters( 'universal_geo_providers', GeoProviderInterface[] $providers ): GeoProviderInterface[]
apply_filters( 'universal_geo_default_country', string $default_country ): string
apply_filters( 'universal_geo_trusted_proxies', string[] $cidrs ): string[]
apply_filters( 'universal_geo_maxmind_db_path', string $path ): string

// Actions
do_action( 'universal_geo_context_resolved', VisitorContext $context ): void
do_action( 'universal_geo_provider_failed', string $provider_id, string $reason ): void
```

## Notes

- **Hooks carry `VisitorContext` only** at the context boundary. No trace
  object, no masked IP, no provider internals ever cross a hook — the
  `VisitorContext` public type structurally cannot carry an IP (it has no
  such field).
- `universal_geo_context`'s return value is **re-validated**: a non-object,
  wrong class, or a country code that is structurally valid but not a real
  ISO 3166-1 entry (e.g. `'XX'`) causes the filtered value to be discarded —
  the original, pre-filter context is kept, and `_doing_it_wrong()` fires.
- `universal_geo_providers`'s return value is re-validated **per element**:
  any entry that isn't a `GeoProviderInterface` instance is dropped (with
  `_doing_it_wrong()`), so a filter can never hand the resolver's
  constructor something that would otherwise throw. A non-array return is
  discarded wholesale and the original provider list is kept.
- `universal_geo_default_country`'s return value is re-sanitized to the same
  shape `Settings::sanitize()` itself enforces (uppercase, empty or exactly
  two ASCII letters); a non-string or wrong-shape result falls back to the
  configured settings value. Real ISO 3166-1 membership is checked later, by
  `GeoValidator`, inside the resolver loop — not here.
- `universal_geo_trusted_proxies` is deliberately **additive-only**: the
  filter receives an empty array and its result is unioned with
  `TrustedProxies`' own configured CIDRs and the Cloudflare preset, never
  substituted for them. A filter can extend trust; it can never silently
  narrow what the administrator configured.
- `universal_geo_provider_failed`'s `$reason` is the failing exception's
  class name and message only — never a client IP, and never any other
  provider internals. A well-behaved provider must not put a raw IP address
  in its own exception messages either; the resolver has no generic way to
  scrub one out of arbitrary text.
- Providers injected via `universal_geo_providers` receive the "unlisted"
  confidence (`0.85`) unless their `get_id()` matches one of the built-in
  provider ids in `ContextResolver`'s confidence table — a filter cannot
  mint a higher-confidence source than the resolver's own table allows.
- `universal_geo_providers`, `universal_geo_default_country`, and (as of
  0.3.0) `universal_geo_maxmind_db_path` fire once, at `plugins_loaded`
  priority 10 (inside `Plugin::init()`) — a consumer registering these
  filters must do so at file scope or another hook that runs before
  priority 10, not inside `plugins_loaded` itself at a later priority.
  `universal_geo_trusted_proxies` fires lazily, on first resolution, so
  registering it any time before that first call works.
- `universal_geo_maxmind_db_path`'s return value is hardened identically to
  `universal_geo_default_country`: a non-string result is discarded with
  `_doing_it_wrong()` and the pre-filter path (the settings/WooCommerce-
  derived candidate, or `''` if neither applied) is kept. The filter is a
  code-level override and is **not** constrained under `WP_CONTENT_DIR` the
  way the settings and WooCommerce-derived candidates are — it is trusted
  the same way the `UNIVERSAL_GEO_MAXMIND_DB` constant is, since both are
  only reachable by someone who can already run PHP on the site. The
  constant takes precedence over this filter entirely: when
  `UNIVERSAL_GEO_MAXMIND_DB` is defined as a non-empty string, this filter
  is never invoked that request.
