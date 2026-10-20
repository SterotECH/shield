<?php

declare(strict_types=1);

namespace Stero\Shield\Events;

/**
 * Keeps events in an array. Used by tests and the demo.
 *
 * record() appends to a private list. events() returns the list.
 */
final class InMemorySink implements EventSink
{
    /** @var list<SecurityEvent> */
    private array $events = [];

    public function record(SecurityEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<SecurityEvent> */
    public function events(): array
    {
        return $this->events;
    }
}
