# Shield: project instructions for coding agents

Shield is a framework-agnostic intrusion detection and prevention layer for
PHP web apps, built by Stero. First host app: EduConect (Laravel). It starts
in detect-only mode and moves to enforcement rule by rule.

The owner must be able to read and review everything you write. Prefer plain,
boring, explicit PHP over clever code. If a choice is unclear, take the
simpler option and say what you assumed.

## Repository layout

    packages/core/src      the engine (pure PHP, no framework)
    packages/core/tests    Pest tests
    packages/core/demo     plain PHP demo, run with php -S
    tests/corpus/attacks   one JSON request per file, must trigger rules
    tests/corpus/normal    one JSON request per file, must trigger nothing

There is no Laravel package and no lab app. Framework glue (about 15 lines
of middleware) lives inside each host app, never in this repo.

## Architecture in one minute

Request flow: `RequestFactory` builds a `NormalizedRequest`. `Engine::inspect()`
runs the `Rule`s for one `Phase`, applies `Mode`, manages bans through a
`Store`, records `SecurityEvent`s through an `EventSink`, and returns a
`Decision`. The host app only reads the `Decision`.

- `Phase::Inline` rules run before the app handles the request. They must be
  very cheap (path checks, one counter). Total budget is a few milliseconds.
- `Phase::Deferred` rules run after the response is sent. Heavy work goes here.
- `Mode::Detect` records findings and never blocks. `Mode::Enforce` blocks and
  bans. Only `Engine` may look at `Mode`.
- `Decision` has `wouldBlock` (set in both modes) and `blocked` (Enforce only).
  `wouldBlock` is the number rules are tuned against.
- Blocked or banned clients get the configured status, default 404, so probes
  learn nothing. Never answer with 403 for security blocks.

## Hard rules

1. No framework imports in `packages/core`. No Laravel, Symfony or PSR-7
   types. The only allowed extra dependency is `psr/log`, and only if asked.
2. `declare(strict_types=1);` in every file. PHP 8.3 or later. Value objects
   are `final readonly`.
3. Fail open. Rules, stores and sinks must never throw into a user request.
   Catch inside `Engine`, return `Decision::allow()` on any failure.
4. Never trust `X-Forwarded-For` unless `REMOTE_ADDR` is in the trusted proxy
   list. Never decode a path twice.
5. Never log or store request bodies, cookies, Authorization headers, tokens
   or form values. Evidence in events is a short snippet (max 200 chars) of
   the path or matched pattern. This is a Data Protection Act, 2012 (Act 843)
   requirement.
6. Ban only on `banWorthy` findings (honeypot paths such as /.env). Rate
   based or fuzzy signals alert or throttle, they never ban. Bans always
   expire. Allowlisted IPs are never banned or blocked.
7. Do not read `$_SERVER`, `$_GET`, `$_POST` or `php://input` outside the demo.
   Callers pass inputs in, so tests can feed any request.
8. Do not add rules, classes, config options or dependencies that were not
   asked for. Do not rename rule ids, event keys or corpus fields without
   being told, because the dashboard and corpus depend on them.
9. Never use em dashes in code comments or docs. Use British spelling.
10. Use mb_* functions for all string work, always passing 'UTF-8' explicitly (never rely on mb_internal_encoding). Exceptions: byte limits use mb_strcut, never substr, so a multibyte character is not cut in half. Before any matching, check mb_check_encoding($s, 'UTF-8').

## Workflow

- When asked to commit, load and follow
  [.agents/skills/commit-gate/SKILL.md](.agents/skills/commit-gate/SKILL.md).
  Every commit must pass its gates and use Conventional Commits format.
- Never use Python to edit files. Use patch-based edits instead.
- Work on one class or one rule at a time. Read its docblock first. The
  docblocks hold the contract and the implementation notes.
- Test first. Write the unit test from the docblock, watch it fail, then
  implement.
- Strict TDD is mandatory for every behaviour change: write the test first,
  run it and verify that it fails for the intended missing behaviour before
  editing production code. Then implement the smallest correct change and
  rerun the test to prove it passes. Refactor only while tests remain green.
  Never write implementation first or claim a red run that was not observed.
- A new rule is not done until it has at least one attack file in
  `tests/corpus/attacks` and one normal file in `tests/corpus/normal`.
- Keep changes small. One task, one commit, no drive-by refactors.
- If the docblock and the code disagree, stop and ask. Do not guess.

## Commands (add these as composer scripts if missing)

    composer test        # pest
    composer analyse     # phpstan, level max
    composer lint        # pint

## Definition of done

Tests pass, PHPStan is clean at max level, Pint reports nothing, the corpus
test passes, and the class contains no remaining "Not implemented" or TODO
for the task in hand. In your reply, list what you changed and any
assumption you made.

## Implementation order

1. Helpers on `Severity`, `Decision`, `NormalizedRequest`
2. `ArrayStore` (with injectable clock), `InMemorySink`, `JsonLinesSink`
3. `RequestFactory` (security critical, test hard: encoded paths, double
   slashes, spoofed forwarded headers)
4. `SensitivePathRule` (must not flag /.well-known/)
5. `Engine` (follow the numbered notes in the class)
6. `CorpusTest`, then `demo/index.php`

Later, only when asked: `LoginRateRule`, `RedisStore`, then the host app
middleware that calls `Engine::inspect()` twice (Inline before the app,
Deferred after the response is sent).

## Glossary

Finding: one thing a rule noticed. Decision: what the engine tells the app.
SecurityEvent: one audit row per finding. Store: counters and bans, kept
outside PHP because PHP forgets between requests. Corpus: the saved set of
attack and normal requests that proves the rules work.
