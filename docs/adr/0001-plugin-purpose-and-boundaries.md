# ADR-0001: Plugin Purpose and Boundaries

**Status**: Accepted — retrospective documentation of an existing v1 architectural contract.

**Date**: 2026-08-15 (v1.8.1 — documented as part of governance backfill)

## Context

The Universal Geo Context plugin establishes a clear boundary between evidence (what the plugin knows) and policy (what consumers do with that knowledge). This boundary is a foundational design principle established in v1.0 and rigorously enforced through tests and architectural constraints throughout all v1 releases.

## Decision

The plugin resolves geography, never policy. Consumers own every decision.

### What the plugin does

1. Detects the client's geographic location using one or more configured providers (Cloudflare, MaxMind, WooCommerce, remote APIs, or a default country fallback).
2. Caches the derived context to avoid repeated provider calls.
3. Exposes the visitor context (country code, optionally region code, source, confidence, cache status) via a public API.
4. Provides diagnostics surfaces for administrators to understand the current resolution state.

### What the plugin does NOT do

1. Make policy decisions based on geography (no geo-blocking, no geo-targeting, no content adaptation).
2. Enforce geographic access control.
3. Store or transmit full client IP addresses beyond request scope.
4. Make assumptions about what downstream consumers should do with geographic data.

### Enforcement

This boundary is enforced by:

- **NoPolicyGuardTest** (`tests/unit/Guards/NoPolicyGuardTest.php`): static scan preventing any policy-like conditionals in the plugin's own code.
- **Public API freeze** (ADR-0004): the six public functions and `VisitorContext` value object expose evidence only, never policy.
- **Private internal boundary** (`@internal` markers): all decision-making logic is internal-only, inaccessible to consumers.

## Consequences

- **Consumers** have full agency: WordPress plugins, themes, or custom code that receives a `VisitorContext` can make their own policy decisions (caching rules, access control, content adaptation, etc.) without the plugin imposing constraints.
- **Audit clarity**: any geographic policy in a WordPress site can be traced to consumer code, not the plugin, simplifying security reviews and compliance assessment.
- **Portability**: the plugin's output is a generic geographic snapshot, reusable across any context where geographic knowledge is valuable.
