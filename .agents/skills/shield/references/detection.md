# Detection rules, policy and corpus

## Rule today: sensitive-path

Honeypot paths that no real user requests. Phase Inline, Severity High, `banWorthy` true, id `sensitive-path`, message "Request for a sensitive file or folder", evidence is the ORIGINAL path cut to 200 characters (invalid UTF-8 scrubbed first).

Default needles: `.env`, `.git`, `.svn`, `.ds_store`, `wp-login.php`, `wp-admin`, `phpmyadmin`, `phpinfo.php`, `vendor/phpunit`, `storage/logs`, `backup.sql`, `dump.sql`. A custom list replaces the defaults. A host app that legitimately serves one of these passes its own list in config, never by editing the core defaults.

Matching works on a working copy of the already-decoded path: remove `\0`, turn `\` into `/`, lowercase with `mb_strtolower` when valid UTF-8 (else `strtolower` so bad bytes cannot hide a needle), split on `/`, ignore empty segments.
- Single-segment needle: flag when any segment equals it, or starts with it followed by `.` (so `.env.local` and `.env.bak` match).
- Multi-segment needle (`vendor/phpunit`, `storage/logs`): the segments appear consecutively and exactly.
- Only the path is examined.

Must be flagged: `/.env`, `/.ENV`, `/.env.local`, `/app/.env`, `/.env/`, `/.env` with a real null byte before `.jpg`, `/\.env`, `/a/.env/b`, `/.git/config`, `/wp-login.php`, `/phpmyadmin/index.php`, `/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php`, `/storage/logs/laravel.log`, `/backup.sql`, `/.DS_Store`, `"/\xFF/.env"`.

Must NOT be flagged: `/`, `/.well-known/acme-challenge/abc`, `/.well-known/security.txt`, `/environment`, `/envelope`, `/.environment`, `/gitlab-guide`, `/blog/what-is-env`, `/storage/logo.png`, `/api/logs`, `/vendor/phpunit-report`, `/students/env`.

Known behaviour kept on purpose: `/%252eenv` is decoded once and stays a literal `%2eenv` segment, so it is not flagged. A corpus file documents this. Shortcuts like `str_contains($path, '.env')` are wrong because they flag `/.environment` and `/blog/what-is-env` and would ban real users.

Another finding raised by the engine itself: `banned-ip` (High, not banWorthy) when a banned address comes back.

## Planned rules (build only after real traffic data)

- `invalid-encoding`: Phase Inline, Severity Medium, not banWorthy. Fires when path, query or header values are not valid UTF-8. Alerts only, because broken clients also send bad encodings. Possible `ext-intl` Normalizer step if the corpus shows lookalike-character bypasses.
- `login-rate`: counters through `Store::increment` (for example 10 failed logins per IP per minute). Throttle or alert, never ban.
- A small SQL injection set in the Deferred phase, based on the OWASP Core Rule Set patterns, with corpus files proving each pattern.
- Body inspection: off by default (adapters pass an empty body). Capped at 8 to 16 KB when added. Never read bodies until a rule needs them.

## Ban policy

Ban only on `banWorthy` findings. Duration escalates (86400 s, then doubled per repeat, capped at 604800 s, counter window 30 days). Bans always expire. Never ban allowlisted IPs, invalid IPs or `0.0.0.0`. The response to a blocked or banned client is 404 with `Cache-Control: no-store, private`. The allowlist holds the Cloudflare ranges (written weekly by the refresh script) plus any extra IPs from `SHIELD_ALLOWLIST_EXTRA`, so a misconfigured real-IP setup can never ban a Cloudflare address.

## Corpus

One JSON file per request in `tests/corpus/attacks` or `tests/corpus/normal`:

    { "description": "...",
      "request": { "method": "GET", "path": "/.env", "query": {}, "headers": {}, "body": "", "ip": "203.0.113.9" },
      "expect": { "rules": ["sensitive-path"], "would_block": true } }

`request.path_b64` (base64 of raw path bytes) overrides `path` so a file can carry invalid UTF-8. The `CorpusTest` builds every request through the real `RequestFactory` and `Engine` (Detect mode, fresh `ArrayStore` and `InMemorySink` per case, both phases merged). Guards: the dataset is never empty, every file has the required fields, every rule id in `allRules()` is named in at least one attack file. Rule of the project: no rule without at least one attack file and one normal file. Use only documentation IP ranges (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24) in the public repo. Every false positive or miss found in production becomes a corpus file.
