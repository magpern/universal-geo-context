# Trusted Proxies — Deployment Recipes

**Status: M2 milestone. Bootstrap placeholder.**

This document will contain deployment recipes for common topologies.

See the approved Revision 3 plan, § 6, for the algorithm and recipes table:
/home/magpern/.claude/plans/you-are-the-lead-encapsulated-riddle.md

## Key principles

1. **Fail closed.** With no trusted proxies configured, no forwarding header is read.
2. **Right-to-left walk.** `X-Forwarded-For` is walked from right to left, so client-prepended forgeries are skipped.
3. **Chained Cloudflare mode.** The admin's `trust_cloudflare` toggle is an assertion that Cloudflare fronts the entire chain — even if other proxies sit between.
4. **Caveat:** On topologies where the origin is directly reachable, bypassing proxies, chained Cloudflare values can be spoofed.
