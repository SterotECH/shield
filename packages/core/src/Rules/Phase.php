<?php

declare(strict_types=1);

namespace Shield\Core\Rules;

/**
 * Inline:   runs before the app handles the request. Must be very cheap
 *           (path checks, counters). Budget: a few milliseconds in total.
 * Deferred: runs after the response is sent. Heavy work goes here (body
 *           scanning, full pattern sets). The user never waits for it.
 */
enum Phase: string
{
    case Inline = 'inline';
    case Deferred = 'deferred';
}
