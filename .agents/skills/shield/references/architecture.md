# Architecture

## Contents
1. Repository layout
2. Request flow
3. Core: classes and contracts
4. Engine algorithm
5. Redis store
6. Runtime
7. Laravel adapter
8. CLI viewer
9. Event schema

## 1. Repository layout

    shield/                     public repo, root package stero/shield
      composer.json             what users install: autoload Stero\Shield, Stero\Shield\Redis,
                                Shield\Runtime, Shield\Laravel; extra.laravel.providers;
                                require php + ext-mbstring; suggest ext-redis, laravel/framework
      packages/core             pure PHP engine
      packages/redis            RedisStore, NullStore, RedisConnector
      packages/runtime          Settings, AllowlistLoader, EngineFactory, Guard, timing, status
      packages/laravel          service provider, thin middleware, config, commands
      packages/cli              Termwind viewer (own dependencies, left out of installed copies)
      tests/corpus/attacks      one JSON request per file
      tests/corpus/normal       one JSON request per file
      tests/install/check.sh    installs the root package from a path repo and runs a smoke test
      CLAUDE.md                delegates to AGENTS.md
      AGENTS.md                agent instructions
      LICENSE (MIT), SECURITY.md, README.md, .gitattributes (export-ignore for cli, tests, demo)

Sub-package names (dev only): `stero/shield-core`, `stero/shield-redis`, `stero/shield-runtime`, `stero/shield-laravel`, `stero/shield-cli`. Nobody installs them.

## 2. Request flow

Host app builds a `NormalizedRequest` (core `RequestFactory` for plain PHP, adapter for frameworks). `Guard::before` runs `Engine::inspect(request, Phase::Inline)` before the app. If the decision is blocked, the adapter returns a text/plain "Not Found" with the decision's status (404) and `Cache-Control: no-store, private`. Otherwise the app runs. After the response is sent (Laravel `terminate()`), `Guard::after` runs `Phase::Deferred` with the user id attached.

- `Phase::Inline`: very cheap checks only (path compare, one counter). Budget: p95 added latency under 5 ms.
- `Phase::Deferred`: heavy work (body scanning, big pattern sets) after the response is sent. Never blocks the current request.

## 3. Core (namespace Stero\Shield)

- `Mode` enum: `Detect`, `Enforce`. Only `Engine` reads it.
- `EngineConfig` (readonly): `mode`, `banSeconds` 86400, `maxBanSeconds` 604800, `allowlist` (list of IPs or CIDR), `blockStatus` 404.
- `Http\NormalizedRequest` (readonly): `method`, `path` (decoded once, no query, `//` collapsed, leading `/`), `query`, `headers` (lowercase names), `body` (first N bytes, default 16 KB), `bodyTruncated`, `clientIp`, `tenantId`, `userId`, `receivedAt`; methods `header(name)`, `queryString()`.
- `Http\RequestFactory::fromGlobals($server, $query, $rawBody, $trustedProxies, $tenantId, $userId, $maxBodyBytes)`: never reads globals itself; `REMOTE_ADDR` by default; uses `X-Forwarded-For` only when `REMOTE_ADDR` is a trusted proxy, walking right to left past trusted proxies; strips ports; invalid entries skipped; missing `REMOTE_ADDR` gives `0.0.0.0`.
- `Net\IpMatcher`: exact IPs and CIDR, IPv4 and IPv6, IPv4-mapped IPv6 converted, invalid entry throws in the constructor, invalid IP matches nothing.
- `Rules\Rule` interface: `id()`, `phase()`, `inspect(NormalizedRequest): ?Finding`. `Rules\Phase`, `Rules\Severity` (Low, Medium, High, Critical, `rank()` 1 to 4). `Rules\SensitivePathRule` (see detection.md).
- `Finding` (readonly): `ruleId`, `severity`, `message`, `evidence` (max 200 chars), `banWorthy`.
- `Decision` (readonly): `findings`, `wouldBlock` (set in both modes), `blocked` (Enforce and Inline only), `responseStatus`, `banForSeconds`, `alreadyBanned`; `allow()`, `hasFindings()`, `highestSeverity()`.
- `Store\Store` interface: `increment(key, windowSeconds): int` (atomic, fixed window, never extends expiry), `ban(ip, seconds)`, `isBanned(ip)`, `unban(ip)`. Failures fail open (increment 0, isBanned false). `ArrayStore` has an injectable clock.
- `Events\SecurityEvent`, `Events\EventSink` (record never throws), `JsonLinesSink`, `InMemorySink`.

