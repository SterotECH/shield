# Task 10b self-review

## Commit cleanup

The follow-up commit includes all reviewed project changes, including the
existing runtime style changes and the IDE schema mapping for Laravel's
Composer file. TimingCommand now receives Settings through method injection,
matching StatusCommand and removing its redundant service lookup and type
annotation. Agent documentation was updated for the new package layout.
The local skill's infrastructure reference explicitly forbids committing it
to the public repository; its own ignore rule keeps that file local while
the public skill documentation is included.

## Scope and changed files

Task files:

- `README.md`
- root `composer.json`
- root `composer.lock` (content hash only, explicitly approved during review)
- `tests/install/check.sh`
- `packages/laravel/.gitignore`
- `packages/laravel/composer.json`
- `packages/laravel/composer.lock`
- `packages/laravel/config/shield.php`
- `packages/laravel/phpstan.neon`
- `packages/laravel/pint.json`
- `packages/laravel/src/ShieldServiceProvider.php`
- `packages/laravel/src/ShieldMiddleware.php`
- `packages/laravel/src/Commands/InstallCommand.php`
- `packages/laravel/src/Commands/StatusCommand.php`
- `packages/laravel/src/Commands/TimingCommand.php`
- `packages/laravel/tests/Pest.php`
- `packages/laravel/tests/TestCase.php`
- `packages/laravel/tests/AdapterTest.php`
- this review

`CLAUDE.md` delegates to `AGENTS.md`; both were read and needed no edit.
No task edits were made to core, Redis, runtime or CLI. Changes to runtime's
`Guard.php` and `StatusReporter.php` appeared during the interrupted session
and were preserved as unrelated work. The existing untracked
`.agents/skills/shield/` directory was also preserved. Generated package
dependencies and caches are ignored by the new package's own `.gitignore`.

## Requirement trace

All test names below are in `tests/AdapterTest.php` unless stated otherwise.

| Requirement | Evidence | Status |
| --- | --- | --- |
| Read instructions first; port Task 9 reference | Read `CLAUDE.md` and its delegated instructions; read EduConect commit `0ec7c27c` | Done |
| New package and `Shield\Laravel` namespace | Package Composer PSR-4 mapping; PHPStan max; package tests | Done |
| Laravel 13 Illuminate support, HTTP and console | Composer requires each at `^13.0`; lock tests Laravel 13.38.0 | Done |
| Path repositories for other packages | Package Composer repositories for core, Redis and runtime | Done |
| Testbench, Pest, PHPStan max and Pint | Package Composer dependencies, scripts and configs; `composer check` | Done |
| Only adapter imports Illuminate | Scoped source search; no task edits to other package sources | Done |
| Tests before implementation, observed red | Initial run: 24 failures because the provider did not exist | Done |
| Same config keys and environment names | `maps every documented environment flag and uses the shield storage defaults` | Done |
| Disabled, detect and Redis database 5 defaults | Disabled test; `loads runtime settings with the Redis database default and environment override` | Done |
| Storage paths default under `storage/shield` | Environment mapping test and config review | Done |
| Merge config and publish under `shield-config` | Install publication test; provider review | Done |
| Root autoload, provider discovery and framework suggestion | Manifest test; install smoke check; root Composer review | Done |
| Provider is loaded and commands registered | Manifest/provider assertion; `registers the three Shield commands` | Done |
| Lazy Settings, Store, EventSink and singleton Guard wiring | Provider review; disabled construction counter; interface override test | Done |
| Settings uses `config('shield')`; EngineFactory builds Guard engine | Detect/enforce, allowlist and environment tests; provider review | Done |
| Disabled: no global middleware, Guard construction or files | `does not put middleware in the global stack or build a guard when disabled` | Done |
| Enabled middleware first | `prepends middleware only when enabled and auto-registration is on` | Done |
| Auto-registration false leaves stack untouched | `leaves a clean middleware stack alone when auto-registration is disabled` | Done |
| Framework request becomes NormalizedRequest with query/server, empty body, no trusted proxies, lowercase host | Normalisation/body test; spoof test; middleware review | Done |
| Guard before; request and Inspection stored in attributes | Detect/enforce and fresh-instance termination tests | Done |
| Block status, text body and runtime headers | `enforces 404 responses, no-store headers, and bans repeat probes` | Done |
| Unblocked response preserved | Detect direct test and actual Laravel HTTP kernel route test | Done |
| Detect normal request has no event | `records no event for a normal path` | Done |
| Enforce bans IP and blocks subsequent normal request | Enforce/repeat probe test with bound ArrayStore | Done |
| File/extra allowlists record findings without blocking or banning | `allows allowlisted addresses from file and extra config while recording findings` (two datasets) | Done |
| X-Forwarded-For and Cloudflare spoofing ignored | `does not trust forwarded client IP headers` | Done |
| Fail open on Store, EventSink and Guard failures | Throwing operation and throwing binding tests | Done |
| Downstream application exception runs once and propagates | `does not retry or swallow a downstream application exception` | Done |
| Skip prefixes create no events | `skips configured prefixes without recording` (three datasets) | Done |
| Terminate reads attributes on a fresh instance | Fresh-instance test, with a deferred fixture rule | Done |
| Deferred user ID string; null for guests/auth failures | Fresh-instance test with member, guest and throwing resolver datasets | Done |
| Termination catches Throwable | Middleware review; throwing binding/lifecycle tests | Done |
| Redis default and environment override; bounded connector spy | Config default/override test; `passes the configured Redis database to its connector` | Done |
| Install config, 0775 folder, flags and next steps; unchanged .env | Install test asserts publication, permissions and exact .env contents; command output review | Done |
| Status uses runtime reporter; healthy 0, NullStore 1, disabled 0 | Status health test with fake connection and bound stores | Done |
| Status prints warnings | `prints runtime warnings in the status command` | Done |
| Timing uses runtime report; PASS/FAIL at 5 ms p95 | Timing fixture test with 1 ms and 6 ms samples | Done |
| Timing missing/empty messages and exit 0 | Timing fixture test | Done |
| Existing unreadable timing file exits 1, matching reference | `returns failure when the timing file exists but cannot be read`; observed red then green | Done |
| README installation, flags, status, switching off, reading events, proxy restoration | Laravel section reviewed against each requested item | Done |
| Install check validates root provider/autoload metadata | `sh tests/install/check.sh` printed `ok` | Done |
| Every package Composer check | Core, Redis, runtime, CLI and Laravel checks passed | Done |
| Mandatory self-review and mutations | This document and results below | Done |

