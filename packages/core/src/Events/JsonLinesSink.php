<?php

declare(strict_types=1);

namespace Shield\Core\Events;

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
    public function __construct(private string $path)
    {
    }

    public function record(SecurityEvent $event): void
    {
        throw new \LogicException('Not implemented');
    }
}
