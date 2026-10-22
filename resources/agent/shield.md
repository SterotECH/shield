# Shield reference for host applications

## What Shield is and is not

Shield (`stero/shield`) is PHP intrusion detection and prevention middleware.
Today it has one rule, `sensitive-path`, for honeypot paths such as `.env` and
`.git`. It is not a full WAF. It is supported and tested on Laravel 13 only,
with PHP 8.5 or later and ext-mbstring.

## Install

For repository development, install all six locked Composer dependency sets
and retain each package's `phpunit.xml`: Pest must run even when PHP assertions
are disabled, without generating its fallback XML inside an assertion.
Run `npm ci` to enable Husky.
`npm run check:commit` validates manifests
and formatting; `npm run check:push` runs the full Composer checks and
dependency audits. Local PHP tooling uses lerd when available. GitHub CI
also runs Redis integration tests and scans Git history for secrets. See
the repository README for setup and required branch protection checks.
Releases use Release Please: Conventional Commits propose patch (`fix`),
minor (`feat`) or major (`!` / `BREAKING CHANGE`) releases. Merge its release
PR after CI; the Release workflow creates the tag and GitHub Release after
main passes CI. An exact version uses a `Release-As:` commit footer. See
the README for the required `RELEASE_PLEASE_TOKEN` secret and Packagist setup.

```sh
composer require stero/shield
php artisan shield:install
```

The installer publishes configuration, prepares storage and publishes agent
instructions. It never edits `.env`. Tell the user which lines to add:

```dotenv
SHIELD_ENABLED=true
SHIELD_MODE=detect
SHIELD_REDIS_DB=5
SHIELD_TIMING=true
```

Configure Redis and the allowlist before enabling Shield. Do not commit `.env`.

## Env names and defaults

| Name                   | Default                                                         |
|------------------------|-----------------------------------------------------------------|
| SHIELD_ENABLED         | `false`                                                         |
| SHIELD_MODE            | `detect`                                                        |
| SHIELD_ALLOWLIST_FILE  | `storage/shield/allowlist.txt` in the app                       |
| SHIELD_ALLOWLIST_EXTRA | Empty comma-separated list                                      |
| SHIELD_REDIS_HOST      | Laravel `database.redis.default.host`, falling back to loopback |
| SHIELD_REDIS_PORT      | Laravel `database.redis.default.port`, falling back to `6379`   |
| SHIELD_REDIS_PASSWORD  | Laravel `database.redis.default.password`, otherwise `null`     |
| SHIELD_REDIS_DB        | `5`                                                             |
| SHIELD_TIMING          | `false`                                                         |

The framework-neutral Settings defaults use loopback, port `6379` and a null
password. Laravel supplies its configured Redis defaults first.

## Redis

Redis needs ext-redis. Use database 5, never database 0 or the cache database.
`cache:clear` flushes a whole database and would erase every ban. Without a
working Redis the store is NullStore, bans do not persist and `shield:status`
reports unhealthy when Shield is enabled.

## Rollout

Start with `SHIELD_ENABLED=true` and `SHIELD_MODE=detect`. Read events daily.
Enable `SHIELD_TIMING=true` to collect samples. Run `php artisan shield:timing`
on day 2 and day 7: p95 inline must be under 5 ms. Switch to enforce only
after a week of real traffic with no false positives.

## Commands

- `php artisan shield:install`: publish config, prepare storage and agent files;
  never edit `.env`.
- `php artisan shield:status`: check health; exit 1 when enabled but unhealthy.
- `php artisan shield:timing`: report PASS or FAIL against the 5 ms p95 budget.
  No samples means there is no timing proof yet.

Events are JSON lines in `storage/shield/events.jsonl`. Read them with:

```sh
tail -n 50 storage/shield/events.jsonl | jq .
tail -f storage/shield/events.jsonl | jq .
```

Laravel includes `php artisan shield:logs`, a paginated table using the configured
`shield.events_path`. It reads at most the final 5000 physical lines and shows
15 rows per page, newest first. Use `--page` and `--per-page` to navigate.
Filters: `--ip`, `--rule`, `--severity` (exact), `--verdict=blocked|would_block|any`,
`--tenant`, `--since=15m|2h|7d` and `--path` (case insensitive text).
Use `--group` for incidents separated by gaps over 60 seconds, `--full` for
complete unwrapped paths (these may exceed the terminal width), `--file` to
override the log path, and `--json` for an unpaginated array of original events.
Interactive terminals offer paging, filters, row inspection and group toggling.
Piped output and `--no-interaction` render one page without a menu.
The runtime namespace is `Stero\Shield\Runtime`; the adapter namespace is
`Stero\Shield\Laravel`.
Other frameworks can use `vendor/bin/shield events|tail|stats --file=/path/to/events.jsonl`.

