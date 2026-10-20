<?php

declare(strict_types=1);

namespace Stero\Shield;

use Stero\Shield\Events\EventSink;
use Stero\Shield\Events\SecurityEvent;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\Rule;
use Stero\Shield\Rules\Severity;
use Stero\Shield\Store\Store;

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
    ) {}

    public function inspect(NormalizedRequest $request, Phase $phase): Decision
    {
        try {
            $allowlisted = $this->isAllowlisted($request->clientIp);
            if (!$allowlisted && $phase === Phase::Inline && $this->store->isBanned($request->clientIp)) {
                $finding = new Finding('banned-ip', Severity::High, 'Client is already banned');
                $blocked = $this->config->mode === Mode::Enforce;
                $decision = new Decision([$finding], true, $blocked, $blocked ? $this->config->blockStatus : null, alreadyBanned: true);
                $this->sink->record(SecurityEvent::fromFinding($request, $finding, $decision, $this->config->mode));

                return $decision;
            }
            if ($this->rules instanceof \Traversable) {
                $this->rules = iterator_to_array($this->rules, false);
            }
            $findings = [];
            $wouldBlock = false;
            foreach ($this->rules as $rule) {
                if ($rule->phase() !== $phase) {
                    continue;
                }
                $finding = $rule->inspect($request);
                if ($finding === null) {
                    continue;
                }
                $findings[] = $finding;
                $wouldBlock = $wouldBlock || $finding->banWorthy;
                if ($phase === Phase::Inline && $finding->banWorthy) {
                    break;
                }
            }
            $blocked = !$allowlisted && $wouldBlock && $this->config->mode === Mode::Enforce;
            $banForSeconds = null;
            if ($blocked) {
                $banForSeconds = $this->banDuration($request->clientIp);
                $this->store->ban($request->clientIp, $banForSeconds);
            }
            $decision = new Decision($findings, $wouldBlock, $blocked, $blocked ? $this->config->blockStatus : null, $banForSeconds);
            foreach ($findings as $finding) {
                $this->sink->record(SecurityEvent::fromFinding($request, $finding, $decision, $this->config->mode));
            }

            return $decision;
        } catch (\Throwable) {
            return Decision::allow();
        }
    }

    /**
     * Return true if $ip equals an allowlist entry or falls inside a
     * CIDR entry. Support IPv4 and IPv6 (inet_pton, compare masked bytes).
     * Unit test with 192.0.2.10, 192.0.2.0/24 and an IPv6 example.
     */
    private function isAllowlisted(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packedIp = inet_pton($ip);
        if ($packedIp === false) {
            return false;
        }
        $ipBytes = unpack('C*', $packedIp);
        if ($ipBytes === false) {
            return false;
        }
        foreach ($this->config->allowlist as $entry) {
            if (!mb_check_encoding($entry, 'UTF-8')) {
                continue;
            }
            $parts = explode('/', $entry, 2);
            if (filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $network = inet_pton($parts[0]);
            if ($network === false) {
                continue;
            }
            $networkBytes = unpack('C*', $network);
            if ($networkBytes === false || count($networkBytes) !== count($ipBytes)) {
                continue;
            }
            $maxBits = count($ipBytes) * 8;
            $prefix = isset($parts[1]) ? filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $maxBits]]) : $maxBits;
            if ($prefix === false) {
                continue;
            }
            $matches = true;
            foreach ($ipBytes as $index => $byte) {
                $bits = min(8, $prefix);
                if ($bits === 0) {
                    break;
                }
                $mask = (255 << (8 - $bits)) & 255;
                $networkByte = $networkBytes[$index];
                if (!is_int($byte) || !is_int($networkByte)) {
                    return false;
                }
                if (($byte & $mask) !== ($networkByte & $mask)) {
                    $matches = false;
                    break;
                }
                $prefix -= $bits;
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Escalating ban. Keep a counter in the store under
     * "ban-count:{ip}" (increment with a 30 day window). Duration is
     * banSeconds * 2^(count - 1), capped at maxBanSeconds.
     */
    private function banDuration(string $ip): int
    {
        $count = $this->store->increment('ban-count:' . $ip, 30 * 86400);
        $maximum = max(1, $this->config->maxBanSeconds);
        $duration = min(max(1, $this->config->banSeconds), $maximum);
        for ($repeat = 1; $repeat < $count && $duration < $maximum; $repeat++) {
            if ($duration > intdiv($maximum, 2)) {
                return $maximum;
            }
            $duration *= 2;
        }

        return min($duration, $maximum);
    }
}
