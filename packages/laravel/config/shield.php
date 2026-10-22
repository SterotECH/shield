<?php

declare(strict_types=1);

$redisPort = env('SHIELD_REDIS_PORT', config('database.redis.default.port', 6379));
$redisDatabase = env('SHIELD_REDIS_DB', 5);

return [
    'enabled' => env('SHIELD_ENABLED', false),
    'mode' => env('SHIELD_MODE', 'detect'),
    'events_path' => storage_path('shield/events.jsonl'),
    'allowlist_file' => env('SHIELD_ALLOWLIST_FILE', storage_path('shield/allowlist.txt')),
    'allowlist_extra' => env('SHIELD_ALLOWLIST_EXTRA', ''),
    'redis' => [
        'host' => env('SHIELD_REDIS_HOST', config('database.redis.default.host', '127.0.0.1')),
        'port' => is_numeric($redisPort) ? (int) $redisPort : 6379,
        'password' => env('SHIELD_REDIS_PASSWORD', config('database.redis.default.password')),
        'database' => is_numeric($redisDatabase) ? (int) $redisDatabase : 5,
        'prefix' => 'shield:',
    ],
    'skip_prefixes' => ['/up', '/build/', '/storage/'],
    'timing' => [
        'enabled' => env('SHIELD_TIMING', false),
        'sample_every' => 20,
        'path' => storage_path('shield/timing.jsonl'),
    ],
    'auto_register_middleware' => true,
];
