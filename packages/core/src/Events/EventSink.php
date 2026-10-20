<?php

declare(strict_types=1);

namespace Shield\Core\Events;

/**
 * Where events go. The core never knows or cares whether that is a file,
 * a queue, a database or a log service.
 *
 * CONTRACT
 * - record() must be fast and must NEVER throw. Catch everything inside.
 *   A failing sink must not break a user request.
 * - No framework logger inside the core. Host apps may write their own sink
 *   (for example one that pushes to a Laravel queue) in their own codebase.
 */
interface EventSink
{
    public function record(SecurityEvent $event): void;
}
