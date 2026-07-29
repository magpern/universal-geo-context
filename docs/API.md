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
universal_geo_get_region_code(): ?string       // always null in v1
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
