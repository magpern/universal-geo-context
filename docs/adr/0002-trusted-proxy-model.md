# ADR-0002 — Trusted proxy model

## Status

Accepted (M2)

## Context

Resolving a visitor's real IP address behind one or more reverse proxies,
CDNs, or load balancers is a security exercise, not a lookup exercise. A
naive implementation that reads `X-Forwarded-For` (or any other forwarding
header) unconditionally lets any client forge their apparent origin simply
by sending that header themselves — exactly the flaw already present in
`WC_Geolocation::get_ip_address()`, which reads `HTTP_X_REAL_IP` first and
`HTTP_X_FORWARDED_FOR` **left-to-right**, with no peer verification. This
plugin exists partly to avoid repeating that mistake.

The plugin also needs to support Cloudflare specifically: `CF-Connecting-IP`
and `CF-IPCountry` are only trustworthy signals when Cloudflare (or a chain
that genuinely terminates at Cloudflare) is actually in front of the
request. On topologies where a load balancer or reverse proxy sits between
Cloudflare and the application — a common containerized deployment shape —
the immediate TCP peer PHP sees is never Cloudflare itself, so a naive
"trust Cloudflare only if the peer is a Cloudflare address" rule would leave
the Cloudflare-header provider permanently unavailable on exactly the
topologies most likely to use it.

## Decision

1. **Fail closed by default.** With no trusted proxies configured and the
   Cloudflare preset disabled, every forwarding header is ignored
   unconditionally and `REMOTE_ADDR` is the answer. This is the shipped
   default — a fresh install is safe without any configuration.
2. **A configured-but-excluding trusted set is a distinct state from an
   empty one.** An empty effective trusted set means "no chain exists to
   verify" (`chain_verified = true`, trivially — the peer *is* the answer).
   A non-empty trusted set that excludes the current peer means "a chain
   was expected but this peer isn't part of it" (`chain_verified = false`)
   — every forwarding header is still ignored, but this is now a
   misconfiguration signal the Site Health test surfaces.
3. **Fixed header precedence, not configurable:** `CF-Connecting-IP` →
   `X-Forwarded-For` → `X-Real-IP`. `Forwarded` (RFC 7239), `True-Client-IP`,
   and `Client-IP` are never read for trust decisions in v1 — their bare
   presence is diagnostic information only.
4. **`X-Forwarded-For` is walked right-to-left**, consuming trusted hops
   from the rightmost entry inward; the walk stops (and that entry becomes
   the client) at the first untrusted entry. The whole header is rejected
   — not partially honored — if it has more than 20 entries or any entry
   fails to parse as an IP address.
5. **Two Cloudflare trust modes, unified into one runtime condition.**
   "Direct mode" (Cloudflare is the immediate peer) and "chained mode" (a
   different admin-trusted proxy sits between Cloudflare and the
   application, and the administrator has explicitly asserted that
   Cloudflare fronts the whole chain via the `trust_cloudflare` toggle) are
   conceptually distinct scenarios in the deployment recipes, but both
   collapse to the identical runtime check: the peer is in the effective
   trusted set **and** `trust_cloudflare` is enabled. This is computed once
   per request by `ClientIpResolver::cloudflare_header_trusted()` and
   injected into `CloudflareHeaderProvider`, never re-derived.
6. **The Cloudflare IP ranges are bundled, dated data — not a trust
   mechanism.** `TrustedProxies::CLOUDFLARE_RANGES` is a `const` array,
   updated by deliberately re-editing the file, never fetched at runtime.
   This keeps the default posture "zero outbound HTTP," keeps tests
   deterministic, and keeps the trusted set auditable (in the repository,
   dated, diffable). A `CloudflareTrustProvider` abstraction over this data
   was considered and rejected: the ranges are data feeding a trust
   decision, not an alternative trust *mechanism* — introducing a class and
   an interface here would add indirection without changing any behavior.
   If a second CDN's IP ranges are ever needed, that is the moment to
   extract an abstraction, not before.
7. **`/0` is rejected outright**, not merely discouraged. `0.0.0.0/0` and
   `::/0` ("trust the entire internet") can never be a legitimate
   deployment need; `Settings::sanitize()` drops such an entry rather than
   persisting it.
8. **The public-address gate is a behavior, not a setting.** IP-based
   providers (`WooCommerceProvider`, and `MaxMindProvider` in M3) each
   self-guard against a non-public resolved client address inside their own
   `resolve()` method, rather than `ContextResolver` pre-filtering by
   inspecting `ResolvedClientIp::$is_public`. `GeoProviderInterface` has no
   generic mechanism to declare "I need a public IP," so the check is
   pushed to the one place that actually knows it needs one.
9. **One CIDR matcher for the whole plugin.** `IpUtils::cidr_match()` is
   used both by `TrustedProxies` (admin-configured CIDRs, the Cloudflare
   preset) and internally by `IpUtils::is_public()`'s own non-public range
   table. No second CIDR implementation exists anywhere in the codebase.

## Consequences

- A single class (`ClientIpResolver`) owns the entire trust-gate algorithm;
  every other class that needs a trust fact (the Cloudflare provider, the
  diagnostics report, the Site Health test) asks it, rather than
  re-deriving any part of the logic.
- The 15-row spoofing matrix (unit-tested in
  `tests/unit/Http/ClientIpResolverTest.php`) is the definitive
  specification of this behavior; any future change to the trust gate must
  keep every row green.
- Chained Cloudflare mode is spoofable on any topology where the origin is
  also directly reachable, bypassing the trusted proxy. This is documented
  as an accepted, operator-manageable risk (lock the edge to proxy-only
  ingress) rather than something the plugin can close on its own — no
  application-layer check can distinguish "a request that really came
  through the trusted proxy" from "a request sent straight to the origin
  with forged headers" once both paths reach the same PHP process.
- Docker/container bridge subnets can be reassigned when a network is
  recreated without a pinned `ipam` block. A trusted CIDR that was correct
  at configuration time can silently become incorrect after infrastructure
  changes — this is an operational risk the diagnostics report's live peer
  display is designed to make visible, not one the plugin can prevent
  structurally.

## Related

- `docs/TRUSTED_PROXIES.md` — the deployment recipe table and diagnosis
  workflow this decision produces.
- `docs/SECURITY.md` — the threat model this trust boundary defends.
- ADR-0003 (provider architecture) — the interface decision
  (`GeoProviderInterface` has no `needs_ip()`-style flag) that shapes
  decision 8 above.
