<?php

declare(strict_types=1);

namespace Shield\Core;

use Shield\Core\Rules\Severity;

/**
 * One thing a rule noticed in one request. Immutable, no behaviour.
 *
 * IMPLEMENTATION NOTES
 * - $evidence is the small part of the request that triggered the rule
 *   (for example the matched path). Max 200 characters. Never put bodies,
 *   cookies, tokens or passwords in it.
 * - $banWorthy means: this signal is certain enough that banning the IP is
 *   safe (honeypot paths like /.env). Rate based or fuzzy signals must be
 *   false. Only the Engine decides whether a ban actually happens.
 */
final readonly class Finding
{
    public function __construct(
        public string $ruleId,
        public Severity $severity,
        public string $message,
        public string $evidence = '',
        public bool $banWorthy = false,
    ) {
    }
}
