<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime;

use Throwable;

final class TimingRecorder
{
    /** @var callable(int): int */
    private $sampler;

    /** @param null|callable(int): int $sampler */
    public function __construct(
        private readonly string $path,
        private readonly int $sampleEvery,
        ?callable $sampler = null,
    ) {
        $this->sampler = $sampler ?? static fn(int $limit): int => random_int(1, $limit);
    }

    public function record(float|int $inlineMicros, float|int $deferredMicros): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            $every = $this->sampleEvery > 0 ? $this->sampleEvery : 20;
            if (($this->sampler)($every) !== 1) {
                return;
            }
            $directory = dirname($this->path);
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                return;
            }
            $line = json_encode([
                'ts' => gmdate(DATE_ATOM),
                'inline_us' => $inlineMicros,
                'deferred_us' => $deferredMicros,
            ], JSON_THROW_ON_ERROR);
            @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
        } finally {
            restore_error_handler();
        }
    }
}
