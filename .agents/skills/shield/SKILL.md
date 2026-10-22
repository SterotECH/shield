---
name: shield
description: Full project knowledge for Shield (Composer package stero/shield), Stero's framework-agnostic PHP intrusion detection and prevention layer (IDS/IPS, WAF-style middleware) that protects EduConect and future apps. Use whenever the user mentions Shield, stero/shield, the security layer, IDS or IPS, intrusion detection or prevention, honeypot path bans (.env, .git probes), Engine, Guard, Rule, Decision, Finding, SecurityEvent, shield:status, the Shield CLI viewer, the Laravel adapter, Cloudflare or nginx real client IP for it, Redis bans, detect-only versus enforce mode, usage tracking (DAU, WAU, MAU, online now) for EduConect, or asks for the next task prompt for the coding agent. Also use when writing or reviewing any prompt for a coding agent on this project, even if the user does not say "Shield".
---

# Shield

Shield is Stero's own intrusion detection and prevention layer for PHP web apps. It is one public GitHub repository and one Composer package, `stero/shield` (MIT, copyright Stero Tech Inc.). It sits in front of the application as middleware, starts in detect-only mode, and moves to enforcement rule by rule. First host app: EduConect (multi-tenant school management SaaS for Ghanaian private schools, Laravel, on Hetzner behind Cloudflare with Nginx). It is meant to be reused on every system Stero delivers, and later on other frameworks.

Read `references/status.md` first. It says what is built, what is next and which gotchas already cost time.

## Design decisions that are settled

Do not reopen these unless the user asks. Each has a reason, listed in the reference files.

1. **Detect first, then enforce.** `Mode::Detect` records `would_block` and never blocks. Enforcement is switched on per rule after a week of real traffic. Only `Engine` looks at `Mode`.
2. **One repo, one package, five folders.** `packages/core` (pure PHP engine), `packages/redis` (Redis store), `packages/runtime` (framework-neutral wiring), `packages/laravel` (thin adapter), `packages/cli` (log viewer, not installed with the package). The user explicitly rejected a separate `stero/shield-laravel` package. Sub-package `composer.json` files are for development only.
3. **Core has no framework code.** Only an adapter package may import its own framework. Adapters hold no security logic: they translate a request in and a decision out. Fail-open handling, allowlists, skip prefixes, timing and status checks live in `runtime`.
4. **Fail open.** A bug or outage in Shield must never take the app down. Rules, stores, sinks and the engine never throw into a request.
5. **404, never 403,** for blocked or banned clients, with `Cache-Control: no-store, private` so Cloudflare cannot cache it. Bans always expire (escalating). Only `banWorthy` findings (honeypot paths) cause bans. Rate-based or fuzzy signals alert or throttle.
6. **Never ban** allowlisted IPs, invalid IPs or `0.0.0.0`. Cloudflare ranges are in the allowlist as a safety net.
7. **Redis database 5,** never the Laravel default or cache database, because `cache:clear` flushes a whole database and would erase every ban.
8. **Privacy.** Never log bodies, cookies, Authorization headers, tokens or form values. Evidence is a short path snippet (200 characters). Ghana's Data Protection Act, 2012 (Act 843) applies.
9. **Everything from a request is untrusted** when shown in a terminal: strip control characters, escape markup.
10. **No client IP logic in PHP.** Nginx restores the real IP (`real_ip_header CF-Connecting-IP`). The adapter never reads `X-Forwarded-For` or `CF-Connecting-IP`.

## Which reference to read

| Need | File |
|---|---|
| What exists, what is next, known pitfalls | `references/status.md` |
| Classes, signatures, request flow, runtime, Laravel adapter, CLI, Redis store | `references/architecture.md` |
| Detection rules, matching specs, ban policy, corpus | `references/detection.md` |
| Project rules for agents, self-review, definition of done | `references/rules.md` |
| Writing the next task prompt for the coding agent | `references/task-prompts.md` |
| Hetzner, Cloudflare, Nginx, Redis, deploy, rollout, EduConect facts | `references/infrastructure.md` (private: never commit to the public repo) |

When working inside the repository, the repo's own `CLAUDE.md` and `AGENTS.md` are authoritative for rules. `references/rules.md` is a snapshot for chats that have no repo access.

## How Stero works with this project

- Stero dictates many messages, so expect transcription errors. Known ones: "mbr strings" means `mb_*` functions; "full 3 / full 4" means 403 / 404; ".gate" means `.git`; "Educonrct" or "Educonnect" mean EduConect; "hezer" means Hetzner; "cloaudfare" means Cloudflare; "adaptor" means adapter. Interpret by context and say which reading you chose when it matters.
- The loop is: Claude writes a complete prompt for the coding agent, the agent implements and self-reviews, Stero reports the result (often pasting the agent's summary or terminal output), Claude reviews it critically and gives the next prompt plus a short hands-on review checklist for Stero. Always list what Stero should check himself, with mutation-style checks he can do by hand.
- Stero does not know Go and knows a little Rust, and wants to read and judge security code, so keep code plain PHP and readable. Review the security-critical classes line by line: `RequestFactory`, `Engine`, `Guard`, `Sanitiser`.
- Prompts for the agent are copy-paste code blocks. Commands for Stero's server are separate code blocks with the expected output stated.
- Style for everything written for him: British spelling, no em dashes, conclusion first, short paragraphs, explain trade-offs, push back on weak logic.

## Keeping this skill current

After any milestone, update `references/status.md` (what is done, what is next, new gotchas) and, if rules changed, sync `references/rules.md` with the repo's `CLAUDE.md`. If asked to update the skill: copy it to a writable folder, edit, repackage with the same name.
