# Writing task prompts for the coding agent

Every task so far followed one shape. Keep it, because Stero pastes these straight into the agent and reviews the result against them.

## Template

    Read CLAUDE.md first. Task N: <one line>. <Assumes tasks X to Y are complete and committed.>

    Files you may touch: <explicit list>. Do not touch anything else.
    If you believe code outside the list has a bug, stop, write a failing
    test that shows it, and report it.

    Write the failing tests first, show them failing, then implement.
    Use mb_* with 'UTF-8' passed explicitly where text is handled.

    <SPEC: numbered requirements, exact behaviours, edge cases with real bad
    bytes and boundaries, fail-open behaviour>

    <TESTS: one bullet per behaviour, including boundary and negative cases>

    Run composer check (and the other packages' checks when they could be affected).

    Finish with the self-review from CLAUDE.md.
    Mutation targets:
    1. <break X and confirm test Y fails>
    ... (3 to 8 targets chosen per task)
    Also run <manual steps> and paste the real output, or say clearly if you could not.

After the prompt, always give Stero a short "Your review" list (10 to 30 minutes): specific things to read, one or two by-hand mutation checks, a grep or two, and anything to try by hand (for example send a request with an escape sequence and confirm the terminal does not clear).

## Principles

- One task, one commit. Small steps; slow down on `RequestFactory`, `Engine`, `Guard`, `Sanitiser`.
- State assumptions and design decisions in the reply so Stero can overrule them before sending.
- Be explicit about fail-open, allowlists, `0.0.0.0`, Redis database 5, 404 plus `no-store`.
- Keep the agent inside the file list. Agents drift into extra classes and extra dependencies.
- Ask for evidence: real command output, real test counts.
- When the agent reports back, check: which files changed, whether mutation checks really failed tests, whether UTF-8 tests used real bad bytes, whether a claimed pass is possible. Correct the report where it is wrong (for example a boundary comparison).

## Task history (scope only; full prompts are not stored)

1 helpers; 2 stores and sinks (then a fix round: expiry boundary, JSON flags, tests); 3 RequestFactory; 4 SensitivePathRule and corpus; 5 IpMatcher and Engine; 6 CorpusTest and demo (DemoFileStore, `php -S`); 7 CLI viewer; 7b theme-safe colours; 8 Redis package (amended with database selection); 8b root composer package, licence, SECURITY.md, secret scan before going public; 9 EduConect integration (middleware, provider, config, `shield:status`, `shield:timing`); 10a runtime package; 10b Laravel adapter; 11 EduConect migrates to the package; 12 usage tracking.

## Task 11 outline (EduConect repo)

`composer update stero/shield` to the `v0.2.0` tag; delete the local provider, middleware, config, allowlist loader, both commands and their tests; remove the `prepend` line from `bootstrap/app.php`; keep env names; run EduConect's full suite; do not touch tenancy, auth, routes or the old probe-ban middleware. After deploy: `php artisan shield:status` healthy, a `/.env` request produces the same event shape as before. Rollback by flipping the release symlink.

## Task 12 outline (EduConect repo, usage tracking)

See `infrastructure.md`, section "Usage tracking design". Step 0 for the agent: find out whether tenancy uses one database or a database per school and which role names exist.
