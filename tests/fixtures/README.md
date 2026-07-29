# Test fixtures

`GeoIP2-Country-Test.mmdb` and `GeoIP2-City-Test.mmdb` are MaxMind's own
public test databases, downloaded verbatim from
`https://github.com/maxmind/MaxMind-DB` (path `test-data/`), commit-pinned
implicitly by this file's own git history.

**Licensing decision (M3 architecture report §9 R-M6):** the `maxmind/MaxMind-DB`
repository is licensed Apache License 2.0 in full (confirmed via the GitHub
API's license detection, no separate `LICENSE` file under `test-data/`
overriding it), which permits redistribution. Committing these two small
(~20 KB each) binary fixtures is therefore permitted and is the approach
taken here, rather than a CI-time fetch mechanism.

Known-answer source data (`source-data/GeoIP2-Country-Test.json` and
`source-data/GeoIP2-City-Test.json` in the same upstream repository) confirms
`214.78.120.0/22` resolves to country `US` in both databases (with
subdivision `CA` in the City database — ignored by this plugin, which reads
`country.iso_code` only), and that `8.8.8.8` is absent from the small test
tree (a genuine miss, not present in either fixture).
