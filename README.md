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
