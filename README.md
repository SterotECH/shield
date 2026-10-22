# Shield

Shield is a framework-agnostic intrusion detection and prevention layer for PHP
web apps. It inspects normalised requests, records security findings and can
block requests and temporarily ban clients when enforcement is enabled.

## Status

Pre-1.0. Detect-only by default. The API may change. Shield has not been
independently audited. Use at your own risk.

## Install

Requires PHP 8.5 or later and ext-mbstring. Redis storage also needs ext-redis.

```sh
composer require stero/shield
```

This command requires a release available through Packagist. Until then, add
the Git repository to your application's Composer configuration and use the
development branch:

```sh
composer config repositories.shield vcs https://github.com/sterotech/shield
composer require 'stero/shield:dev-main'
```

The package includes the core engine, Redis store, framework-neutral runtime
and Laravel 13 adapter. Laravel discovers the service provider automatically;
middleware is registered when Shield is enabled. Other applications can call
the engine explicitly. The CLI viewer is used from a repository clone; see
"View events" below.

## How it works

1. RequestFactory builds a NormalizedRequest from inputs supplied by the host.
2. Engine runs rules and records their findings through an event sink.
3. Phase selects Inline before the app or Deferred after sending the response.
4. Mode selects detection or enforcement; detection never blocks.
5. Decision tells the host what would block and whether it should block now.

## Use in a plain PHP application

After installing the package in your application's root, create `public/` and
save this as `public/index.php`. It follows the flow in
`packages/core/demo/index.php`. The example records events to a file outside
the public directory and uses an in-memory store for a first local run.

```php
<?php

declare(strict_types=1);

use Stero\Shield\Engine;
use Stero\Shield\EngineConfig;
use Stero\Shield\Events\JsonLinesSink;
use Stero\Shield\Http\RequestFactory;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\SensitivePathRule;
use Stero\Shield\Store\ArrayStore;

require dirname(__DIR__) . '/vendor/autoload.php';

$request = RequestFactory::fromGlobals($_SERVER, $_GET, '');
$store = new ArrayStore();
$sink = new JsonLinesSink(dirname(__DIR__) . '/var/shield/events.jsonl');
$engine = new Engine(
    [new SensitivePathRule()],
    $store,
    $sink,
    new EngineConfig(),
);

$decision = $engine->inspect($request, Phase::Inline);
if ($decision->blocked) {
    http_response_code($decision->responseStatus ?? 404);
    echo 'Not Found';
    exit;
}

// Replace this response with your application's request handler.
header('X-Shield-Would-Block: ' . ($decision->wouldBlock ? '1' : '0'));
echo 'hello';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
$engine->inspect($request, Phase::Deferred);
```

Run the example from your application's root:

```sh
php -S 127.0.0.1:8080 -t public public/index.php
```

In another terminal:

```sh
curl -i http://127.0.0.1:8080/hello
curl -i http://127.0.0.1:8080/.env
cat var/shield/events.jsonl
```

Both requests return `200` and `hello` in detection mode. `/hello` has
`X-Shield-Would-Block: 0`; `/.env` has `X-Shield-Would-Block: 1` and records a
`sensitive-path` event. These are example headers set by your app, not headers
the engine sends itself. The development server is for local use only.

`SensitivePathRule` is an Inline rule for known probe paths, including `/.env`,
`/.git` and WordPress login paths. It ignores `/.well-known/`. Rules are opt-in:
constructing an Engine does not register rules automatically.

The empty body argument is intentional: this rule only needs the path. Keep
request-body handling in your application. Pass raw request inputs to
RequestFactory; do not decode the path first. If you use a reverse proxy,
pass its exact IP addresses as `trustedProxies`. Forwarded client IPs are
ignored unless the direct peer is trusted.

Run Inline before your application handler. Run Deferred through your host's
after-response or termination hook. The example calls `fastcgi_finish_request`
where available; PHP's development server has no equivalent, so its Deferred
call runs after generating the output. Never send a second response from
Deferred.

## Enable enforcement

Review recorded events in detection mode first. To enforce the rules supplied
to an engine, import `Stero\Shield\Mode` and replace `new EngineConfig()` with:

```php
new EngineConfig(mode: Mode::Enforce)
```

The host must honour `$decision->blocked` and return
`$decision->responseStatus`, normally `404`, before running its handler.
`wouldBlock` is also set in detection mode; it is not an instruction to block.
`findings` contains the rule results. `banForSeconds` reports a newly written
ban; `alreadyBanned` reports an existing ban.

Only ban-worthy findings create bans. The default initial ban is one day;
repeat offences double it up to seven days. Allowlisted IPs, invalid IPs and
`0.0.0.0` are never banned or blocked. An allowlist can be passed as exact IPs
or CIDRs through `EngineConfig(allowlist: [...])`.

