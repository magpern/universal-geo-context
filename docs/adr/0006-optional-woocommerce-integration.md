# ADR-0006 — Optional WooCommerce integration

## Status

Accepted (M2)

## Context

WooCommerce ships its own geolocation infrastructure
(`WC_Geolocation::geolocate_ip()`), typically backed by a MaxMind database
once an operator configures WooCommerce's own MaxMind integration. Universal
Geo Context needs a local-database provider from day one, and reusing
WooCommerce's existing infrastructure avoids bundling a second copy of a
MaxMind reader or duplicating database-management logic WooCommerce already
has.

At the same time, this plugin's own house rule (`CLAUDE.md`) requires it to
work on any WordPress site — it must have a functioning settings screen and
resolve *something* useful (at minimum, the configured default country) even
on a site with no WooCommerce installed at all. WooCommerce cannot be a hard
dependency.

Two integration shapes were considered: a dedicated integration/adapter
layer that specifically understands "this is the WooCommerce case," or
treating WooCommerce as an ordinary provider behind the same
`GeoProviderInterface` every other provider satisfies.

## Decision

1. **WooCommerce is a provider, not a special-cased integration layer.**
   `WooCommerceProvider` implements `GeoProviderInterface` exactly like
   `CloudflareHeaderProvider` and `DefaultCountryProvider` — `get_id()` /
   `is_available()` / `resolve(string $ip): ?GeoCandidate`, nothing more. No
   `Requires Plugins` header, no WooCommerce version constant, no parallel
   code path.
2. **`is_available()` is `class_exists('WC_Geolocation')`.** A missing
   database, a missing MaxMind integration configuration, or an absent
   license key are not detected here — they surface as an ordinary miss
   (`resolve()` returning `null` because the underlying country came back
   empty), which the diagnostics report explains rather than this method
   pre-empting.
3. **`resolve()` calls `WC_Geolocation::geolocate_ip($ip, false, false)`** —
   the resolved client IP explicitly, with both fallback parameters
   disabled. Passing the IP explicitly stops WooCommerce reading its own
   headers (`WC_Geolocation::get_ip_address()`'s left-to-right,
   unverified `X-Forwarded-For` read — the exact anti-pattern this whole
   plugin exists to avoid). The two `false` arguments disable
   `geolocate_via_api()` and `get_external_ip_address()`, guaranteeing zero
   outbound HTTP from this call, verified directly by a `pre_http_request`
   trap in the integration suite.
4. **Country only.** WooCommerce's MaxMind integration leaves `state` empty
   in every code path this plugin targets; `WooCommerceProvider` extracts
   only the `country` key and always returns a `null` region, regardless of
   what WooCommerce's own return array might one day contain in that key.
5. **A private static re-entrancy guard.**
   `woocommerce_geolocate_ip` and `woocommerce_get_geolocation` are public
   WooCommerce filters. If a site (deliberately or accidentally) wires this
   plugin's own resolution back into either filter, `WC_Geolocation`'s
   internal call would recurse into `WooCommerceProvider::resolve()` again.
   A `private static bool $in_flight` flag, set for the duration of the
   call and always cleared via `finally` (even if WooCommerce itself
   throws), makes the nested call return `null` immediately instead of
   recursing indefinitely. Verified directly by an integration test that
   wires the provider into `woocommerce_geolocate_ip` and confirms the call
   terminates.
6. **The provider self-guards a non-public resolved IP**, via
   `IpUtils::is_public()`, before ever calling into WooCommerce — the same
   pattern MaxMind's provider (M3) will follow, per ADR-0002 decision 8.
7. **Every value WooCommerce returns is treated as untrusted input.**
   Because the public filters in point 5 mean a third party can return
   anything from `woocommerce_get_geolocation`, the extracted `country`
   value is checked structurally (must be a non-empty string) before being
   wrapped in a `GeoCandidate`; real ISO 3166-1 validation happens
   uniformly afterward, in `ContextResolver`'s own loop, exactly like every
   other provider's output.

## Consequences

- No second MaxMind reader is bundled by this plugin; WooCommerce's own
  vendored copy (or lack thereof) is the only one in play for this
  provider. (M3's `MaxMindProvider` will document its own, separate soft
  dependency on a global `MaxMind\Db\Reader` class.)
- On a site with WooCommerce installed but its MaxMind integration
  unconfigured (no database, no license key), `WooCommerceProvider` is
  `is_available() === true` yet reliably returns `null` — an honest,
  documented "unavailable in practice" state the diagnostics report's
  WooCommerce section explains (integration configured? license key
  present? `.mmdb` file present?) rather than papering over.
- The provider makes zero outbound HTTP under all tested conditions,
  including when a hostile `woocommerce_get_geolocation` filter is also
  registered — confirmed by two separate integration tests.
- Because `WooCommerceProvider` takes no constructor arguments (unlike
  `CloudflareHeaderProvider`, which needs `ServerRequest` and
  `ClientIpResolver`), it has no dependency on the trust-boundary layer at
  all — its own trust story begins and ends with "is the resolved,
  already-trust-gated client IP public," using the same IP every other
  provider receives.

## Related

- ADR-0002 (trusted proxy model) — decision 8's provider self-guard
  pattern, reused here verbatim.
- ADR-0003 (provider architecture) — the frozen `GeoProviderInterface`
  contract this provider satisfies with no widening.
- `docs/SECURITY.md` — the "WooCommerce's own public geolocation filters"
  threat-model row.
