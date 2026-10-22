<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime\Agent;

/** A relative destination and the outcome of its publication. */
final readonly class PublishResult
{
    /** @param 'created'|'updated'|'unchanged'|'skipped'|'failed' $status */
    public function __construct(
        public string $path,
        public string $status,
        public string $message,
    ) {}
}
