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
