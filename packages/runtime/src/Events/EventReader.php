<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime\Events;

/**
 * Read a bounded tail, retaining the original event keys for JSON export.
 * @phpstan-type Event array<string, scalar|null>
 */
final class EventReader
{
    public ?string $reason = null;

    /** @return list<Event> */
    public function read(string $file, int $maxLines = 5000): array
    {
        $this->reason = null;
        $handle = null;
        try {
            if (!is_file($file) || !is_readable($file) || ($handle = @fopen($file, 'rb')) === false) {
                $this->reason = 'Cannot read event file: ' . $file;
                return [];
            }
            if ($maxLines < 1) {
                return [];
            }
            if (fseek($handle, 0, SEEK_END) !== 0 || ($position = ftell($handle)) === false) {
                throw new \RuntimeException('Cannot seek event file');
            }
            $buffer = '';
            $lines = [];
            $atEnd = true;
            while ($position > 0 && count($lines) < $maxLines) {
                $size = min(8192, $position);
                $position -= $size;
                if (fseek($handle, $position) !== 0 || ($chunk = fread($handle, $size)) === false) {
                    throw new \RuntimeException('Cannot read event file');
                }
                $parts = explode("\n", $chunk . $buffer);
                $buffer = array_shift($parts) ?? '';
                foreach (array_reverse($parts) as $line) {
                    if ($atEnd && $line === '') {
                        $atEnd = false;
                        continue;
                    }
                    $atEnd = false;
                    $lines[] = $line;
                    if (count($lines) >= $maxLines) {
                        break;
                    }
                }
            }
            if ($position === 0 && count($lines) < $maxLines && $buffer !== '') {
                $lines[] = $buffer;
            }
            $events = [];
            foreach ($lines as $line) {
                $event = json_decode($line, true);
                if (self::valid($event)) {
                    $events[] = $event;
                }
            }
            return $events;
        } catch (\Throwable) {
            $this->reason = 'Cannot read event file: ' . $file;
            return [];
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /** @phpstan-assert-if-true Event $event */
    public static function valid(mixed $event): bool
    {
        if (!is_array($event)) {
            return false;
        }
        foreach ($event as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                return false;
            }
        }
        foreach (['ts', 'ip', 'rule', 'severity', 'path', 'method', 'mode', 'evidence', 'message'] as $key) {
            if (!isset($event[$key]) || !is_string($event[$key]) || !mb_check_encoding($event[$key], 'UTF-8')) {
                return false;
            }
        }
        foreach (['tenant', 'user'] as $key) {
            if (!array_key_exists($key, $event) || ($event[$key] !== null && !is_string($event[$key]))) {
                return false;
            }
        }
        if (!is_bool($event['blocked'] ?? null) || !is_bool($event['would_block'] ?? null)
            || !in_array($event['severity'], ['low', 'medium', 'high', 'critical'], true)) {
            return false;
        }
        try {
            if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/u', $event['ts']) !== 1) {
                return false;
            }
            new \DateTimeImmutable($event['ts']);
            return \DateTimeImmutable::getLastErrors() === false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param Event $event */
    public static function verdict(array $event): string
    {
        return ($event['blocked'] ?? false) === true ? 'blocked' : (($event['would_block'] ?? false) === true ? 'would_block' : 'watch');
    }
}
