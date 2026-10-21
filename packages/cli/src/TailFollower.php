<?php

declare(strict_types=1);

namespace Stero\Shield\CLI;

use Closure;

/** Polls an open log, reopening from the start after rotation or truncation. */
final class TailFollower
{
    /** @var resource|null */
    private $handle = null;

    private bool $initial = true;
    private int $size = 0;

    public bool $waiting = false;

    public readonly JsonLineReader $reader;

    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    /** @param (callable(int): void)|null $sleeper */
    public function __construct(private readonly string $path, private readonly int $lines = 20, ?callable $sleeper = null)
    {
        $this->reader = new JsonLineReader();
        $this->sleeper = $sleeper === null ? static function (int $microseconds): void {
            usleep($microseconds);
        } : Closure::fromCallable($sleeper);
    }

    /** @return list<array{raw: string, event: array<string, mixed>}> */
    public function poll(): array
    {
        clearstatcache(true, $this->path);
        if (!file_exists($this->path)) {
            $this->waiting = true;

            return [];
        }
        $this->waiting = false;
        $stat = @stat($this->path);
        if ($this->handle !== null) {
            $opened = fstat($this->handle);
            $offset = ftell($this->handle);
            if ($stat !== false && $opened !== false && $offset !== false
                && ($stat['ino'] !== $opened['ino'] || $stat['dev'] !== $opened['dev'] || $stat['size'] < $offset || $stat['size'] < $this->size)) {
                fclose($this->handle);
                $this->handle = null;
            }
        }
        $this->size = $stat === false ? 0 : $stat['size'];
        if ($this->handle === null) {
            $this->handle = $this->reader->open($this->path);
            if ($this->initial) {
                $this->initial = false;

                return $this->reader->lastFrom($this->handle, $this->lines);
            }
        }

        return array_values(iterator_to_array($this->reader->readFrom($this->handle), false));
    }

    public function pause(): void
    {
        ($this->sleeper)(500_000);
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }
}
