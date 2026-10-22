# Shield logs datatable self-review

Implementation reviewed 22 October 2026, before the requested commit.

## Scope and changed files

The viewer changes belong to runtime, the CLI imports, and the Laravel command.
The owner's subsequent namespace correction also applies to existing runtime and
Laravel classes, their imports, Composer autoloading and provider discovery.
Those namespace-only changes are intentional. No dependency was added.

Changed files, including the two moves represented as old and new paths:

- `README.md`
- `composer.json`
- `composer.lock` (namespace metadata hash refreshed at the commit gate)
- `packages/cli/composer.json`
- `packages/cli/src/Application.php`
- `packages/cli/src/EventFormatter.php`
- `packages/cli/src/Renderer.php`
- `packages/cli/src/Sanitiser.php` (moved)
- `packages/cli/tests/SanitiserTest.php` (moved)
- `packages/runtime/src/Sanitiser.php` (move destination)
- `packages/runtime/tests/SanitiserTest.php` (move destination)
- `packages/runtime/composer.json`
- `packages/runtime/src/Agent/AgentFilesPublisher.php`
- `packages/runtime/src/Agent/AgentTarget.php`
- `packages/runtime/src/Agent/PublishResult.php`
- `packages/runtime/src/AllowlistLoader.php`
- `packages/runtime/src/AllowlistResult.php`
- `packages/runtime/src/EngineFactory.php`
- `packages/runtime/src/Guard.php`
- `packages/runtime/src/Inspection.php`
- `packages/runtime/src/Settings.php`
- `packages/runtime/src/StatusReport.php`
- `packages/runtime/src/StatusReporter.php`
- `packages/runtime/src/TimingRecorder.php`
- `packages/runtime/src/TimingReport.php`
- `packages/runtime/src/Events/EventReader.php`
- `packages/runtime/src/Events/EventFilter.php`
- `packages/runtime/src/Events/Pager.php`
- `packages/runtime/src/Events/Facets.php`
- `packages/runtime/src/Events/Incident.php`
- `packages/runtime/src/Events/IncidentGrouper.php`
- `packages/runtime/src/Events/PathFormatter.php`
- `packages/runtime/tests/AgentTest.php`
- `packages/runtime/tests/RuntimeTest.php`
- `packages/runtime/tests/EventsTest.php`
- `packages/laravel/SELF_REVIEW.md`
- `packages/laravel/LOGS_SELF_REVIEW.md`
- `packages/laravel/composer.json`
- `packages/laravel/composer.lock`
- `packages/laravel/src/Commands/InstallCommand.php`
- `packages/laravel/src/Commands/LogsCommand.php`
- `packages/laravel/src/Commands/StatusCommand.php`
- `packages/laravel/src/Commands/TimingCommand.php`
- `packages/laravel/src/ShieldMiddleware.php`
- `packages/laravel/src/ShieldServiceProvider.php`
- `packages/laravel/tests/AdapterTest.php`
- `packages/laravel/tests/InstallCommandTest.php`
- `packages/laravel/tests/LogsCommandTest.php`
- `packages/laravel/tests/Pest.php`
- `packages/laravel/tests/TestCase.php`
- `resources/agent/shield.md`
- `resources/agent/summary.md`
- `tests/install/check.sh`

## Requirement trace

Runtime tests below are in `packages/runtime/tests/EventsTest.php`; command tests
are in `packages/laravel/tests/LogsCommandTest.php`, unless stated otherwise.

