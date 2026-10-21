# Task 8 self-review

## Changed files and scope

Existing files changed: root `.gitignore` and `README.md`.

New files, all under `packages/redis`:

- `composer.json`
- `composer.lock`
- `docker-compose.yml`
- `phpstan.neon`
- `pint.json`
- `src/Client.php`
- `src/PhpRedisClient.php`
- `src/RedisConnector.php`
- `src/RedisStore.php`
- `src/NullStore.php`
- `tests/FakeClient.php`
- `tests/RedisStoreTest.php`
- `tests/IntegrationTest.php`
- `verification.md`

Read `CLAUDE.md` first. It delegates to `AGENTS.md`, which already contains
rule 15. Neither instruction file was edited. No core or CLI files were
edited. The initial working tree already contained changes in `.idea`,
`AGENTS.md`, core sources and tests, CLI verification, and corpus files.
Those changes were preserved. `git status --short` and `git diff --stat`
were reviewed before implementation and after verification. New files are
untracked, so the normal diff stat does not include their contents.

## Spec trace

Test names below refer to `RedisStoreTest.php` (unit) or `IntegrationTest.php`
(integration). Configuration requirements are verified by file inspection
and actual Composer checks rather than artificial configuration tests.

| Requirement | Proof | Status |
| --- | --- | --- |
| Read instructions first; preserve allowed scope | Initial reads and working-tree audit above | Done |
| Use PHP 8.5 and existing core Store namespace | Composer installation; both stores implement `Stero\Shield\Store\Store` | Done |
| Consistent package namespace | All production classes use `Stero\Shield\Redis`; Composer autoload and tests pass | Done |
| New package; ext-mbstring, ext-redis, core path `../core`, dev constraint | `composer.json`, successful installation and lock file; owner approved `dev-main` in place of `@dev` to pass strict Composer validation | Done |
| Pest, PHPStan max, Pint; copied configuration; four scripts | Copied core configuration; `composer check` runs all three gates | Done |
| Compose Redis 7 Alpine, port 6380 | Compose file binds loopback 6380 to 6379; runtime verification used Lerd as instructed | Done |
| Internal Client with eval, set, exists, del, ttl signatures | `Client.php`; FakeClient and native adapter implement it; PHPStan max | Done |
| PhpRedisClient over native Redis | All seven integration cases use the adapter | Done |
| Store constructed with Client and default/custom prefix | Unit: uses exact key names and clamps durations | Done |
| One atomic INCR Lua script, first-write EXPIRE | Integration: counts atomically and assigns a bounded TTL; script inspection | Done |
| Never extend an existing expiry | Integration: does not extend an existing expiry; mutation 1 | Done |
| Self-heal TTL -1 | Integration: self-heals a counter without expiry; mutation 5 | Done |
| Return script count; clamp windows below one | Unit: uses exact key names and clamps durations | Done |
| Ban uses SET EX, clamps seconds; second ban replaces expiry | Unit key/duration assertions; integration bans unbans and replaces ban expiry | Done |
| Exact `c:` and `ban:` namespaces; exists and del | Unit exact-key assertions; integration ban lifecycle; mutation 4 | Done |
| Every Store method catches Throwable and fails open | Four unit datasets; integration disconnected real client; mutation 3 | Done |
| First caught failure stops every later operation | Four unit datasets assert one call after invoking every method; mutation 2 | Done |
| A new instance retries | Each failure dataset creates another instance and asserts call count two | Done |
| No failure logging or key/IP logging | Static inspection and grep: no logging calls in new source | Done |
| NullStore no-ops, zero counter, false ban lookup | Unit: provides an inert null store | Done |
| Connector returns Redis or null, optional password, configurable timeouts | Real connection in integration setup; closed-port unit test; source inspection of auth and both millisecond conversions | Done |
| Closed-port connector completes within about 500 ms | Unit: returns null quickly for a closed port, measured using hrtime | Done |
| Integration enabled only by SHIELD_TEST_REDIS; useful skip message for each | Full check without variable: seven skips, each prints enabling command | Done |
| Random test prefixes and SCAN-only scoped cleanup | Integration hooks use random_bytes, SCAN with prefix pattern, DEL and finally close; no FLUSHALL | Done |
| Counts 1, 2, 3; TTL positive and at most window | Integration: counts atomically and assigns a bounded TTL | Done |
| Fifty sequential increments equal fifty | Integration: counts fifty sequential increments exactly, asserts each count | Done |
| Two prefixes cannot see each other's data | Integration: isolates prefixes, checks independent counters and ban visibility | Done |
| Real client pointed at closed port fails open | Integration: fails open over a disconnected real client, attempts connection to loopback port 1 | Done |
| mb functions with explicit UTF-8 for text processing | Endpoint parsing uses mb functions and validates UTF-8 before searching; store keys are opaque concatenations | Done |
| Failing tests before production implementation | Observed 7 failed/7 skipped, then enabled integration run with 14 failed on missing classes | Done |
| Five requested mutations, restoration, final check | Mutation table and summaries below | Done |
| Short README section; ignored generated files | README Redis section and four package-local ignore paths | Done |
| Rule 15 if absent | Already present in AGENTS.md through CLAUDE.md reference | Done |

