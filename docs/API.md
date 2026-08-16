# Public API

**Status: M5 complete.** The six functions and one value object below are
the frozen, permanent public surface as of v0.1.0 and remain unchanged
through M2, M3, M4, and M5 — the remote provider (M4) adds a new
resolution source (`source === 'remote'`, `confidence === 0.85`); M5 adds
WP-CLI access to the same six functions (`wp universal-geo context`) but
no new function, no new value object, and no change to
`universal_geo_api_version()` (still `1`).

## Public surface

Six functions and one value object:

```php
universal_geo_get_context(): UniversalGeo\Model\VisitorContext
universal_geo_get_country_code(): ?string
universal_geo_get_region_code(): ?string       // subdivision code when the winning provider supplies one (M13); else null
universal_geo_get_source(): string
universal_geo_get_confidence(): float
universal_geo_api_version(): int
```

All functions:
- Wrapped in `function_exists()` guard
- Never throw, never fatal
- Return a value in every context (including before boot, where they return the unknown context)

## Stability rules (M1)

1. Guard every call with `function_exists( 'universal_geo_get_context' )`.
2. Call at or after `plugins_loaded` priority 20 (the plugin boots at 10).
3. Only these types are stable: `VisitorContext`, `GeoCandidate`, and the two interfaces in `Contracts/`.
4. The `VisitorContext` FQCN is frozen. Adding properties is a minor version; removing or renaming is major.

## Consumer examples

Both examples below follow the stability rules above: guarded with
`function_exists()`, called at or after `plugins_loaded` priority 20 (here,
via a hook that already fires later — `woocommerce_init` and
`init` respectively — never earlier), and treat an unknown country
(`country_code === null`) as a normal, expected outcome to fall back from,
not an error to handle specially. Neither example constructs any internal
class of this plugin, calls anything outside the six functions above, or
assumes a specific provider resolved the answer — `source` is read only
for logging/debugging, never branched on.

### Universal Multicurrency — default currency by visitor country

```php
add_action( 'woocommerce_init', function () {
	if ( ! function_exists( 'universal_geo_get_country_code' ) ) {
		return; // Universal Geo Context not active — fall back to the existing default-currency logic.
	}

	$country = universal_geo_get_country_code();

	if ( null === $country ) {
		return; // Unknown country — the site's own configured default currency applies, unchanged.
	}

	// Map the ISO 3166-1 country code to a currency using this plugin's own
	// existing country => currency table, then apply it exactly as the
	// existing manual-selection path already does.
	universal_multicurrency_set_currency_for_country( $country );
} );
```

### AI Multilingual — suggested language by visitor country

```php
add_action( 'init', function () {
	if ( ! function_exists( 'universal_geo_get_country_code' ) ) {
		return; // Universal Geo Context not active — the site's existing language default applies.
	}

	$country = universal_geo_get_country_code();

	if ( null === $country ) {
		return; // Unknown country — no geo-based suggestion; the existing default/negotiated language stands.
	}

	// Map the country to one of this site's published languages using this
	// plugin's own existing country => language table; a country with no
	// mapped language is itself a normal miss, not an error.
	$language = ai_multilingual_language_for_country( $country );

	if ( null !== $language ) {
		ai_multilingual_suggest_language( $language );
	}
} );
```

## REST v1 (M14 / v1.9.0) — cache-safe visitor context

A second, **independent** public surface, added to close the one gap the six PHP
functions above cannot: full-page/CDN-cached HTML never re-runs PHP, so a browser needs
a way to ask for the *current* visitor's context after such a page has already loaded.
See `docs/adr/0012-cache-safe-visitor-context.md` for the full design record.

```
GET /wp-json/universal-geo-context/v1/context
```

- Public, anonymous — no authentication required, no `permission_callback` restriction.
- GET only. No parameters accepted (no `ip=` override).
- Response, always exactly these two keys, both always present:

  ```json
  { "country_code": "SE", "region_code": null }
  ```

  `country_code`: uppercase ISO 3166-1 alpha-2 string, or `null` when unknown.
  `region_code`: uppercase subdivision code string, or `null` when not resolved, unknown,
  or when simulation is active (region is always `null` under simulation, same as the PHP
  API).

### Versioning — independent from `universal_geo_api_version()`

This contract's versioning is carried entirely by the `/v1` URL segment, not by
`universal_geo_api_version()` (which governs the six-function PHP contract only and is
unaffected by this surface). **The `v1` key set is frozen, not additive** — no third key
will ever be added under `v1`; any change (add, remove, rename, retype) ships as `/v2`,
with `v1` continuing to be served unchanged for at least one minor release, mirroring the
PHP API's own deprecation-path discipline.

Deliberately not `VisitorContext::to_array()`: that method's shape (which includes
`source`, `is_cached`, `confidence`, `schema_version`) serves a different consumer
(`GeoCache`'s own round-trip). None of those fields are exposed here — see ADR-0012 for
the full field-by-field rationale.

### Simulation and the `X-WP-Nonce` requirement

An authorized administrator's active simulation (M8) is reflected through this endpoint —
but only when the request includes a valid `X-WP-Nonce` header
(`wp_create_nonce( 'wp_rest' )`). This is standard WordPress REST cookie-authentication
behavior, not UGC-specific: without a nonce, WordPress anonymizes the request before this
route's callback ever runs, so simulation will not appear. If you are building an
admin-facing testing tool that calls this endpoint, localize a nonce
(`wp_localize_script()` + `wp_create_nonce( 'wp_rest' )`) and send it as `X-WP-Nonce`.

### Caching

Every response carries `Cache-Control: no-store`. If your site uses a full-page cache or
CDN, confirm `/wp-json/universal-geo-context/v1/context` is excluded from HTML
page-caching rules — most WP cache plugins and CDNs already exclude all of `/wp-json/*`
by default; verify rather than assume.

### Disabling the route

There is no UGC-specific filter to disable this route. WordPress's own core `rest_endpoints`
filter already covers this need generically:

```php
add_filter( 'rest_endpoints', function ( $endpoints ) {
	unset( $endpoints['/universal-geo-context/v1/context'] );
	return $endpoints;
} );
```