## 4. Engine algorithm

`Engine::inspect(request, phase)`:

1. Unbannable IP = allowlisted, `0.0.0.0`, or fails `FILTER_VALIDATE_IP`. Unbannable IPs are never blocked or banned and their ban status is never checked, but findings are still recorded.
2. Inline only, IP bannable: if `store->isBanned(ip)` return a `banned-ip` finding (High, not banWorthy), `alreadyBanned` true, `wouldBlock` true, `blocked` only in Enforce, no rules run, ban not renewed.
3. Run rules whose phase equals the argument, each in its own try/catch. Inline stops at the first banWorthy finding.
4. `wouldBlock` = any banWorthy finding (also true for unbannable IPs, to keep data honest).
5. Enforce, `wouldBlock`, bannable IP: Inline gives `blocked` true and status `blockStatus`; Deferred keeps `blocked` false and status null (response already sent). Both phases ban for `banDuration(ip)`; a failing `store->ban` is swallowed and `banForSeconds` becomes null.
6. Detect never calls `ban` and never sets blocked, status or banForSeconds.
7. Build the `Decision`, then record one `SecurityEvent` per finding (each sink call in its own try/catch).

`banDuration`: counter `ban-count:{ip}` with a 30-day window; duration = `banSeconds * 2^(count-1)`, capped at `maxBanSeconds`, overflow-safe; count 0 or less uses `banSeconds`. Any unexpected throwable returns `Decision::allow()`.

## 5. Redis store (namespace Stero\Shield\Redis)

`RedisStore` over a small `Client` interface (`PhpRedisClient` wraps `\Redis`). Keys: `shield:c:{key}` for counters, `shield:ban:{ip}` for bans. `increment` is one Lua script: INCR, set EXPIRE only when the result is 1, self-heal when TTL is -1, never extend. `ban` is SET with EX (minimum 1). After the first caught failure an instance short-circuits every later call (a dead Redis must not stall a request several times). `NullStore` is a no-op used when connect fails. `RedisConnector::connect(host, port, ?password, connectTimeoutMs = 100, readTimeoutMs = 100)` returns `\Redis|null` and never throws. Runtime and adapter wiring select the configured database after connecting. Integration tests need `SHIELD_TEST_REDIS=host:port` and use random prefixes with SCAN cleanup, never FLUSHALL.

## 6. Runtime (namespace Shield\Runtime)

- `Settings::fromArray(config)`: `enabled` (default false), `mode` ("enforce" else Detect), `events_path`, `allowlist_file`, `allowlist_extra` (list or comma string), `redis {host, port, password, database default 5, prefix "shield:"}`, `skip_prefixes` (default `/up`, `/build/`, `/storage/`; entries must start with `/`), `timing {enabled, sample_every default 20, path}`, `sensitive_paths` (null for default). Never throws.
- `AllowlistLoader::load(file, extra): AllowlistResult(entries, dropped)`: ignores blanks and `#` lines, validates each entry alone with `IpMatcher`, dedupes, missing file contributes nothing.
- `EngineFactory::create(Settings, ?Store, ?EventSink): Engine`: default rules `[SensitivePathRule]`, `blockStatus` 404, loaded allowlist, Redis store or `NullStore`, `JsonLinesSink`.
- `Guard(Settings, Engine, ?TimingRecorder)`: `isEnabled()`, `shouldSkip(path)` (case-sensitive prefix), `before(NormalizedRequest): Inspection(decision, inlineMicros, skipped)`, `after(request, ?userId, ?Inspection)`; both never throw; `BLOCK_BODY` "Not Found", `blockHeaders()`.
- `TimingRecorder` (sampled JSON lines `{ts, inline_us, deferred_us}`), `TimingReport::fromFile` (p50, p95, p99, nearest-rank, `passesBudget(5.0)`), `StatusReporter::report(Settings, ?connector): StatusReport` (healthy false when enabled and the store is `NullStore` or the events folder is not writable; warning when Redis database is 0).

