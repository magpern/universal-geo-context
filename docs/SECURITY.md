# Security Considerations

**Status: M2 milestone. Bootstrap placeholder.**

## Threat model

See the approved Revision 3 plan, § 17, for the threat model and mitigations:
/home/magpern/.claude/plans/you-are-the-lead-encapsulated-riddle.md

| Threat | Mitigation |
|---|---|
| Header spoofing → forged country | Fail-closed trust model; right-to-left XFF walk; peer verification |
| Forged country used for authorisation | Documented prohibition: geo is evidence, never authentication |
| Arbitrary file read via MaxMind path | Path constrained under `WP_CONTENT_DIR`; constant override requires wp-config |
| Hostile third-party providers | Every candidate re-validated; confidence assigned by resolver only; try/catch wrapper |
| SSRF via remote providers | wp_safe_remote_get only; hardcoded endpoint; timeouts; response caps |
| API-key disclosure | Boolean reporting only; constant override; never logged raw |
| Admin CSRF | manage_options + nonce on every write |
| Information disclosure via diagnostics | Masked IPs; `'private' => true` on Site Health fields |
| Cache poisoning | Per-site salt + settings signature in key |
| DoS via provider latency | Timeouts, circuit breaking, negative caching |

## Page caching

The plugin resolves per-request in PHP. If cached HTML varies by geography, every visitor gets the first visitor's country. **The plugin does not solve this** — documented with three strategies and whose job each is.

See docs/PRIVACY.md for persisted-data details and GDPR framing.
