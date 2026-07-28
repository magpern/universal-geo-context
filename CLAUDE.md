# CLAUDE.md — Working agreement

## Core invariants

1. The plugin resolves geography, never policy. Consumers own every decision.
2. Raw client IPs are transient locals, never persisted as plain text.
3. Trusted proxies are a security boundary; the default is to trust nothing.
4. Deactivation removes nothing; uninstall is all-or-nothing.
5. No provider failure fatals a page view.
6. One approved milestone at a time (house rule).
7. Tests accompany every feature.

## Code rules

- **Generic product only.** No site names, client names, hosting domains, or any deployment-specific branding in committed files — code, comments, docs, tests, workflows, composer metadata, commit content. The plugin must work on any WordPress site and be publishable as-is. Check before every commit.
- **Fully self-contained repo.** This directory is its own git repository (GitHub: `magpern/universal-geo-context`), independent of whatever tree it happens to be checked out in. Never reference paths outside the repo from committed code, and never commit this project's files into any surrounding repository.
- Naming: namespace `UniversalGeo\`, prefix `universal_geo_`, textdomain `universal-geo-context`, constants `UNIVERSAL_GEO_*`.
- Minimum versions: see `docs/COMPATIBILITY.md` (enforced by version-header parity tests in M5).
- No secrets in this repo, ever.

## Architecture

Approved Revision 3 architecture plan (§27 audit findings applied, verdict: APPROVED WITH REQUIRED CORRECTIONS):

- Two independent abstractions: `ClientIpResolverInterface` (who is asking) and `GeoProviderInterface` (where that IP is).
- One central resolver owns every judgement: ordering, validation, normalization, confidence, source attribution, caching.
- The IP is a local variable, discarded when the method returns.
- Public API: six functions + `VisitorContext` value object (`@internal` boundary around everything else).
- Guard tests: four total. M1 ships `NoPolicyGuardTest` + `ImmutabilityGuardTest`.

## Workflow

- Checks: `composer phpcs`, `composer test:unit`, `composer test:integration` (integration needs MySQL and `tests/bin/install-wp.sh`; see `.github/workflows/ci.yml` for the reference setup).
- Machine-specific dev-environment notes belong in `CLAUDE.local.md` (gitignored) — never in this file.
- Release: bump the `Version:` plugin header, `UNIVERSAL_GEO_VERSION`, and version in `docs/COMPATIBILITY.md` together; tag `vX.Y.Z` matching the header, push the tag only when explicitly approved by the Product Owner. The Release workflow builds and publishes the installable zip.

## Deferred to later milestones

- `src/api.php` (M1 bootstrap has no public functions yet)
- Region support (M1.1)
- REST API (M1.1)
- WP-CLI commands (M5)
- Site Health tests (M2+)
- MaxMind integration (M3)
- Remote providers (M4)
- Translation infrastructure (M5)
