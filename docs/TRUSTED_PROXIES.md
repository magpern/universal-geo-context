# Trusted Proxies — Deployment Recipes

**Status: M2 complete.** The trust-gate algorithm (`src/Http/ClientIpResolver.php`),
the effective trusted set (`src/Http/TrustedProxies.php`), and the two admin
settings (`trusted_proxies`, `trust_cloudflare`, under Settings → Geo Context)
are implemented and tested — the full 15-row spoofing matrix, both Cloudflare
modes, and the fail-closed default.

This is the single most important configuration decision a site operator
makes. Misconfiguring it — or leaving it at its default — either returns the
correct answer for the wrong reason or, on a stricter reading, silently
reports the reverse proxy's own location instead of the real visitor's. The
`universal_geo_trusted_proxy` Site Health test (Settings → Geo Context →
Diagnostics, and Tools → Site Health) exists to catch exactly that.

## Key principles

1. **Fail closed.** With no trusted proxies configured and the Cloudflare
   preset off, no forwarding header is ever read — `REMOTE_ADDR` is the
   answer, and `chain_verified` is `true` (there is no chain to fail
   verifying). This is the shipped default; a fresh install requires no
   configuration to be safe, only to be *accurate* behind a proxy.
2. **A configured trusted set that excludes the peer is different from an
   empty one.** If `trusted_proxies` has entries but the connecting peer
   isn't one of them, every forwarding header is ignored and
   `chain_verified` is `false` — a signal something is misconfigured
   (the proxy's own IP changed, a load balancer was added, etc.).
3. **Fixed header precedence, not configurable:** `CF-Connecting-IP` →
   `X-Forwarded-For` → `X-Real-IP`. `Forwarded` (RFC 7239), `True-Client-IP`,
   and `Client-IP` are never read for trust, in any configuration — their
   bare presence only ever appears in diagnostics.
4. **Right-to-left walk.** `X-Forwarded-For` is walked from right to left:
   the rightmost entry was appended by the nearest proxy and is the only one
   this process observed directly. Everything left of the first untrusted
   entry may be forged by the client. This is the opposite of
   `WC_Geolocation::get_ip_address()`'s left-to-right read, which is why this
   plugin never uses it. The whole header is rejected (not partially
   trusted) if it has more than 20 entries or any entry fails to parse as an
   IP.
5. **Chained Cloudflare mode.** The admin's `trust_cloudflare` toggle is an
   assertion that Cloudflare fronts the entire chain — even when a different
   trusted proxy (a load balancer, an nginx-based reverse proxy) sits
   between Cloudflare and PHP. Concretely: `CF-Connecting-IP` / `CF-IPCountry` are honored whenever
   the peer is trusted **and** `trust_cloudflare` is enabled, regardless of
   whether that peer is Cloudflare itself (direct mode) or an admin-trusted
   intermediary (chained mode) — both reduce to the same runtime check.
6. **Caveat:** on any topology where the origin is directly reachable
   (bypassing the proxy/CDN), chained-mode CF-* values are spoofable by
   anyone who discovers the origin's IP and sends requests straight to it.
   Locking the edge to proxy/CDN-only ingress (firewall rule, nginx
   allowlist) closes this gap; leaving it open is a documented, accepted
   risk, not a plugin bug.
7. **The public-address gate is always on, not a setting.** When the
   resolved client address is private/unroutable (e.g. a Docker bridge
   peer), IP-based providers (`WooCommerceProvider`, and `MaxMindProvider`
   from M3) self-guard and skip rather than looking up a meaningless
   address; header-based providers (`CloudflareHeaderProvider`) and the
   default-country fallback are unaffected.

## Deployment recipes

| Topology | Configuration |
|---|---|
| **Direct** (no proxy) | Leave `trusted_proxies` empty, `trust_cloudflare` off. Nothing to do. |
| **Single reverse proxy, same host** | Trust `127.0.0.1/32` and `::1/128`. Ensure the proxy overwrites, not appends, `X-Real-IP`. |
| **Cloudflare, PHP reached directly by Cloudflare** | Enable `trust_cloudflare` (direct mode — the peer is a Cloudflare address). `CF-Connecting-IP` / `CF-IPCountry` win. |
| **Cloudflare → containerized reverse proxy → application** | Trust the proxy's own network — e.g. the Docker bridge subnet the reverse proxy container is on. A correctly configured reverse proxy restores the real client IP into `X-Real-IP`, so that header supplies the client address. **Also enable `trust_cloudflare`** (chained mode) so `CF-IPCountry` can supply the country — the application container's peer is the reverse proxy, never Cloudflare directly, so direct mode alone would leave the Cloudflare provider permanently unavailable on this topology. Caveat: if the origin also answers directly (bypassing the proxy), chained-mode CF values are spoofable — lock the edge to proxy-only ingress or accept the documented risk. |
| **Docker / container proxies generally** | Trust the specific bridge subnet, never `0.0.0.0/0` (rejected outright by `Settings::sanitize()` — see below). Docker bridge subnets can be re-assigned on network recreation; pin the subnet via `ipam` in the proxy's compose file where possible, and treat drift as a deploy-time check, not a one-time setting. |
| **Cloud load balancer (ALB/GCLB/etc.)** | Trust the LB's documented CIDR ranges. Rely on `X-Forwarded-For` and the right-to-left walk. |
| **Multiple chained proxies** | List every hop's CIDR in `trusted_proxies`. The right-to-left walk consumes trusted hops one at a time; the first untrusted entry from the right is the client. |

## What `/0` rejection means in practice

`0.0.0.0/0` and `::/0` ("trust the entire internet") are rejected by
`Settings::sanitize()` and silently dropped from the saved list — this is
always a misconfiguration, never a legitimate deployment need, so it is
refused outright rather than merely discouraged. A narrower prefix that
still over-trusts (e.g. accidentally trusting `0.0.0.0/1`) is not similarly
blocked — operators are expected to trust specific, documented ranges from
the table above, not broad ones.

## Diagnosing a misconfiguration

Settings → Geo Context → Diagnostics shows, for the current request: the
masked peer and its public/private classification; which forwarding headers
are present; for each of the three trust-relevant headers, whether it was
trusted or ignored **and why** (`no_trusted_proxies_configured`,
`peer_not_trusted`, `cloudflare_preset_disabled`, or accepted); whether the
peer falls inside a configured entry (and which one) or a Cloudflare range;
and whether `CF-IPCountry` actually arrived this request (Cloudflare only
sends it when the zone's *Add visitor location headers* Managed Transform is
enabled — not something inspectable from the server side, only from a live
request). A first-run admin notice appears automatically when forwarding
headers are present but no trusted proxies are configured — the exact
condition the `universal_geo_trusted_proxy` Site Health test also flags as
critical. The Diagnostics tab additionally offers two one-click affordances
when applicable: **Trust this peer** (adds the observed peer's `/32` or
`/128`) and **Enable the Cloudflare preset** (shown only when the peer is
actually inside a Cloudflare range) — both explicit admin actions, never
automatic.

A CLI process (`wp eval`, WP-CLI commands from M5) has no HTTP request and
therefore no peer to verify — header-trust behavior can only ever be
confirmed through a real browser request against the Diagnostics tab.