| Requirement | Proof | Status |
| --- | --- | --- |
| Read CLAUDE.md first | Read before any edit; it includes AGENTS.md | Done |
| Correct Stero namespace | All existing runtime and Laravel classes and imports corrected; AdapterTest checks root autoload/provider; install smoke exercises runtime | Done |
| No framework imports in core/runtime/redis | Static search; existing adapter suite remains green | Done |
| Fail open for bad lines | Reader malformed record, missing key, bad type and invalid timestamp tests | Done |
| Missing/unreadable path gives reason and exit 1 | Reader missing file/directory checks; command missing file test; reader catches all failures | Done; actual permission denial not exercised as root |
| Bad options give message and exit 1 | Command invalid since, page, per-page, verdict and severity tests | Done |
| Every displayed request string sanitised | Moved Sanitiser tests; command full-path and every-field tests; decoded inspection test | Done |
| Colours only from Theme; verdict/severity written as words | New table and menus use plain text, no new colours; render tests assert textual columns and values | Done |
| No ESC without TTY or with NO_COLOR | Non-interactive render tests, forced ANSI test and real PTY menu regression test under NO_COLOR | Done |
| No new dependency; Symfony Table and Laravel Prompts | Composer require sections unchanged; code uses installed Console and Prompts | Done |
| Event schema unchanged | SecurityEvent untouched; JSON export checks original keys | Done |
| Move Sanitiser and preserve its tests | Same implementation and all seven moved tests pass; CLI suite passes | Done |
| Reader seeks backwards with default final 5000 physical lines | Code review: seeks from EOF in 8192-byte blocks; final-N test | Done |
| Reader skips malformed and incomplete records | Malformed record and bad-field tests | Done |
| Reader newest first and missing final newline | Reader final-N test and multi-block test with both endings | Done |
| Filter readonly and all exact fields | Readonly declaration; every-field and combined-filter datasets | Done |
| Verdict blocked/would_block/any | Filter datasets, constructor validation, JSON and default render | Done |
| Since parser and inclusive timestamp filtering | Valid 15m/2h/1d/7d durations, invalid input, since dataset; implementation uses inclusive boundary | Done |
| Case insensitive path search | ADMIN/admin filter dataset | Done |
| Pager rows/page/pages/total and clamping | Exact first-page structure, below-one page, above-last page, invalid size and empty input assertions | Done |
| Facet counts, descending frequency and alphabetical ties | Exact count map and verdict facet assertions | Done |
| Incident immutable fields and IP/rule grouping | Readonly Incident; two-IP/two-rule/group test and command COUNT fixture | Done |
| Split only when adjacent gap exceeds 60s | 60-second chain joins, 61-second gap splits; mutation 2 | Done |
| Skip malformed incidents, newest ordering, highest severity | Group test with malformed record, critical event, blocked event and explicit ordering | Done |
| Path sanitised before measurement/truncation | Control/bidi test with long control prefix; mutation 4 | Done |
| Middle ellipsis, original last six characters | Long ASCII path test checks ellipsis, end suffix and width | Done |
| Display width including wide characters | Long wide path bound and exactly-fitting wide path equality; mutation 5 | Done |
| Exact fit unchanged, minimum width 12 | ASCII equality and below-minimum width tests | Done |
| Header includes mode, file, filtered event total and page bounds | 50-event render test and header code review; long filename fitted | Done |
| Every filter shown with any when unset | Default render asserts path=any; empty result includes active IP; filter bar iterates all seven fields | Done |
| Box table TIME/VERDICT/SEV/IP/METHOD/PATH | Default render and box-border width assertion | Done |
| RULE/TENANT only when varying and width >=100 | Wide/narrow varying facet test | Done |
| Constant rule/tenant in footer | Default 50-probe fixture test | Done |
| PATH uses terminal width, minimum 24 and no Table wrapping | 80-column long wide-path test; exact 80-column default border; column widths set explicitly | Done |
| Sanitise, fit, escape markup in that order | Full-path/every-field tests, literal markup test, formatter control test; implementation review | Done |
| Full paths, shortened TIME and METHOD dropped if necessary | Complete long-path single-row test | Done |
| Newest sort and default 15 rows | JSON first timestamp; default rows 1-15/page 1 of 4 | Done |
| All requested options, configured default file | Signature review; fixture Settings binding; file-override missing-file test | Done |
| JSON original array, unpaginated, no styling/prompts | 50-item JSON test with group enabled and original keys; raw JSON output | Done |
| Group TIME/VERDICT/SEV/IP/RULE/COUNT/PATH and bounds footer | COUNT 50 fixture, first/last footer checks, real TTY group toggle | Done |
| Interactive only when both streams TTY and input interactive | Code checks both streams and Symfony input; non-interactive tests; actual PTY test | Done |
| Next/Previous/Filter/Inspect/Group/Clear/Quit and re-render | Actual PTY test drives all seven actions and exits 0 | Done |
| Searchable facet counts and stable values | Facet count tests; selected-facet regression; real TTY filter search | Done |
| Since choices 15m/1h/6h/24h/7d, path text input | Menu code review; parser covers m/h/d | Done; these two prompts not driven in PTY test |
| Inspection full path, decoded path, method, UTC time, IP/rule/severity/verdict/tenant/mode/evidence | Selected-row regression checks decoded and sanitised details; PTY test visits panel; field-loop code review | Done |
| Empty matches show message and active filters | Unknown-IP command test | Done |
| Non-interactive render once and exit 0 | Render tests contain no Next page menu and assert exit status | Done |
| Updated command documentation and agent resources | README and both agent resources updated in working tree | Done |
| All five deliberate mutations fail and are restored | Results below; final complete check after restoration | Done |

