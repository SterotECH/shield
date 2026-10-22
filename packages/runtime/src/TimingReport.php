<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime;

use Throwable;

final readonly class TimingReport
{
    public function __construct(
        public int $count,
        public float $inlineP50Ms,
        public float $inlineP95Ms,
        public float $inlineP99Ms,
        public float $deferredP50Ms,
        public float $deferredP95Ms,
        public float $deferredP99Ms,
    ) {}

    public static function fromFile(string $path): self
    {
        try {
            $lines = is_file($path) && is_readable($path) ? @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        } catch (Throwable) {
            $lines = [];
        }
        $inline = [];
        $deferred = [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            try {
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($row)
                    || ! is_string($row['ts'] ?? null)
                    || ! is_numeric($row['inline_us'] ?? null)
                    || ! is_numeric($row['deferred_us'] ?? null)) {
                    continue;
                }
                $inlineValue = (float) $row['inline_us'];
                $deferredValue = (float) $row['deferred_us'];
                if ($inlineValue < 0 || $deferredValue < 0 || ! is_finite($inlineValue) || ! is_finite($deferredValue)) {
                    continue;
                }
                $inline[] = $inlineValue / 1000;
                $deferred[] = $deferredValue / 1000;
            } catch (Throwable) {
            }
        }

        return new self(count($inline), ...self::percentiles($inline), ...self::percentiles($deferred));
    }

    public function passesBudget(float $p95Ms = 5.0): bool
    {
        return $this->inlineP95Ms <= $p95Ms;
    }

    /** @param list<float> $values
     *  @return array{float, float, float}
     */
    private static function percentiles(array $values): array
    {
        if ($values === []) {
            return [0.0, 0.0, 0.0];
        }
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $at = static fn(float $percent): float => $values[max(0, (int) ceil($count * $percent) - 1)];

        return [$at(0.50), $at(0.95), $at(0.99)];
    }
}