## Event fields and meaning

| Field       | Meaning                                                        |
|-------------|----------------------------------------------------------------|
| ts          | Event timestamp                                                |
| tenant      | Tenant identifier, if available                                |
| user        | User identifier, if available                                  |
| ip          | Client IP supplied by the web server                           |
| method      | HTTP method                                                    |
| path        | Request path                                                   |
| rule        | Rule identifier, currently `sensitive-path`                    |
| severity    | Finding severity                                               |
| would_block | True for a blocking finding in both detect and enforce modes   |
| blocked     | True only when enforce actually blocks during the inline phase |
| mode        | `detect` or `enforce`                                          |
| message     | Short finding description                                      |
| evidence    | Short path or matched pattern snippet, at most 200 characters  |

`would_block` is the tuning signal in both modes; it does not instruct the
host to block. `blocked` is true only in enforce mode on the inline phase.

## Behaviour agents must preserve

- Fail open: a Shield bug must never take the app down.
- Blocked and banned clients get 404 with `Cache-Control: no-store, private`,
  never 403. Bans always expire and escalate for repeat offences.
- Only `banWorthy` findings, such as honeypot paths, cause bans.
- Allowlisted IPs, invalid IPs and the unknown client address (four zero
  octets) are never banned or blocked.
- Never log bodies, cookies, Authorization headers, tokens or form values.
- The middleware is prepended globally so it runs before tenancy and sessions.

## Client IP

Nginx must restore the real IP. PHP never reads `X-Forwarded-For` or
`CF-Connecting-IP` in Shield's Laravel integration. If every event shows the
same IP, or a Cloudflare address, fix Nginx, not PHP. Nginx rejects a second
`real_ip_header`, so check existing `conf.d` files before adding one.

## Common tasks

### A real user got a 404 from Shield

1. Read the event and check `rule` and `evidence` to confirm Shield caused it.
2. Do not edit vendor code or core defaults. Set `sensitive_paths` in
   `config/shield.php`.
3. A custom list REPLACES the defaults. Copy this full default list and remove
   only the offending needle:

```php
'sensitive_paths' => [
    '.env', '.git', '.svn', '.ds_store', 'wp-login.php', 'wp-admin',
    'phpmyadmin', 'phpinfo.php', 'vendor/phpunit', 'storage/logs',
    'backup.sql', 'dump.sql',
],
```

4. If the problem is one client rather than one path, allowlist the IP instead.
5. Run `php artisan shield:status`, then verify the affected request and events.

### Allow an IP or CIDR

1. Set `SHIELD_ALLOWLIST_EXTRA` to a comma list, for example
   `192.0.2.10,198.51.100.0/24`, or edit the configured allowlist file.
2. The file takes one entry per line and `#` for comments.
3. Invalid entries are dropped. Run `php artisan shield:status` to check them.

### Unban an IP

There is no unban command. Verify the installed `RedisStore` key shape and
configured prefix first. With the default `shield:` prefix, its `ban()` and
`isBanned()` methods use `shield:ban:` followed by the IP. For the documentation
address below, delete only that ban key in Redis database 5:

```sh
redis-cli -n 5 DEL shield:ban:192.0.2.10
```

Use the configured Redis connection and authenticate securely if needed.
Never flush the database. Verify the key before deleting it. The offence
counter is separate, so deleting the ban does not reset escalation.

### Skip paths

Set `skip_prefixes` in `config/shield.php`. The defaults are `/up`, `/build/`
and `/storage/`. Entries are case-sensitive path prefixes. Review the scope
carefully, then test requests and check events.

### Add a detection rule

Do not add detection rules in a host app. Add them in the Shield repository
with at least one attack file and one normal file in `tests/corpus`.

## Do not

Do not edit files in vendor, disable fail-open, ban on rate-based signals,
return 403, point Shield at Redis database 0, commit `.env` values or widen
the claim of supported frameworks.

## Troubleshooting

- `shield:status` unhealthy: Redis unreachable gives NullStore; check Redis
  connection settings and ext-redis. Check that the events folder is writable.
- Redis database 0 warning: use dedicated database 5, away from the cache.
- Events show the unknown IP (four zero octets): the real IP was not restored.
  Fix Nginx before enabling enforcement.
