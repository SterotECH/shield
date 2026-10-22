<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime;

use Stero\Shield\Mode;

/** Framework-neutral, validated Shield configuration. */
final readonly class Settings
{
    /**
     * @param list<string> $allowlistExtra
     * @param array{host: string, port: int, password: string|null, database: int, prefix: string} $redis
     * @param list<string> $skipPrefixes
     * @param array{enabled: bool, sample_every: int, path: string} $timing
     * @param list<string>|null $sensitivePaths
     */
    private function __construct(
        public bool $enabled,
        public Mode $mode,
        public string $eventsPath,
        public string $allowlistFile,
        public array $allowlistExtra,
        public array $redis,
        public array $skipPrefixes,
        public array $timing,
        public ?array $sensitivePaths,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $redis = is_array($config['redis'] ?? null) ? $config['redis'] : [];
        $timing = is_array($config['timing'] ?? null) ? $config['timing'] : [];
        $mode = $config['mode'] ?? null;
        $extra = $config['allowlist_extra'] ?? null;
        if (is_string($extra)) {
            $extra = array_map(
                static fn(string $entry): string => mb_check_encoding($entry, 'UTF-8') ? mb_trim($entry, null, 'UTF-8') : $entry,
                explode(',', $extra),
            );
        }
        $rawSkipPrefixes = $config['skip_prefixes'] ?? ['/up', '/build/', '/storage/'];
        $skipPrefixes = is_array($rawSkipPrefixes) && array_is_list($rawSkipPrefixes)
            ? self::stringList($rawSkipPrefixes)
            : ['/up', '/build/', '/storage/'];
        $skipPrefixes = array_values(array_filter(
            $skipPrefixes,
            static fn(string $prefix): bool => $prefix !== ''
                && str_starts_with($prefix, '/')
                && mb_check_encoding($prefix, 'UTF-8'),
        ));
        $sensitivePaths = $config['sensitive_paths'] ?? null;
        $sensitivePaths = is_array($sensitivePaths)
            && array_is_list($sensitivePaths)
            && count(array_filter($sensitivePaths, 'is_string')) === count($sensitivePaths)
            ? self::stringList($sensitivePaths)
            : null;
        $sampleEvery = $timing['sample_every'] ?? 20;

        return new self(
            enabled: is_bool($config['enabled'] ?? null) ? $config['enabled'] : false,
            mode: $mode === 'enforce' ? Mode::Enforce : Mode::Detect,
            eventsPath: self::stringOr($config['events_path'] ?? null, 'storage/shield/events.jsonl'),
            allowlistFile: self::stringOr($config['allowlist_file'] ?? null, 'storage/shield/allowlist.txt'),
            allowlistExtra: self::stringList($extra),
            redis: [
                'host' => self::stringOr($redis['host'] ?? null, '127.0.0.1'),
                'port' => is_int($redis['port'] ?? null) && $redis['port'] > 0 ? $redis['port'] : 6379,
                'password' => is_string($redis['password'] ?? null) && $redis['password'] !== '' ? $redis['password'] : null,
                'database' => is_int($redis['database'] ?? null) && $redis['database'] >= 0 ? $redis['database'] : 5,
                'prefix' => self::stringOr($redis['prefix'] ?? null, 'shield:'),
            ],
            skipPrefixes: $skipPrefixes,
            timing: [
                'enabled' => is_bool($timing['enabled'] ?? null) ? $timing['enabled'] : false,
                'sample_every' => is_int($sampleEvery) && $sampleEvery > 0 ? $sampleEvery : 20,
                'path' => self::stringOr($timing['path'] ?? null, 'storage/shield/timing.jsonl'),
            ],
            sensitivePaths: $sensitivePaths,
        );
    }

    private static function stringOr(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
