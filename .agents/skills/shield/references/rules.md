# Project rules for coding agents (snapshot of CLAUDE.md and AGENTS.md)

The repo files are authoritative. This snapshot is for chats without repo access.

## Hard rules

1. No framework imports in `packages/core`, `packages/redis` or `packages/runtime`. Only a framework adapter package may import its own framework. The only allowed extra dependency in core is `psr/log`, and only if asked.
2. `declare(strict_types=1);` in every file. PHP 8.3 or later (EduConect runs 8.5). Value objects are `final readonly`.
3. Fail open. Rules, stores and sinks never throw into a user request. Catch inside `Engine`/`Guard`, return `Decision::allow()` on any failure.
4. Never trust `X-Forwarded-For` unless `REMOTE_ADDR` is in the trusted proxy list. Never decode a path twice.
5. Never log or store bodies, cookies, Authorization headers, tokens or form values. Evidence is a short snippet (max 200 characters) of the path or pattern. Act 843 applies.
6. Ban only on `banWorthy` findings. Rate-based or fuzzy signals alert or throttle. Bans always expire. Allowlisted IPs are never banned or blocked.
7. Do not read `$_SERVER`, `$_GET`, `$_POST` or `php://input` outside the demo. Callers pass inputs in.
8. Do not add rules, classes, config options or dependencies that were not asked for. Do not rename rule ids, event keys or corpus fields without being told.
9. No em dashes in code comments or docs. British spelling.
10. Use `mb_*` functions for string work, always passing `'UTF-8'`. Byte limits use `mb_strcut`, never `substr`. Check `mb_check_encoding($s, 'UTF-8')` before matching.
11. The client IP `0.0.0.0`, invalid IPs and allowlisted IPs are never banned or blocked.
12. Use the shared `IpMatcher` for every IP or CIDR comparison. Do not write a second one.
13. `packages/cli` is the only package allowed dependencies (`symfony/console`, `nunomaduro/termwind`). It reads `events.jsonl` and never imports core classes. The event keys of `SecurityEvent::toArray()` are its contract.
14. Every string that came from a request is untrusted. Strip control characters and escape markup before it reaches a terminal.
15. `packages/redis` may depend on `ext-redis` and the core `Store` interface only. Every Redis failure fails open.
16. Adapters hold no security logic. They only translate: build a `NormalizedRequest` in, apply a `Decision` out. Fail-open handling, skip prefixes, allowlists, timing and status live in `packages/runtime`.
17. Every top-level namespace must be in the ROOT `composer.json` autoload, and `tests/install/check.sh` must exercise it. Sub-package `composer.json` files are for development only.

## Workflow

- One class or one rule at a time. Read its docblock first (contracts and implementation notes live there).
- Test first: write the failing test, show it failing, then implement.
- A new rule is not done without at least one attack file and one normal file in `tests/corpus`.
- Small changes, one task per commit, no drive-by refactors.
- If the docblock and the code disagree, stop and ask.

## Commands

    composer test      # pest
    composer analyse   # phpstan, level max (EduConect uses level 10)
    composer lint      # pint (per preset in Shield; EduConect uses its own config)
    composer check     # lint:check, analyse, test

## Definition of done

Tests pass, PHPStan clean at max level, Pint clean, corpus test passes, no remaining "Not implemented" or TODO for the task. The reply lists what changed and any assumption.

## Self-review (mandatory at the end of every task)

After `composer check` passes and before reporting, act as a hostile reviewer of your own work. Fix what you find and say so.

1. Scope: `git status` and `git diff --stat`. Any file outside the task's allowed list must be reverted or justified.
2. Spec trace: for every requirement in the task prompt give the requirement, the test that proves it, and a status (done, partial, missing).
3. Mutation checks: for the 3 to 5 most critical behaviours, break the implementation one change at a time and confirm the relevant test FAILS. Restore and confirm with `git diff`. If no test fails, strengthen the test and repeat.
4. Static checks: grep and report: no "Not implemented" or TODO left for this task; no `urldecode`; no `$_SERVER`, `$_GET`, `php://input` inside `src`; no framework imports in framework-free packages; `Mode` referenced only in `Engine`, `EngineConfig`, `SecurityEvent`; no new dependencies; no em dashes.
5. Test quality: list any test with no behavioural assertion, a lookalike string where real bad bytes are needed, or that would still pass with the implementation deleted.
6. Report: (a) changed files, (b) spec trace table, (c) mutation table (change made, test that failed), (d) static check results, (e) assumptions, (f) anything unverified. Run `composer check` once more after mutations are restored and paste the summary line.