## Mutation results

Each mutation was applied to production code, the named test was run and failed,
then the exact change was reversed with a patch before the next mutation.
Restored calls, constants and clamping were inspected; `git diff --check` passed.

| # | Deliberate break | Test that failed | Observed result |
| --- | --- | --- | --- |
| 1 | Removed sanitisation from the PATH cell | Command: sanitises full paths before output even when colour is forced | Expected clean path absent; control-bearing output rejected |
| 2 | GAP_SECONDS 60 to 6000 | Runtime: groups by adjacent time gaps IP and rule with highest severity and newest ordering | Three groups instead of four |
| 3 | Removed page clamping | Runtime: clamps pages and handles invalid sizes and empty input | Page -1 and wrong rows instead of page 1 |
| 4 | Truncated path before sanitising | Runtime: removes controls and bidi overrides before deciding whether to cut | Returned / instead of /abcdef |
| 5 | Used strlen instead of display width for fit check | Runtime: fits paths by display width preserving the tail and sanitising before cutting | Exactly fitting wide text was incorrectly shortened |

The fourth mutation was rerun against a separate control-character test so its
failure specifically proves the required sanitise-before-truncate ordering.

## Static checks and test quality

- No task TODO or Not implemented remains.
- No bare `urldecode()` introduced. Inspection deliberately uses `rawurldecode()`
  for the requested display-only decoded path, then sanitises it.
- No request superglobals or `php://input` in the changed source.
- No framework imports in core, runtime or redis. Laravel imports stay in its
  adapter; existing Symfony imports in the CLI are intentional.
- No new Mode references or security decisions outside Engine. Existing runtime
  Settings, EngineFactory, StatusReport and StatusReporter already reference
  Mode; those pre-existing references were not changed beyond their namespace.
- No new Composer dependency, no new event schema key, no em dash in new code or
  documentation. Root provider discovery and autoload mappings were updated.
- New tests use actual ESC, invalid UTF-8 and Unicode bidi characters. Every new
  test makes behavioural assertions. No new assertion-free tests were found.
- Review found and fixed selection labels being mistaken for numeric keys,
  blank tenant facets not matching null tenants, and Symfony autocomplete
  emitting ESC under NO_COLOR. Each fix had an observed failing test first.
- The path width is assigned to Table as well as used for fitting. Filter bar
  wrapping preserves words rather than splitting path=any across lines.

## Assumptions and limitations

- Severity filtering is exact. This replaces the old Laravel delegation's
  minimum-severity interpretation, as documented with the new options.
- JSON exports the filtered tail before grouping or pagination. Original keys
  and values are preserved; JSON escaping keeps terminal controls encoded.
- The incident tenant and sample path come from the most recent event in the
  incident. Grouping is by IP and rule regardless of tenant, as requested.
- Full paths can exceed the terminal width after shortening TIME and dropping
  METHOD. Preserving complete paths and avoiding wrapping requires this when a
  path is wider than the available terminal.
- The UI deliberately uses plain text throughout. Laravel Prompts supplies its
  plain fallback menu and search. Under NO_COLOR, Symfony stty autocomplete is
  disabled because it emits cursor escape sequences even without decoration.
- Seven existing Redis integration tests require SHIELD_TEST_REDIS and were
  skipped. One existing filesystem permission test skips as root. No Redis or
  permission policy was changed for this viewer task.

## Final gate

After all five mutations were restored and the review fixes were applied,
root `composer check` completed successfully, including installation smoke:

```text
Core:     Tests: 261 passed (1407 assertions)
Redis:    Tests: 7 skipped, 7 passed (36 assertions)
Runtime:  Tests: 1 skipped, 79 passed (328 assertions)
CLI:      Tests: 79 passed (347 assertions)
Laravel:  Tests: 57 passed (271 assertions)
Install:  ok
Total:    483 passed, 8 skipped, 2389 assertions
PHPStan:  No errors in all five packages at max level
Pint:     passed in all five packages
```