## Mutation results

Each production mutation was applied alone, the focused test failed, and the
change was restored. Restored lines were inspected in the new-file diff and
source, then the package check was rerun.

| Deliberate change | Test that failed | Result |
| --- | --- | --- |
| Remove the enabled condition from middleware registration | Disabled global stack/Guard test | Failed: middleware unexpectedly present |
| Trust the peer so RequestFactory accepts spoofed X-Forwarded-For | Forwarded client IP test | Failed: `198.51.100.1` instead of `203.0.113.9` |
| Save termination state on middleware properties and read those properties | Fresh-instance deferred user test, all three datasets | Failed: zero events instead of one |
| Append Shield to the global stack | First middleware test | Failed: ValidatePathEncoding was first |
| Default SHIELD_REDIS_DB to 0 | Default/environment override test | Failed: 0 instead of 5 |
| Omit runtime Cache-Control headers from block response | Enforce/repeat probe test | Failed: no `no-store` header |

The first database mutation run passed because the test inspected overridden
configuration. The test was strengthened to clear the environment and load
the package config directly. The repeated mutation then failed as required.
The append mutation uses the kernel's middleware property to append because
Laravel's kernel has no `appendMiddleware()` method; the final code uses its
supported `prependMiddleware()` method.

## Static checks and test quality

- `git diff --check`: clean.
- No task `TODO`, `Not implemented`, `urldecode`, request globals or
  `php://input` in adapter source or config.
- No framework imports added outside the adapter.
- No Mode class imports or mode-based security decisions in adapter source.
- No em dashes in new source or documentation.
- Root Composer adds the requested suggestion and discovery/autoload metadata,
  with no new required runtime dependency. New package dependencies match the
  requested tools and Illuminate components. No suppressions or PHPStan
  baseline were added.
- Every test has a behavioural assertion. No known test can pass with the
  relevant implementation deleted. The unreadable-file test uses an actual
  unreadable stream, and the spoof test supplies both real spoofed headers.
- The middleware has no instance state and does not catch downstream app
  exceptions. Its security decisions remain in Guard/Engine.

## Final check summaries

All six mutations were restored before these checks. Pint and PHPStan at
maximum level passed in every package.

| Package | Composer check test summary |
| --- | --- |
| core | `Tests: 261 passed (1407 assertions)` |
| redis | `Tests: 7 skipped, 7 passed (36 assertions)` |
| runtime | `Tests: 23 passed (109 assertions)` |
| cli | `Tests: 86 passed (365 assertions)` |
| laravel | `Tests: 34 passed (132 assertions)` |

The install smoke check printed `ok`. Package Composer strict validation
passed. Root strict validation initially exposed a stale root lock content
hash after adding provider metadata. The user approved refreshing this one
additional file; its only change is the content hash, with no dependency
changes.

## Assumptions, fixes and limits

- `_reference/` was absent. The reference is the EduConect commit and paths
  explicitly supplied earlier in this conversation; that checkout was read
  without edits.
- Disabled means middleware is not registered and Guard is not constructed.
  Config publication, commands and lazy container bindings remain available
  so installation and disabled status reporting work.
- `auto_register_middleware` is the one config key required by this task in
  addition to the Task 9 config. It defaults to true without adding an env name.
- The internal `shield.redis.connector` callable permits the requested
  database spy and status fixtures. Redis connection and database selection
  use the existing Redis classes with 100 ms timeouts.
- Guard is final. Guard failure coverage injects a throwing Guard binding;
  Store/Sink failures cover both construction and operation failures.
- Self-review fixed stale README claims, strengthened the Redis default test,
  and restored the unreadable timing file's reference exit code.
- Redis's seven integration tests remain skipped without SHIELD_TEST_REDIS.
  They are not reported as passing. No deployment or release was performed.
