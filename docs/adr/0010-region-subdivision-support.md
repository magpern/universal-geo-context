# ADR-0010: Region/subdivision support and provider ownership

Status: Accepted (M13 / v1.8.0)

## Context

`VisitorContext::region_code` has existed since M1 as one of the five
frozen properties, and `GeoValidator::region()` (M8-era code, syntactic-only
normalization) has existed as dormant, fully-tested infrastructure that no
provider ever fed real data. ADR-0003 decision 14 explicitly deferred region
support, including for a City-edition MaxMind database, to a later
milestone ("1.1" at the time; actually delivered here, in M13).
`docs/ARCHITECTURE_FREEZE.md` §15 separately pre-approved "Region support
(M6+): Adding `region_code` support via GeoLite2-City without removing the
Country provider" as a non-breaking v1.x evolution — this ADR exercises
that pre-approval and amends ADR-0003 decision 14 accordingly.

The milestone plan approved for M13 split the work into a guaranteed core
(13A: activate the dormant field via `MaxMindProvider`) and an
evidence-gated, optional extension (13B0 investigation → 13B1 managed
GeoLite2 City download support, authorized only by an explicit GO decision).
This ADR records both: the durable region contract decided by 13A, and the
13B0 findings and resulting GO/NO-GO decision.

## Decision

### Region contract

- `region_code` is the **subdivision-only** portion of a location (e.g.
  `CA`, `AB`, `BY`) — never the compound ISO 3166-2 form (`US-CA`). This was
  already the contract `GeoValidator::region()` implemented before this
  milestone (prefix-stripping plus a `^[A-Z0-9]{1,3}$` syntactic check); M13
  did not change it.
- No ISO 3166-2 membership table exists or was added. Validation remains
  syntactic only, exactly as `GeoValidator::region()`'s own docblock always
  scoped it.
- `region_code = null` whenever the winning provider has none, or the raw
  value fails the syntactic check. A null region is never invented or
  inferred, and never itself an error — it is the expected, common case.

### Provider ownership

- Exactly one provider is region-capable in v1.8.0: `MaxMindProvider`, when
  the local `.mmdb` it has open happens to be a City-edition database (via
  a manually configured `maxmind_db_path` or a WooCommerce-auto-detected
  path — independent of whether the managed downloader ever learns City).
  `MaxMindProvider::resolve()` reads `subdivisions[0].iso_code` from the raw
  reader record when present, defensively (any malformed shape resolves to
  `null`, never a warning or fatal), alongside the country it already read.
  The provider never inspects or cares which edition is open.
- The other four providers remain permanently region-`null`, for reasons
  already documented in their own source: `CloudflareHeaderProvider`
  (`CF-Region` is Cloudflare Enterprise-only), `WooCommerceProvider`
  (`WC_Geolocation`'s `state` key is empty in every code path this plugin
  uses), `ReferenceRemoteProvider` (queries MaxMind's Country-only web
  service — a different product from their City web service; switching
  endpoints is explicitly out of scope for this milestone), and
  `DefaultCountryProvider` (no region concept applies to a static
  fallback).
- **No cross-provider region enrichment.** `ContextResolver`'s first-success
  semantics were already architecturally incapable of combining one
  provider's country with another's region (the winning candidate's
  `region_code` travels with its `country_code`, both built from the same
  `GeoCandidate`); M13 did not change resolver ordering, memoization, or
  confidence assignment, and introduces no separate region confidence or
  region source.

### Cache, simulation, public API

- `GeoCache` already round-tripped `region_code` under the existing
  `SCHEMA_VERSION` before this milestone (already tested with non-null
  values). No cache-key, schema-version, or migration change was made or is
  needed. Old country-only cache entries remain valid and simply carry
  `region_code = null` until they expire naturally.
- Country simulation (M8, ADR-0008) is **unchanged**: `region_code = null`
  when simulation is active remains frozen per `ARCHITECTURE_FREEZE.md`
  §21.3/§22 and ADR-0008. This milestone deliberately did not add region
  simulation.
- The public API is unchanged: still six functions, still version `1`.
  `universal_geo_get_region_code()` already existed; no new helper was
  added. Its docblock (and `docs/API.md`) no longer claim "always null in
  v1" — everything else about the public surface is untouched.
- A missing region never affects operational readiness by itself. A
  country-only provider chain remains fully `ready`/`consumer_usable`; the
  existing M12 issue codes (`maxmind_unavailable`,
  `managed_database_missing`, `managed_database_stale`) already classify a
  broken/missing MaxMind database correctly regardless of edition, without
  a new region-specific issue code.

