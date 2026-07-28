# Hooks and Extension Points

**Status: M2 complete.** Six of the seven v1 hooks are shipped (M1 shipped
two; M2 adds four). `universal_geo_maxmind_db_path` remains M3.

All hooks use the `universal_geo_` namespace; filters are nouns, actions are
`{subject}_{past-participle}`.

| Hook | Type | Since | Fires | Purpose |
|---|---|---|---|---|
| `universal_geo_context` | Filter | 0.1.0 | Once per request, at the `Plugin::context()` boundary — including on derived-cache hits. | Modify the final resolved `VisitorContext` before any consumer sees it. Runs first and gets the last word on the value. |
| `universal_geo_context_resolved` | Action | 0.1.0 | Immediately after the filter above, receiving the already-filtered value. | React to resolution (read-only — cannot change the value). |
| `universal_geo_providers` | Filter | 0.2.0 | Once, when `Plugin::init()` builds the object graph (`plugins_loaded` priority 10). | Reorder, remove, or add providers. **The filtered array's order IS resolution order** — this is the advanced-customization surface that replaced a `provider_order` setting. |
| `universal_geo_default_country` | Filter | 0.2.0 | Once, at graph build, before `DefaultCountryProvider` is constructed. | Override the configured fallback country. |
| `universal_geo_trusted_proxies` | Filter | 0.2.0 | Lazily, on the first trust-gate evaluation each request (inside `ClientIpResolver`), not at graph build. | Extend the trusted-proxy CIDR set. **Additive only** — starts from an empty array and is unioned with the admin's own configuration; it can never shrink or replace what the admin configured. |
| `universal_geo_provider_failed` | Action | 0.2.0 | Whenever a provider's `resolve()` throws, from inside the resolver loop (`ContextResolver::resolve()` or `::probe()`). | React to provider failures — logging, alerting, metrics. |
| `universal_geo_maxmind_db_path` | Filter | — (M3) | Not yet implemented. | Override the MaxMind database path. |

## Signatures

```php
// Filters
apply_filters( 'universal_geo_context', VisitorContext $context ): VisitorContext
apply_filters( 'universal_geo_providers', GeoProviderInterface[] $providers ): GeoProviderInterface[]
apply_filters( 'universal_geo_default_country', string $default_country ): string
apply_filters( 'universal_geo_trusted_proxies', string[] $cidrs ): string[]

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
- `universal_geo_providers` and `universal_geo_default_country` fire once,
  at `plugins_loaded` priority 10 (inside `Plugin::init()`) — a consumer
  registering these filters must do so at file scope or another hook that
  runs before priority 10, not inside `plugins_loaded` itself at a later
  priority. `universal_geo_trusted_proxies` fires lazily, on first
  resolution, so registering it any time before that first call works.
