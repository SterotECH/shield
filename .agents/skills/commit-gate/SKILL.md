---
name: commit-gate
description: Review the commit scope, run checks appropriate to the changed files, and create a Conventional Commit when the user asks to commit. Does not push or publish changes.
---

# Commit gate

Before every commit in this repository:

1. Read AGENTS.md, inspect the working tree and staged diff, and identify
   the changes authorised for the commit. Preserve unrelated work.
2. Choose checks from the actual diff before running tooling. When every
   change is limited to documentation, agent instructions or skills, or
   .gitignore, use focused validation and Git checks only. Review the text
   for correctness; for .gitignore changes, verify intended paths with
   git check-ignore and ensure tracked files remain tracked. Do not run
   PHP tests, Pint, PHPStan, composer check or Composer validation for
   these commits. Behavioural mutation checks are not applicable to this
   limited scope. If any changed file affects code, dependencies, autoload,
   runtime configuration, test execution or build tooling, use the full
   gates below. If uncertain, use the full gates.
3. For full gates, from packages/core, run Composer scripts test, lint:check, and analyse
   through lerd. Run all three even if one fails, for a complete report.
   lint:check is the read-only Pint check; use lint -- --test if absent.
4. Always run git diff --check. For full gates, run composer validate --strict. Inspect declared
   check scripts and run additional checks relevant to changed files.
   For namespace or autoload changes, also run
   composer dump-autoload --optimize --strict-psr.
5. Require every applicable gate to pass. Existing failures in applicable gates still block the commit.
   Report failing commands and causes. Never skip checks, suppress errors,
   weaken configuration, or implement unrelated features to force a pass.
   Fix task-related failures within authorised scope and rerun affected
   checks. If files change after validation, rerun checks they affect.
   Report skipped or TODO tests without claiming they passed.
6. Review and stage only intended files, inspect git diff --cached, and run
   git diff --cached --check. Exclude secrets, generated caches and unrelated
   changes. Do not discard previously staged work.
7. Use Conventional Commits: <type>(<optional scope>): <imperative summary>.
   Choose a suitable type: feat, fix, refactor, test, docs, style, or chore.
   Mark breaking changes with ! and a BREAKING CHANGE: body. Keep subjects
   within 72 characters and describe the whole commit.
8. Commit only after all applicable checks pass. Report hash, subject, checks and
   remaining TODO tests. Do not push unless explicitly requested.