`ArrayStore` and `InMemorySink` keep data only in their own instances. In a
normal PHP request lifecycle they do not preserve bans, counters or events
between requests. Use Redis for persistent bans and counters, and a durable
sink such as JsonLinesSink for events.

## Persist bans and counters with Redis

Install ext-redis and provide a reachable Redis server. In the PHP example,
add these imports and replace `$store = new ArrayStore();` with the following:

```php
use Stero\Shield\Redis\NullStore;
use Stero\Shield\Redis\PhpRedisClient;
use Stero\Shield\Redis\RedisConnector;
use Stero\Shield\Redis\RedisStore;

$connection = RedisConnector::connect(
    getenv('REDIS_HOST') ?: '127.0.0.1',
    (int) (getenv('REDIS_PORT') ?: 6379),
    getenv('REDIS_PASSWORD') ?: null,
);
$store = $connection === null
    ? new NullStore()
    : new RedisStore(new PhpRedisClient($connection), 'my-app:shield:');
```

Use a different prefix for each application and create a fresh RedisStore for
each request, including in long-running workers. Connection and read timeouts
default to 100 ms. Connection failure returns null; NullStore keeps the app
running without stored bans or counters. RedisStore stops attempting Redis
operations for the rest of a request after an operation fails. A failed ban
write does not undo an enforcement decision for the current request.

Keep event files outside the public directory, grant the PHP process write
access and arrange log rotation. Shield does not rotate files. Rules, stores
and sinks are designed to fail open, so check that events are actually being
written before relying on them.

## Use Shield with Laravel 13

Install Shield in a Laravel 13 application with Composer:

```sh
composer require stero/shield
php artisan shield:install
```

Laravel discovers the Shield service provider automatically. The install
command publishes `config/shield.php` and creates `storage/shield` with
permissions `0775`. It prints suggested flags and never edits `.env`. Configure
the flags you need:

```dotenv
SHIELD_ENABLED=false
SHIELD_MODE=detect
SHIELD_REDIS_HOST=127.0.0.1
SHIELD_REDIS_PORT=6379
SHIELD_REDIS_PASSWORD=
SHIELD_REDIS_DB=5
SHIELD_ALLOWLIST_FILE=/absolute/path/to/storage/shield/allowlist.txt
SHIELD_ALLOWLIST_EXTRA=
SHIELD_TIMING=false
```

Shield is off by default. Enable it after reviewing the settings with
`SHIELD_ENABLED=true`; leave `SHIELD_MODE=detect` while checking events. Run
`php artisan shield:status` to check Redis, event storage and allowlist status.
The command exits with status 1 when enabled Shield cannot use its store or
write to the events folder. Set `SHIELD_ENABLED=false` to switch Shield off.

Events are written as JSON Lines to `storage/shield/events.jsonl` by default.
Read them with the separate Shield CLI, for example
`bin/shield events --file=storage/shield/events.jsonl`, or follow new events
with `bin/shield tail --file=storage/shield/events.jsonl`. Enable
`SHIELD_TIMING=true` and run `php artisan shield:timing` to review sampled
latency.

Behind Cloudflare or another proxy, configure the web server to restore the
real client IP before PHP receives the request. The Laravel adapter never
reads `X-Forwarded-For` or other forwarded client IP headers.

## Agent files

`php artisan shield:install` publishes a shared Shield skill at
`.agents/skills/shield/SKILL.md`, the skill location used by Codex and OpenCode.
All formats use the same reference shipped in `resources/agent/shield.md`.
Use `--agents=claude` for a Claude skill, `--agents=agents` for a managed
`AGENTS.md` summary, `--agents=cursor` for a Cursor rule, or `--agents=copilot`
for Copilot instructions. Comma lists select several; `--agents=all` publishes
all five and `--agents=none` skips agent files.

Whole files containing the `shield-agent-file` marker are regenerated on
upgrade. Unmarked user files are skipped unless `--force-agents` is supplied.
Only the `shield:begin` to `shield:end` block is managed in `AGENTS.md`;
malformed blocks are left untouched and reported as failures.
Run `shield:install --agents=...` again after upgrading to refresh your targets.

## View events

The event viewer is included with `stero/shield`. In Laravel, it automatically
uses the configured `shield.events_path`:

```sh
php artisan shield:logs
php artisan shield:logs --per-page=20 --severity=high
php artisan shield:logs --group
php artisan shield:logs --json
```

`shield:logs` reads the final 5000 log lines and shows 15 rows per page, newest
first. Interactive terminals offer paging, filters, row inspection and incident
grouping. Use `--page` and `--per-page` for scripted paging. Filters are
`--since=15m|2h|7d`, `--rule`, `--ip`, `--tenant`, `--severity` (exact),
`--verdict=blocked|would_block|any` and `--path` (case insensitive text).
`--group` combines events for the same IP and rule until a gap exceeds 60 seconds.
`--full` prints complete paths without wrapping, even when wider than the
terminal. `--json` exports all filtered events as one array with original keys,
ignoring grouping and pagination. `--file` overrides `shield.events_path`.
Piped output and `--no-interaction` render once without prompts. Output is plain
text. The namespaces are `Stero\Shield\Runtime` and `Stero\Shield\Laravel`.

