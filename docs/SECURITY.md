# Security Considerations

**Status: M2 complete.** The trust-boundary threats (spoofing, header
precedence, provider hardening) below are implemented and tested. The
MaxMind and remote-provider rows are M3/M4.

## Threat model

| Threat | Mitigation |
|---|---|
| **Header spoofing → forged country** | Fail-closed trust model (§ see `docs/TRUSTED_PROXIES.md`): with no trusted proxies configured, every forwarding header is ignored; right-to-left `X-Forwarded-For` walk; the immediate peer must be verified before any header is read at all. |
| **Forged country used for authorisation** | Documented prohibition (the plugin's public API contract): geo is evidence, never authentication. Never use the resolved country as the sole basis for access control, pricing eligibility, or compliance exemptions. |
| **Arbitrary file read via a MaxMind database path** | M3. The settings field will be constrained under `WP_CONTENT_DIR`; a `wp-config.php` constant override will be the only escape hatch, unreachable from an admin-panel attacker. |
| **Hostile third-party providers** (including filter-registered ones via `universal_geo_providers`) | Every candidate is re-validated by `GeoValidator` before use, regardless of source; confidence is assigned only from `ContextResolver`'s own table, keyed by provider id — a provider (or a filter) can never mint its own confidence or a `1.0` score; every `resolve()` call is wrapped in `try/catch(Throwable)`, so a throwing provider degrades to a skip, never a fatal page view; the `universal_geo_context` filter's return value is itself re-validated (non-`VisitorContext`, or a structurally-valid-but-not-real country code, is discarded and the original context kept). |
| **WooCommerce's own public geolocation filters** (`woocommerce_geolocate_ip`, `woocommerce_get_geolocation`) | `WooCommerceProvider` calls `WC_Geolocation::geolocate_ip($ip, false, false)` with an explicit IP and both fallbacks disabled — no outbound HTTP, no left-to-right `X-Forwarded-For` re-read. A `private static` re-entrancy guard makes a nested re-entry (from a site wiring this plugin back into those same filters) return `null` immediately rather than recursing. |
| **SSRF via remote providers** | M4. The reference adapter will use `wp_safe_remote_get()` only, hardcode its endpoint host (no admin-supplied base URL), enforce short timeouts and a response-size cap. |
| **API-key disclosure** | M4. Boolean presence reporting only in diagnostics/Site Health; a `wp-config.php` constant override takes precedence over the stored option. |
| **Admin CSRF** | Every settings write (`handle_save_settings`, and the two diagnostics affordances `handle_trust_peer`/`handle_enable_cf_preset`) requires `manage_options` **and** a verified nonce (`check_admin_referer()`); the Diagnostics tab is read-only and still capability-gated. |
| **Information disclosure via diagnostics / Site Health** | Every address anywhere in the diagnostics report or the trusted-proxy Site Health test passes through `IpUtils::mask()` first — never a complete IP. The report also never contains a raw exception message from a provider's internals beyond the class name (see `universal_geo_provider_failed` in `docs/HOOKS.md`). |
| **Cache poisoning** | The derived-context cache key includes a per-site salt (never persisted in plaintext form usable to reverse it without brute force) and a settings signature (`config_sig`) covering every resolution-affecting setting — a configuration change cannot serve a context computed under the old rules, and the settings-save handler bumps the invalidation epoch on every save. |
| **DoS via provider latency** | Local providers (`CloudflareHeaderProvider`, `WooCommerceProvider`, and M3's `MaxMindProvider`) perform no network I/O. M4's remote provider will add timeouts, circuit breaking, and negative caching. |
| **Trusted-proxy misconfiguration** (the most likely way this plugin silently returns wrong data for every visitor) | Fail-closed default; the `universal_geo_trusted_proxy` Site Health test flags **critical** when forwarding headers are present, the peer is private, and no trusted proxies are configured; a first-run admin notice surfaces the same condition proactively; `docs/TRUSTED_PROXIES.md`'s recipe table covers every common topology. |
| **Chained Cloudflare mode is spoofable when the origin is directly reachable** | Documented, not silently mitigated: if a site's origin answers HTTPS directly (bypassing the CDN/proxy), an attacker who discovers that origin IP can forge `CF-*` headers through the trusted inner proxy. The chained-mode toggle is always an explicit admin assertion, never a default; the recipe in `docs/TRUSTED_PROXIES.md` recommends locking the edge to proxy-only ingress. |

## Provider hardening, stated as invariants

- A provider never receives write access to any plugin state; it returns a
  `GeoCandidate` (country + region facts only) or `null`, nothing else.
- No provider — built-in or filter-registered — can assign its own
  confidence. `ContextResolver`'s confidence table is keyed by provider id;
  an unrecognized id (a third-party provider registered via the
  `universal_geo_providers` filter) receives a fixed, capped, "unlisted"
  confidence rather than being trusted implicitly.
- A provider throwing any `Throwable` is caught at the resolver loop and
  treated exactly like a miss — the chain continues to the next provider.
  This is the single most important operational property of the resolver:
  no third-party provider bug can fatal a page view.
- `CloudflareHeaderProvider`'s availability is gated entirely on the trust
  verdict `ClientIpResolver` computes once per request — it never re-derives
  or duplicates that decision, so there is exactly one place in the codebase
  that decides whether a Cloudflare header may be trusted this request.

## Page caching

The plugin resolves per-request in PHP. If cached HTML is varied by
geography, every visitor sees the first visitor's country baked into the
cache. **The plugin does not solve this and does not claim to.** Three
strategies exist, and each belongs to the site operator or the consuming
plugin, not to Universal Geo Context itself: restrict geo-dependent output
to never-cached surfaces (admin-ajax, a logged-in-only area); vary the edge
cache key on the resolved country (an operator-level CDN configuration);
resolve client-side via a REST endpoint (sketched for a future release, not
shipped in v1).

## Zero front-end footprint

No cookies, no sessions, no JavaScript, no CSS, no output on the front end.
Resolution happens entirely server-side, on demand.

See `docs/PRIVACY.md` for the full persisted-data inventory and GDPR framing,
and `docs/TRUSTED_PROXIES.md` for the trust-boundary deployment recipes this
threat model depends on.