## 7. Laravel adapter (namespace Shield\Laravel)

- `ShieldServiceProvider` (auto-discovered): merges `config/shield.php`, publishes it under tag `shield-config`, registers commands, binds `Settings`, `Store` and `EventSink` by interface (overridable in tests), singleton `Guard`. When enabled and `shield.auto_register_middleware` (default true) it prepends `ShieldMiddleware` to the global HTTP kernel so it runs before tenancy and sessions. When disabled it registers and builds nothing.
- `ShieldMiddleware`: `handle()` builds the `NormalizedRequest` (server bag, query bag, EMPTY body, `trustedProxies` [], `tenantId` = lowercase host), calls `Guard::before`, stores request and inspection in request attributes, returns the 404 when blocked. `terminate()` reads only from request attributes (the instance may be fresh), resolves the user id as string or null, calls `Guard::after`. Both wrapped in try/catch.
- Commands: `shield:install` (publishes config, creates `storage/shield` with 0775, prints the env flags, never edits `.env`), `shield:status` (exit 1 when not healthy), `shield:timing` (PASS/FAIL against 5 ms p95).
- Env names: `SHIELD_ENABLED` (default false), `SHIELD_MODE` (default detect), `SHIELD_ALLOWLIST_FILE`, `SHIELD_ALLOWLIST_EXTRA`, `SHIELD_REDIS_HOST`, `SHIELD_REDIS_PORT`, `SHIELD_REDIS_PASSWORD`, `SHIELD_REDIS_DB` (default 5), `SHIELD_TIMING`. Paths default to `storage_path('shield/...')`.
- Supported and tested: Laravel 13 only (EduConect). Do not claim more.

## 8. CLI viewer (packages/cli, namespace Stero\Shield\CLI, binary `bin/shield`)

Termwind (HTML plus Tailwind-style classes) and Symfony Console. Commands: `tail` (last 20 then follow, 500 ms polling, reopens on truncation or rotation, waits for a trailing newline), `events` (filters `--rule --ip --tenant --severity --would-block --since --limit`), `stats` (totals, per mode, top 5 rules, IPs and paths, per-hour bar line). Options: `--file` or env `SHIELD_EVENTS` (default `./events.jsonl`), `--json`, `--no-ansi`, `NO_COLOR`, `--theme=auto|light|dark|plain` or `SHIELD_THEME`. Non-TTY output has no ESC bytes. Reads the last N lines by seeking from the end. Exit 0 normal, 1 for bad options or unreadable file.

Security: `Sanitiser::forTerminal` removes C0 and C1 controls, replaces invalid UTF-8, neutralises bidi overrides (U+202A to U+202E, U+2066 to U+2069), then `htmlspecialchars`. Every request-derived string goes through it. Colours live only in `Theme` as badge pairs with both background and foreground set (white on red-600 BLOCKED, black on amber-500 WOULD BLOCK, white on gray-600 WATCH, severity badges); plain text uses the terminal default. A test enforces contrast of at least 4.5:1. Never query the terminal background (no OSC 11).

## 9. Event schema (`SecurityEvent::toArray`, JSON lines)

`ts` (ISO 8601 UTC), `tenant`, `user`, `ip`, `method`, `path`, `rule`, `severity`, `would_block`, `blocked`, `mode`, `message`, `evidence`. One event per finding. The CLI depends on these keys, so never rename them.
