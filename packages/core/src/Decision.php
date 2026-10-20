<?php

declare(strict_types=1);

namespace Shield\Core;

/**
 * What the Engine tells the host app. The app only reads this and acts.
 *
 * FIELD MEANING
 * - $wouldBlock: true when enforcement would have blocked this request.
 *   Set in BOTH modes. This is the number you tune rules against.
 * - $blocked: true only in Mode::Enforce when the request is really blocked.
 *   In Mode::Detect this is always false.
 * - $responseStatus: status the app must return when $blocked is true
 *   (normally 404), otherwise null.
 * - $banForSeconds: set when this request caused a new ban, otherwise null.
 * - $alreadyBanned: the IP was banned before this request arrived.
 */
final readonly class Decision
{
    /** @param list<Finding> $findings */
    public function __construct(
        public array $findings,
        public bool $wouldBlock,
        public bool $blocked,
        public ?int $responseStatus = null,
        public ?int $banForSeconds = null,
        public bool $alreadyBanned = false,
    ) {
    }

    /** Factory for the common "nothing found" result. */
    public static function allow(): self
    {
        return new self([], false, false);
    }

    /** TODO: return true when $findings is not empty. */
    public function hasFindings(): bool
    {
        throw new \LogicException('Not implemented');
    }

    /** TODO: return the most severe finding's severity, or null if none. Use Severity::rank(). */
    public function highestSeverity(): ?Severity
    {
        throw new \LogicException('Not implemented');
    }
}