# Status, roadmap and gotchas

Snapshot date: 2026-10-22. Update after every milestone.

## Done (as reported by the user, or assumed because he moved on)

- Task 1: helpers on `Severity`, `Decision`, `NormalizedRequest`.
- Task 2: `ArrayStore` (injectable clock, entries expire exactly at `expiresAt`), `InMemorySink` (with `reset()`), `SecurityEvent::toArray()`, `JsonLinesSink` (flags `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE`, no `JSON_THROW_ON_ERROR`, `LOCK_EX`, fail open). Confirmed complete.
- Task 3: `RequestFactory` (path decoded once with `rawurldecode`, trusted-proxy-aware client IP, body cap with `mb_strcut`).
- Task 4: `SensitivePathRule` plus corpus files.
- Task 5: `Engine`, `IpMatcher` (shared IP and CIDR matcher), escalating bans, allowlist, unbannable IPs, PHPStan skeleton ignores removed.
- Task 6: full `CorpusTest`, `demo/index.php`, `DemoFileStore`.
- Task 7 and 7b: Termwind CLI viewer (`shield tail`, `events`, `stats`), theme-safe palette with WCAG 4.5:1 contrast test.
- Task 8: Redis store package (`RedisStore`, `NullStore`, `RedisConnector` with database selection).
- Task 8b: root `composer.json` named `stero/shield`, LICENSE (MIT), SECURITY.md, `.gitattributes`, README, install check. Repo made public, submitted to Packagist, tagged `v0.1.0`. Confirm Packagist lists it if unsure.
- Task 9: EduConect integration written inside EduConect (config, provider, middleware, allowlist loader, `shield:status`, `shield:timing`), switched off by default. Reported complete.
- Task 10a: `packages/runtime` implemented and committed. Runtime checks pass (23 tests, 109 assertions).
- Task 10b: Laravel 13 adapter implemented. Adapter checks pass (34 tests, 132 assertions), all six requested mutations were caught, and the install smoke check passes. No release has been tagged.

## Next

1. Review the Laravel adapter changes, then release `v0.2.0` when requested.
2. Task 11: EduConect drops its local Shield files and the `prepend` line in `bootstrap/app.php`, runs `composer update stero/shield`. Env names are unchanged, so no server change. Verify `shield:status` is healthy and a `/.env` event looks identical. Rollback is flipping the release symlink.
3. Task 12: usage tracking inside EduConect (not in Shield). See `infrastructure.md`, section "Usage tracking design".
4. Week of observation in detect mode: `shield:timing` on day 2 and 7 (inline p95 under 5 ms), read events daily, every false positive becomes a corpus file, compare with the old probe-ban middleware. Then switch `sensitive-path` to enforce and retire the old middleware only after Shield matched it for a full week.
5. Only after real data: new rules (see `detection.md`), body inspection, a latency load test (k6, budget p95 5 ms added).
6. Later: adapters for other frameworks, one folder each, about 100 lines because `runtime` does the work. Ask the user which frameworks he touches.

## Gotchas that already happened

- Nginx rejects a second `real_ip_header`. The server already had `/etc/nginx/conf.d/cloudflare-real-ip.conf` (with hyphens) from June: one `real_ip_header CF-Connecting-IP;` and the Cloudflare ranges. Never generate a duplicate. The refresh script now only writes the app allowlist and warns on drift.
- The folder on the server is `educonnect` (two n), not `educonect`. Use the real path from the user.
- A cron job must never rewrite nginx config unattended.
- `JSON_THROW_ON_ERROR` in the sink loses events for malformed UTF-8, which is exactly what attackers send.
- Expiry boundary: active only while `now < expiresAt`, to match Redis behaviour.
- Cloudflare "Pseudo IPv4" must be Off or "Add header", never "Overwrite headers". It is Off by default.
- Behind Cloudflare, PHP's `REMOTE_ADDR` is a Cloudflare address unless Nginx restores the visitor IP. If it is wrong, one ban blocks thousands of users. The first staging check: the `ip` in a test event must equal the visitor's public IP.
- `0.0.0.0` means "unknown client" and must never be banned.
- Agents mark tests as passing for the wrong reason. Always demand mutation checks (break the code, confirm a test fails) and real bad bytes in UTF-8 tests.
- The Task 6 prompt was cut off once and had to be re-sent in full.
- `cache:clear` flushes the Redis database it uses, hence database 5 for Shield.
- A path repository in EduConect's `composer.json` would break deploys (the runner has no local Shield folder). Install from Packagist only.
