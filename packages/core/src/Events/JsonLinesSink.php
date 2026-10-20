<?php

declare(strict_types=1);

namespace Stero\Shield\Events;

/**
 * Appends one JSON object per line to a file. Easy to tail, grep, and
 * import later.
 *
 * IMPLEMENTATION NOTES
 * - json_encode($event->toArray()) plus "\n", written with
 *   file_put_contents($path, $line, FILE_APPEND | LOCK_EX).
 * - Create the directory if missing. Swallow all errors (see EventSink).
 * - Log rotation is out of scope here. Use logrotate on the server.
 */
final class JsonLinesSink implements EventSink
{
    public function __construct(private string $path) {}

    public function record(SecurityEvent $event): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            $json = json_encode($event->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                return;
            }
            $line = $json . "\n";
            $directory = dirname($this->path);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                return;
            }
            file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
        } finally {
            restore_error_handler();
        }
    }
}
