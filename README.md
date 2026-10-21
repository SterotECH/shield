# Shield skeleton

Rules for all code: `declare(strict_types=1)`, no framework imports, final
readonly value objects, and nothing in the inline path may throw (fail open).

## Implement in this order
1. Severity::rank, Decision helpers, NormalizedRequest helpers
2. ArrayStore, InMemorySink, JsonLinesSink
3. RequestFactory (path and client IP are security critical, test hard)
4. SensitivePathRule
5. Engine (follow the numbered notes in the class)
6. CorpusTest, then demo/index.php
Each step is done when its unit tests pass and PHPStan (max) is clean.

## Later, not in this skeleton
LoginRateRule (needs Store), RedisStore, the 15-line middleware inside
EduConect that builds a NormalizedRequest and calls Engine::inspect twice.

## Run the demo

From `packages/core`, install development dependencies with `composer install`,
then start the demo:

```sh
SHIELD_DEMO=1 composer demo
curl -i localhost:8080/hello
curl -i localhost:8080/.env
curl localhost:8080/__events
```

The first request returns 200 and `X-Shield-Would-Block: 0`; the second returns
200 and `X-Shield-Would-Block: 1`. Both say `hello`. On fresh state, events
shows one `sensitive-path` event. State persists in `demo/.state/`.

Stop the server with Ctrl-C, then restart in enforcement mode:

```sh
SHIELD_DEMO=1 SHIELD_MODE=enforce composer demo
curl -i localhost:8080/.env
curl -i localhost:8080/hello
curl localhost:8080/__reset
curl -i localhost:8080/hello
```

The probe returns 404 with `Not Found` and bans the IP. The next `/hello`
also returns 404. Reset prints `reset`; `/hello` then returns 200 again.
Reset clears bans and counters, preserving event history. Helper routes are
enabled only with `SHIELD_DEMO=1`. This local demo store is not for production;
use Redis there.

## Redis store

Install `packages/redis` with Composer (PHP 8.5, ext-mbstring and ext-redis).
Use `Stero\Shield\Redis\RedisConnector::connect($host, $port, $password)`;
it returns a native Redis connection or null with 100 ms connection and read
timeouts by default. Wrap a connection in `PhpRedisClient`, then `RedisStore`;
use `NullStore` when the connection is null. All three classes share the
`Stero\Shield\Redis` namespace. Create a fresh store for each request: after
one failure it skips all later operations on that instance. Counters use
fixed windows and bans always expire. Pass a distinct prefix per application.

Run `composer check` in `packages/redis`. Integration tests skip unless
`SHIELD_TEST_REDIS=host:port` is set. With Lerd Redis running, use
`lerd-redis:6379` inside the PHP container and pass the variable into that
container. Alternatively, `docker compose up -d` in `packages/redis` starts
Redis 7 on host port 6380. Tests clean up only their random prefixes via SCAN.

## Viewing events

Install the independent viewer from `packages/cli` with `composer install`
(PHP 8.5 or later), then run:

```sh
bin/shield events --file=../core/demo/.state/events.jsonl --severity=high --limit=20
bin/shield stats --file=../core/demo/.state/events.jsonl --since=24h
bin/shield tail --file=../core/demo/.state/events.jsonl --lines=20
```

Set `SHIELD_EVENTS` instead of passing `--file`; the default is `./events.jsonl`.
Events also supports `--rule`, `--ip`, `--tenant`, `--would-block` and `--since`
(`15m`, `2h`, `1d` or an ISO 8601 timestamp). `--json` emits matching raw JSON
lines for piping; skipped-line notices go to stderr. Stop tail with Ctrl-C.
Use `--theme=auto|light|dark|plain` or `SHIELD_THEME`; the option wins. Auto
reads `COLORFGBG` without querying the terminal. Badge pairs keep the same
contrast on every theme, while other text uses terminal defaults. `--no-ansi`,
`NO_COLOR`, plain theme and piped output disable styling.
