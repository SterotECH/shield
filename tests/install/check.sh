#!/bin/sh
set -eu

repo=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
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
if (count($decision->findings) !== 1 || $decision->findings[0]->ruleId !== "sensitive-path"
    || !$decision->wouldBlock || $decision->blocked || count($sink->events()) !== 1
    || $fallback->isBanned("192.0.2.1")) {
    fwrite(STDERR, "Installation smoke check failed\n");
    exit(1);
}
echo "ok\n";
'
