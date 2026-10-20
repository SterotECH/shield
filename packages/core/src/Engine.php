<?php

declare(strict_types=1);

namespace Shield\Core;

use Shield\Core\Events\EventSink;
use Shield\Core\Http\NormalizedRequest;
use Shield\Core\Rules\Phase;
use Shield\Core\Rules\Rule;
use Shield\Core\Store\Store;

/**
 * Runs the rules, applies the mode, manages bans, records events.
 * The only class that knows about Mode.
 *
 * The host app calls inspect() twice per request:
 *   1. Phase::Inline   before the app runs (cheap rules only)
 *   2. Phase::Deferred after the response is sent (heavy rules)
 *
 * IMPLEMENTATION NOTES for inspect(), in this order
 * 1. If the client IP is allowlisted, still run the rules and record events,
 *    but never ban or block.
 * 2. Inline phase only: ask Store::isBanned($ip). If banned, return a
 *    Decision with alreadyBanned true. It is blocked only in Enforce mode,
 *    status = config->blockStatus. Record one event with rule id "banned-ip".
 *    Stop here, do not run rules.
 * 3. Run only the rules whose phase() equals the $phase argument. Collect
 *    every non-null Finding. In the Inline phase you may stop at the first
 *    finding with banWorthy true to save time.
 * 4. wouldBlock = at least one finding has banWorthy true.
 * 5. In Enforce mode, when wouldBlock: blocked = true, responseStatus =
 *    config->blockStatus, ban the IP for banDuration($ip) seconds.
 *    In Detect mode: never block, never ban, but still set wouldBlock.
 * 6. Record one SecurityEvent per finding through the sink.
 * 7. Return the Decision.
 *
 * FAIL OPEN: wrap rule execution, store calls and sink calls in try/catch.
 * A bug in this layer must never take the app down. On any exception,
 * return Decision::allow(). Optionally report through error_log().
 */
final class Engine
{
    /** @param iterable<Rule> $rules */
    public function __construct(
        private iterable $rules,
        private Store $store,
        private EventSink $sink,
        private EngineConfig $config,
    ) {
    }

    public function inspect(NormalizedRequest $request, Phase $phase): Decision
    {
        throw new \LogicException('Not implemented');
    }

    /**
     * TODO: return true if $ip equals an allowlist entry or falls inside a
     * CIDR entry. Support IPv4 and IPv6 (inet_pton, compare masked bytes).
     * Unit test with 192.0.2.10, 192.0.2.0/24 and an IPv6 example.
     */
    private function isAllowlisted(string $ip): bool
    {
        throw new \LogicException('Not implemented');
    }

    /**
     * TODO: escalating ban. Keep a counter in the store under
     * "ban-count:{ip}" (increment with a 30 day window). Duration is
     * banSeconds * 2^(count - 1), capped at maxBanSeconds.
     */
    private function banDuration(string $ip): int
    {
        throw new \LogicException('Not implemented');
    }
}