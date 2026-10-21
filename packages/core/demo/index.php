<?php

declare(strict_types=1);

use Shield\Demo\DemoFileStore;
use Stero\Shield\Engine;
use Stero\Shield\EngineConfig;
use Stero\Shield\Events\JsonLinesSink;
use Stero\Shield\Http\RequestFactory;
use Stero\Shield\Mode;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\SensitivePathRule;

// Convert warnings to exceptions so the outer boundary can fail open quietly.
set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});
$responded = false;
try {
    require __DIR__ . '/../vendor/autoload.php';

    $directory = __DIR__ . '/.state';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create demo state');
    }
    $store = new DemoFileStore($directory . '/store.json');
    $eventsPath = $directory . '/events.jsonl';
    foreach ([$directory . '/store.json', $eventsPath] as $path) {
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot create demo file');
        }
        fclose($handle);
    }
    $server = [];
    foreach ($_SERVER as $key => $value) {
        if (is_string($key)) {
            $server[$key] = $value;
        }
    }
    $query = [];
    foreach ($_GET as $key => $value) {
        $query[(string) $key] = $value;
    }
    $request = RequestFactory::fromGlobals($server, $query, file_get_contents('php://input') ?: '');
    header('Content-Type: text/plain; charset=UTF-8');
    if (getenv('SHIELD_DEMO') === '1') {
        if ($request->path === '/__reset') {
            $store->clear();
            echo 'reset';

            return;
        }
        if ($request->path === '/__events') {
            $lines = file($eventsPath, FILE_IGNORE_NEW_LINES);
            echo implode("\n", array_slice($lines === false ? [] : $lines, -20));

            return;
        }
    }
    $mode = getenv('SHIELD_MODE') === 'enforce' ? Mode::Enforce : Mode::Detect;
    $engine = new Engine([new SensitivePathRule()], $store, new JsonLinesSink($eventsPath), new EngineConfig($mode));
    $decision = $engine->inspect($request, Phase::Inline);
    if ($decision->blocked) {
        http_response_code($decision->responseStatus ?? 404);
        echo 'Not Found';

        exit;
    }
    header('X-Shield-Would-Block: ' . ($decision->wouldBlock ? '1' : '0'));
    echo 'hello';
    $responded = true;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // The CLI server has no FastCGI response-finishing function.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
    $engine->inspect($request, Phase::Deferred);
} catch (Throwable) {
    if (!$responded) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Shield-Would-Block: 0');
        echo 'hello';
    }
} finally {
    restore_error_handler();
}
