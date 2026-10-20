<?php

declare(strict_types=1);

namespace Shield\Core\Rules;

use Shield\Core\Finding;
use Shield\Core\Http\NormalizedRequest;

/**
 * One detection. Rules are small, single purpose and stateless.
 *
 * CONTRACT
 * - id(): stable kebab-case string such as "sensitive-path". It appears in
 *   events and in the corpus files, so never rename it casually.
 * - phase(): where the engine may run it. Be honest: if a rule does
 *   anything heavier than a string compare or one store call, it is Deferred.
 * - inspect(): return one Finding or null. Never throw for bad input, treat
 *   weird input as normal input. Never modify the request. Never read
 *   globals. Never write to the store unless the rule needs counters (then
 *   take the Store in the constructor).
 * - Every rule ships with corpus files: at least one attack it must catch
 *   and one normal request it must ignore.
 */
interface Rule
{
    public function id(): string;

    public function phase(): Phase;

    public function inspect(NormalizedRequest $request): ?Finding;
}