## Mutation checks

Each mutation was made alone, its targeted test was run, and the original
source was restored before the next mutation. Byte-for-byte comparisons
confirmed restoration after each. A final `git diff --no-index` against a
saved baseline returned no differences.

| Deliberate change | Test that failed | Observed failure |
| --- | --- | --- |
| Run first EXPIRE unconditionally | does not extend an existing expiry | TTL 30 exceeded previous TTL 5; 1 failed |
| Disable all instance failure guards | fails open for each method and short-circuits all later calls | Five client calls instead of one; 4 failed |
| Throw from isBanned catch | fails open for each method and short-circuits all later calls, isBanned dataset | RuntimeException escaped; 1 failed, 3 passed |
| Use ban namespace for counters too | uses exact key names and clamps durations | `shield:ban:login` instead of `shield:c:login`; 1 failed |
| Remove self-heal branch | self-heals a counter without expiry | TTL remained -1; 1 failed |

## Static checks and test quality

- Pint passed and PHPStan max reported `[OK] No errors` in both packages.
- No unfinished markers, URL decoding calls, superglobal/input reads,
  framework imports, Mode references, em dashes or logging calls in new
  source/tests. Every PHP file declares strict types.
- New runtime dependencies are only those explicitly requested. The core
  package already requires psr/log; no core or CLI dependency was changed.
  Composer also installs the requested development tools' transitive dependencies.
- Repository-wide Mode search found existing Decision docblock references
  and the enum declaration, in addition to Engine, EngineConfig and
  SecurityEvent. No new Mode reference was introduced.
- `git diff --check` passed.
- No test lacks a behavioural assertion or would pass if the production
  implementation were deleted. No malformed-byte lookalikes are used.
  Endpoint configuration is the only parsed text here; Redis keys remain opaque.
- Hostile review found no further defect requiring a production change.

## Assumptions and limits

- Use existing `stero/shield` core package and its Store interface, PHP 8.5,
  and the consistent `Stero\Shield\Redis` namespace, as clarified by the owner.
- During the commit gate, Composer strict validation rejected the unbounded
  `@dev` requirement. The owner approved pinning it to `dev-main` instead.
- Store lifetime is one request. Long-running workers must create a fresh
  instance per request; the README documents this requirement.
- Integration verification used the running Lerd Redis service at
  `lerd-redis:6379`, as requested. Docker Compose was supplied but not run;
  Docker is not installed on this machine. The shared Redis service was not
  stopped merely to verify skips: the opt-in variable was omitted instead.
- Password handling and exact timeout assignments were inspected. The tests
  exercise a real successful unauthenticated connection and a refused connection;
  they do not simulate a blackholed network or an authenticated Redis server.
- Sequential count and Lua atomicity are proven as requested; no parallel
  load benchmark was added.

## Final checks after restoring all mutations

Redis check with real Lerd Redis: `Tests: 14 passed (125 assertions)`.
Pint passed; PHPStan: `[OK] No errors`.

Redis check without SHIELD_TEST_REDIS: `Tests: 7 skipped, 7 passed (36 assertions)`.
Pint passed; PHPStan: `[OK] No errors`.

Core check including corpus: `Tests: 261 passed (1407 assertions)`.
Pint passed; PHPStan: `[OK] No errors`.