For any framework, the same viewer is available as a Composer binary:

```sh
vendor/bin/shield events --file=/absolute/path/to/events.jsonl
vendor/bin/shield stats --file=/absolute/path/to/events.jsonl --since=24h
vendor/bin/shield tail --file=/absolute/path/to/events.jsonl
```

Use `--severity=high` or `--would-block` with `events` to narrow the results.
Set `SHIELD_EVENTS` instead of repeating `--file`. Stop `tail` with Ctrl-C.
Future framework adapters can delegate to `Stero\Shield\CLI\Application`,
as Laravel does, passing their configured events path. Laravel is currently
the only framework adapter supplied.

## Run the demo

From a repository clone:

```sh
cd packages/core
composer install
SHIELD_DEMO=1 composer demo
```

Visit `http://localhost:8080/.env`. Detection returns `hello`; events are
written to `packages/core/demo/.state/events.jsonl`. Restart with
`SHIELD_DEMO=1 SHIELD_MODE=enforce composer demo` to try enforcement locally.

## Run the tests

Use PHP 8.5 with mbstring and redis, Composer 2, and Node.js 24 or later.
Install the locked dependencies from the repository root:

```sh
for directory in . packages/core packages/redis packages/runtime packages/cli packages/laravel; do
    composer --working-dir="$directory" install --no-interaction --prefer-dist
done
npm ci
```

`npm ci` activates Husky for this clone. Pre-commit checks all Composer
manifests and PHP formatting without rewriting files. Pre-push runs the
full suite, PHPStan, Pint, the installation smoke test, and Composer and npm
dependency audits. Both hooks also test the gate's failure handling. Run
`npm run check:push` manually for the same gate. Local PHP commands use lerd
when available; otherwise they use Composer directly.

Core tests include the attack and normal request corpus. Redis integration
tests require `SHIELD_TEST_REDIS=host:port` pointing to a disposable Redis
instance; otherwise they skip locally. When using a container runtime,
set this variable inside the container, using a hostname it can reach.

GitHub CI runs on pushes, pull requests, manual dispatch and weekly. It runs
the full gate on PHP 8.5 with a disposable Redis service, plus a Gitleaks scan
of Git history with secrets redacted. Dependabot proposes weekly dependency
updates. CI itself needs no publishing credentials.

In GitHub branch protection for the default branch, require pull requests
and the checks `Tests, analysis and dependency audit` and `Secret scan`,
and disallow bypasses and force pushes. Hooks alone can be bypassed; these
repository settings must be enabled by a repository administrator after
the workflow has run. Enable GitHub secret scanning and push protection
in the repository's security settings as well.

## Releases

After CI succeeds on a push to `main`, Release Please creates or updates a
release PR with a proposed version and changelog. Review that PR, wait for
its CI checks, then merge it. After the merged commit passes CI, the Release
workflow creates the `vX.Y.Z` tag and GitHub Release. No manual tagging is
needed. The baseline for this setup is the existing `v0.2.1` tag.

Choose the version through Conventional Commits in the commits merged to
`main` (or the squash commit message):

| Commit | Proposed change | From 0.2.1 |
| --- | --- | --- |
| `fix: correct path matching` | Patch | 0.2.2 |
| `feat: add an adapter` | Minor | 0.3.0 |
| `feat!: change the public API` | Major | 1.0.0 |

`BREAKING CHANGE:` in a commit footer also requests a major release. Docs,
tests and chores alone do not normally start a release. To request an exact
version, include a `Release-As: 0.3.0` footer in a merged Conventional Commit.
Keep a blank line between the subject and footer. The release PR shows the
result before anything is published. The Release workflow can also be run
manually on `main` to retry automation; version selection stays in commits.

One-time setup: create a fine-grained personal access token restricted to
this repository, with Contents, Pull requests and Issues read/write access,
and save it as the Actions secret `RELEASE_PLEASE_TOKEN`. Issues permission
allows Release Please to manage its lifecycle labels. Give the token an
expiry and rotate it before expiry. The built-in `GITHUB_TOKEN` is unsuitable
here because PRs it creates do not trigger the required CI workflows.
Branch protection must require the CI checks described above before a
release PR can merge. Keep the Release workflow and its configuration under
review because they control publication.

Ensure Packagist's GitHub webhook/auto-update is enabled for `stero/shield`
so new tags become Composer versions. This workflow publishes the GitHub
release; Packagist receives tags through that integration.

See [SECURITY.md](SECURITY.md) for vulnerability reporting and [LICENSE](LICENSE)
for the MIT licence.
