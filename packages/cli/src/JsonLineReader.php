<?php

declare(strict_types=1);

namespace Stero\Shield\CLI;

use Generator;
use JsonException;
use RuntimeException;

/** Streams complete JSON lines; reverse reads retain only one line and a chunk. */
final class JsonLineReader
{
    public int $skipped = 0;

    /** @return resource */
    public function open(string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Cannot read event file: ' . $path);
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read event file: ' . $path);
        }

        return $handle;
    }

    /** @return list<array{raw: string, event: array<string, mixed>}> */
    public function last(string $path, int $count): array
    {
        $handle = $this->open($path);
        try {
            return $this->lastFrom($handle, $count);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Leaves the stream at the last complete newline in the snapshot, so a
     * follower can resume without losing a partial line or a concurrent append.
     *
     * @param resource $handle
     * @return list<array{raw: string, event: array<string, mixed>}>
     */
    public function lastFrom($handle, int $count): array
    {
        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);
        if ($position === false) {
            throw new RuntimeException('Cannot seek event file');
        }
        $rows = [];
        $buffer = '';
        $skipEnd = true;
        $completeEnd = 0;
        try {
            while ($position > 0) {
                $length = min(8192, $position);
                $position -= $length;
                fseek($handle, $position);
                $chunk = fread($handle, $length);
                if ($chunk === false) {
                    throw new RuntimeException('Cannot read event file');
                }
                // Delimiters and offsets are bytes, not UTF-8 text boundaries.
                if ($skipEnd) {
                    $newline = preg_match('/\n[^\n]*\z/', $chunk, $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : false;
                    if ($newline === false) {
                        continue;
                    }
                    $completeEnd = $position + $newline + 1;
                }
                $parts = explode("\n", $chunk . $buffer);
                $buffer = array_shift($parts);
                foreach (array_reverse($parts) as $line) {
                    if ($skipEnd) {
                        $skipEnd = false;
                        if ($count === 0) {
                            return [];
                        }

                        continue;
                    }
                    $row = $this->decode($line);
                    if ($row !== null) {
                        $rows[] = $row;
                        if (count($rows) >= $count) {
                            return array_reverse($rows);
                        }
                    }
                }
            }
            if (!$skipEnd) {
                $row = $this->decode($buffer);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            return array_reverse($rows);
        } finally {
            fseek($handle, $completeEnd);
        }
    }

    /** @return Generator<int, array{raw: string, event: array<string, mixed>}> */
    public function read(string $path): Generator
    {
        $handle = $this->open($path);
        try {
            yield from $this->readFrom($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return Generator<int, array{raw: string, event: array<string, mixed>}>
     */
    public function readFrom($handle): Generator
    {
        while (true) {
            $start = ftell($handle);
            if ($start !== false) {
                fseek($handle, $start);
            }
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            if (!str_ends_with($line, "\n")) {
                if ($start !== false) {
                    fseek($handle, $start);
                }
                break;
            }
            $row = $this->decode(explode("\n", $line, 2)[0]);
            if ($row !== null) {
                yield $row;
            }
        }
    }

    /** @return array{raw: string, event: array<string, mixed>}|null */
    public function decode(string $line): ?array
    {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->skipped++;

            return null;
        }
        $event = [];
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                $event[(string) $key] = $value;
            }
        }

        return ['raw' => $line, 'event' => $event];
    }
}