### Privacy boundary

Country and first-level subdivision only. City, postcode, latitude/longitude,
timezone, and metro code are all present in a raw City-edition MMDB record
and are all deliberately never read, even though the extraction code sits
one line away from data that would trivially expose them.

## 13B0 findings and GO/NO-GO decision

13B0 (managed GeoLite2 City feasibility investigation) gathered live
evidence against a real, already-configured MaxMind account on the plugin's
own dev environment, without ever exposing the account's credentials:

| Question | Finding |
|---|---|
| Country archive size (compressed) | 4,370,018 bytes (~4.2 MiB) |
| City archive size (compressed) | 32,445,267 bytes (~31 MiB) — well under the existing 64 MiB `DatabaseManager::MAX_ARCHIVE_BYTES` cap |
| City extracted `.mmdb` size | **65,590,693 bytes (~62.55 MiB)** — against the existing 64 MiB (67,108,864 byte) `ArchiveExtractor::DEFAULT_MAX_EXTRACTED_BYTES` cap, leaving only **~1.45 MiB (~2.3%) headroom** |
| Redirect behaviour | Identical two-hop pattern for both editions (same R2-hosted bucket host) — `RedirectValidator`'s host allowlist needs no change |
| Checksum sidecar | Identical `<sha256>  GeoLite2-{Edition}_{date}.tar.gz` format for both editions |
| Database metadata | Real production `databaseType` is `GeoLite2-City` (not `GeoIP2-City`, which is only the committed *test fixture's* value) — cleanly distinguishable from `GeoLite2-Country` via the same substring-containment approach `validate_file()` already uses |
| Credential entitlement | The same account/license key downloads both editions; no new credential type needed |
| Fake transport test seam | `tests/Support/FakeHttpTransport.php` is generic/edition-agnostic and already reusable for a City test double |

**Decision: NO-GO for v1.8.0.**

The extracted-size margin (~2.3% headroom under the current, shared,
security-relevant 64 MiB cap) is too thin to treat as safe without a
deliberate, separately-reviewed decision to introduce an edition-specific
limit — GeoLite2 City has historically grown release over release, and a
future monthly release could plausibly exceed the current cap within a
short time. Raising a download-safety cap is exactly the kind of decision
that should not be bundled into an evidence-gathering pass. Independently,
four of the nine approved GO criteria (atomic install/rollback across
editions, edition-switch safety, uninstall determinism across layouts, and
migration safety for existing installs) can only be fully demonstrated by
implementing and testing 13B1 itself — and the approved process requires
all criteria to be satisfied *before* that implementation begins, not
after. This is not a failure: per the approved plan, a NO-GO is a valid,
planned M13 outcome.

**What ships as a result**: 13A only, for the managed-database question.
No `Edition` value object, no settings schema bump (schema stays at 5), no
admin edition selector, and no change to the managed downloader's Country-only
behavior. Region support remains fully available today via a manually
configured or WooCommerce-detected City-edition database.

**What would need to change for a future GO**: a deliberate decision on
whether to raise `ArchiveExtractor::DEFAULT_MAX_EXTRACTED_BYTES` /
`DatabaseManager::MAX_ARCHIVE_BYTES` for a City edition specifically (and by
how much, tracking MaxMind's actual growth trend rather than a one-time
snapshot), plus an implemented and live-account-tested 13B1 covering the
remaining four criteria to the same bar M6J's original Country
implementation met.

## Consequences

- Region support ships in v1.8.0 as a purely additive activation of
  already-existing infrastructure — the smallest possible change that
  delivers the milestone's core value, with no new frozen-contract surface.
- Sites already running a City-edition MaxMind database (manual path or
  WooCommerce auto-detection) get subdivision-level region data immediately
  upon upgrading, with no configuration change required.
- Managed GeoLite2 City downloads remain a documented, deferred candidate
  for a future milestone, gated on the specific unresolved items above —
  not a vague "someday."
- `ReferenceRemoteProvider` switching to MaxMind's City web service remains
  explicitly out of scope; it would be a separate, larger decision changing
  a third-party contract this plugin does not currently depend on.

## Related

- ADR-0003 (provider architecture) — decision 14 amended by this ADR.
- ADR-0008 (country simulation framework) — confirmed unchanged; simulation
  remains country-only, region always `null` when simulated.
- `docs/ARCHITECTURE_FREEZE.md` §15 — the pre-approval this milestone
  exercises.
- `docs/ROADMAP.md` — M13/v1.8.0 entry.
