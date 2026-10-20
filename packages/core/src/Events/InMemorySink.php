<?php

declare(strict_types=1);

namespace Shield\Core\Events;

/**
 * Keeps events in an array. Used by tests and the demo.
 *
 * TODO: record() appends to a private list. events() returns the list.
 * Add reset() if the corpus runner needs a clean sink per case.
 */
final class InMemorySink implements EventSink
{
    public function record(SecurityEvent $event): void
    {
        throw new \LogicException('Not implemented');
    }

    /** @return list<SecurityEvent> */
    public function events(): array
    {
        throw new \LogicException('Not implemented');
    }
}
