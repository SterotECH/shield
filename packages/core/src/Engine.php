<?php

declare(strict_types=1);

namespace Stero\Shield;

use Stero\Shield\Events\EventSink;
use Stero\Shield\Events\SecurityEvent;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Net\IpMatcher;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\Rule;
use Stero\Shield\Rules\Severity;
use Stero\Shield\Store\Store;

/**
 * Runs rules in order, applies Mode, manages bans and records events.
 * The host calls Inline before handling the request and Deferred after sending the response.
 *
 * 1. Allowlisted, invalid and 0.0.0.0 clients still produce findings, but never
 *    check bans, block or receive bans.
 * 2. An existing Inline ban records one banned-ip event and skips rules without
 *    extending the ban. Only Enforce blocks the request.
 * 3. Run matching-phase rules, skipping individual failures. Stop Inline rules
 *    after the first banWorthy finding; Deferred runs every rule.
 * 4. wouldBlock reflects banWorthy findings, including for unbannable clients.
 * 5. Enforce bans bannable clients in both phases, but blocks only Inline.
 *    A failed ban write preserves blocking and leaves banForSeconds null.
 * 6. Build the final Decision before recording each finding in order. Sink
 *    failures must not alter the Decision or prevent later event attempts.
 * 7. Unexpected failures log a fixed message without request data and allow.
 */
final class Engine
{
    /** @var list<Rule> */
    private array $rules;

    private IpMatcher $allowlist;

    /** @param iterable<Rule> $rules */
    public function __construct(
        iterable $rules,
        private Store $store,
        private EventSink $sink,
        private EngineConfig $config,
    ) {
        $this->rules = $rules instanceof \Traversable ? iterator_to_array($rules, false) : array_values($rules);
        $this->allowlist = new IpMatcher($config->allowlist);
    }

    public function inspect(NormalizedRequest $request, Phase $phase): Decision
    {
        try {
            $ip = $request->clientIp;
            $unbannable = $ip === '0.0.0.0'
                || filter_var($ip, FILTER_VALIDATE_IP) === false
                || $this->allowlist->matches($ip);

            if ($phase === Phase::Inline && !$unbannable) {
                try {
                    $banned = $this->store->isBanned($ip);
                } catch (\Throwable) {
                    $banned = false;
                }
                if ($banned) {
                    $finding = new Finding(
                        'banned-ip',
                        Severity::High,
                        'Request from a banned IP',
                        mb_substr($request->path, 0, 200, 'UTF-8'),
                        false,
                    );
                    $blocked = $this->config->mode === Mode::Enforce;
                    $decision = new Decision([$finding], true, $blocked, $blocked ? $this->config->blockStatus : null, alreadyBanned: true);
                    try {
                        $this->sink->record(SecurityEvent::fromFinding($request, $finding, $decision, $this->config->mode));
                    } catch (\Throwable) {
                    }
                    return $decision;
                }
            }

            $findings = [];
            $wouldBlock = false;
            foreach ($this->rules as $rule) {
                try {
                    if ($rule->phase() !== $phase) {
                        continue;
                    }
                    $finding = $rule->inspect($request);
                } catch (\Throwable) {
                    continue;
                }
                if ($finding === null) {
                    continue;
                }
                $findings[] = $finding;
                $wouldBlock = $wouldBlock || $finding->banWorthy;
                if ($phase === Phase::Inline && $finding->banWorthy) {
                    break;
                }
            }

            $enforce = $this->config->mode === Mode::Enforce && $wouldBlock && !$unbannable;
            $blocked = $enforce && $phase === Phase::Inline;
            $banForSeconds = null;
            if ($enforce) {
                $duration = $this->banDuration($ip);
                try {
                    $this->store->ban($ip, $duration);
                    $banForSeconds = $duration;
                } catch (\Throwable) {
                }
            }
            $decision = new Decision($findings, $wouldBlock, $blocked, $blocked ? $this->config->blockStatus : null, $banForSeconds);
            foreach ($findings as $finding) {
                try {
                    $this->sink->record(SecurityEvent::fromFinding($request, $finding, $decision, $this->config->mode));
                } catch (\Throwable) {
                }
            }
            return $decision;
        } catch (\Throwable) {
            // Logging itself must not let a custom error handler break fail-open behaviour.
            try {
                error_log('Shield inspection failed');
            } catch (\Throwable) {
            }
            return Decision::allow();
        }
    }

    /**
     * Double the base ban per offence, capped at maxBanSeconds. The counter has
     * a fixed 30 day window. Saturating integer doubling avoids exponent overflow.
     */
    private function banDuration(string $ip): int
    {
        try {
            $count = $this->store->increment('ban-count:' . $ip, 30 * 86400);
        } catch (\Throwable) {
            $count = 0;
        }
        if ($count <= 0) {
            return $this->config->banSeconds;
        }
        $maximum = max(1, $this->config->maxBanSeconds);
        $duration = min(max(1, $this->config->banSeconds), $maximum);
        // No positive integer can double more than this many times without saturating.
        $exponent = min($count - 1, PHP_INT_SIZE * 8 - 1);
        for ($repeat = 0; $repeat < $exponent && $duration < $maximum; $repeat++) {
            if ($duration > intdiv($maximum, 2)) {
                return $maximum;
            }
            $duration *= 2;
        }
        return $duration;
    }
}
