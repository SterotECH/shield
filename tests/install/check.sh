#!/bin/sh
set -eu

repo=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
archive_list=$(git -C "$repo" archive HEAD | tar -tf -)
for resource in resources/agent/shield.md resources/agent/summary.md; do
    if ! printf '%s\n' "$archive_list" | grep -Fx "$resource" >/dev/null; then
        echo "Composer archive is missing $resource" >&2
        exit 1
    fi
done
php -r '
declare(strict_types=1);
$manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if (($manifest["autoload"]["psr-4"]["Shield\\Laravel\\"] ?? null) !== "packages/laravel/src/"
    || !in_array("Shield\\Laravel\\ShieldServiceProvider", $manifest["extra"]["laravel"]["providers"] ?? [], true)) {
    fwrite(STDERR, "Laravel package autoload or provider discovery is missing\n");
    exit(1);
}
' "$repo/composer.json"
temporary=$(mktemp -d "$repo/tests/install/.check.XXXXXX")
trap 'rm -rf -- "$temporary"' EXIT HUP INT TERM

php -r '
declare(strict_types=1);
file_put_contents($argv[2], json_encode([
    "repositories" => [["type" => "path", "url" => $argv[1], "options" => ["symlink" => true]]],
    "require" => ["stero/shield" => "@dev"],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
' "$repo" "$temporary/composer.json"

cd "$temporary"
composer install --no-interaction --no-progress
php -r '
declare(strict_types=1);
require "vendor/autoload.php";
$sink = new Stero\Shield\Events\InMemorySink();
$engine = new Stero\Shield\Engine(
    [new Stero\Shield\Rules\SensitivePathRule()],
    new Stero\Shield\Store\ArrayStore(),
    $sink,
    new Stero\Shield\EngineConfig(),
);
$request = Stero\Shield\Http\RequestFactory::fromGlobals([
    "REMOTE_ADDR" => "192.0.2.1", "REQUEST_METHOD" => "GET", "REQUEST_URI" => "/.env",
], [], "");
$decision = $engine->inspect($request, Stero\Shield\Rules\Phase::Inline);
$fallback = new Stero\Shield\Redis\NullStore();
$settings = Shield\Runtime\Settings::fromArray(["enabled" => true]);
$runtimeEngine = Shield\Runtime\EngineFactory::create($settings, new Stero\Shield\Store\ArrayStore(), new Stero\Shield\Events\InMemorySink());
$guard = new Shield\Runtime\Guard($settings, $runtimeEngine);
$inspection = $guard->before($request);
if (count($decision->findings) !== 1 || $decision->findings[0]->ruleId !== "sensitive-path"
    || !$decision->wouldBlock || $decision->blocked || count($sink->events()) !== 1
    || count($inspection->decision->findings) !== 1 || !$inspection->decision->wouldBlock
    || $fallback->isBanned("192.0.2.1")) {
    fwrite(STDERR, "Installation smoke check failed\n");
    exit(1);
}
echo "ok\n";
'
