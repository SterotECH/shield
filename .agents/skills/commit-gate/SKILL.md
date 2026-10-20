---
name: commit-gate
description: Run Shield's required checks and create a Conventional Commit when the user asks to commit. Does not push or publish changes.
---

# Commit gate

Before every commit in this repository:

1. Read AGENTS.md, inspect the working tree and staged diff, and identify
   the changes authorised for the commit. Preserve unrelated work.
2. From packages/core, run Composer scripts test, lint:check, and analyse
   through lerd. Run all three even if one fails, for a complete report.
   lint:check is the read-only Pint check; use lint -- --test if absent.
3. Run git diff --check and composer validate --strict. Inspect declared
   check scripts and run additional checks relevant to changed files.
   For namespace or autoload changes, also run
   composer dump-autoload --optimize --strict-psr.
4. Require every gate to pass. Existing failures still block the commit.
   Report failing commands and causes. Never skip checks, suppress errors,
   weaken configuration, or implement unrelated features to force a pass.
   Fix task-related failures within authorised scope and rerun affected
   checks. If files change after validation, rerun checks they affect.
   Report skipped or TODO tests without claiming they passed.
5. Review and stage only intended files, inspect git diff --cached, and run
   git diff --cached --check. Exclude secrets, generated caches and unrelated
   changes. Do not discard previously staged work.
6. Use Conventional Commits: <type>(<optional scope>): <imperative summary>.
   Choose a suitable type: feat, fix, refactor, test, docs, style, or chore.
   Mark breaking changes with ! and a BREAKING CHANGE: body. Keep subjects
   within 72 characters and describe the whole commit.
7. Commit only after all checks pass. Report hash, subject, checks and
   remaining TODO tests. Do not push unless explicitly requested.
