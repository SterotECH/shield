# Shield essentials

1. Shield is PHP intrusion detection and prevention middleware with one sensitive-path rule, not a full WAF; supported and tested on Laravel 13 only, PHP 8.5 or later.
2. Install with composer require stero/shield and php artisan shield:install; the installer never edits .env, so tell the user which flags to add and never commit their values.
3. Start SHIELD_ENABLED=true and SHIELD_MODE=detect; read events daily and wait a week of real traffic without false positives before enforce.
4. Run shield:status for health and shield:timing on day 2 and day 7 with SHIELD_TIMING=true; p95 inline must be under 5 ms.
5. Use ext-redis and dedicated Redis database 5, never database 0 or the cache database; NullStore cannot persist bans.
6. Preserve fail-open and expiring, escalating bans; only banWorthy honeypot findings cause bans, never rate-based signals.
7. Return 404 with Cache-Control: no-store, private for security blocks, never 403; never ban or block allowlisted, invalid or unknown IPs.
8. Never log bodies, cookies, Authorization headers, tokens or form values; use `php artisan shield:logs` in Laravel (15 rows per page, interactive filters and inspection). Use `--group` for incidents, `--json` for an event array, `--page` and `--per-page` for paging. All frameworks can use `vendor/bin/shield events|tail|stats --file=/path/to/events.jsonl`. Namespaces use `Stero\Shield\Runtime` and `Stero\Shield\Laravel`.
9. Restore client IPs in Nginx, never forwarded headers in PHP; keep middleware prepended globally before tenancy and sessions.
10. Never edit vendor or core defaults for false positives: sensitive_paths replaces the full default list; use allowlist entries for individual clients and skip_prefixes for exclusions; add rules only in Shield with attack and normal corpus files.

Repository gates: `npm ci` enables Husky; `npm run check:commit` checks
manifests and formatting; `npm run check:push` runs all package checks and
dependency audits. GitHub CI adds Redis integration tests and secret scanning.
Release Please proposes versions from Conventional Commits; merge its release
PR to publish after CI. `Release-As:` selects an exact version. Setup requires
the `RELEASE_PLEASE_TOKEN` Actions secret; see the repository README.

Full reference: vendor/stero/shield/resources/agent/shield.md
