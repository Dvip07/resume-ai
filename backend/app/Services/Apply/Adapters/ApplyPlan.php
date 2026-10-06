<?php

namespace App\Services\Apply\Adapters;

use App\Services\Apply\ApplyStepScript;

/**
 * What an adapter compiled for one attempt: the script, plus the two facts the
 * shared interpretation needs that cannot be read back off the script itself.
 *
 * `submitIndex` is the index of the first step that touches the employer's
 * server — the submit click. It is the boundary between "retry is safe" and
 * "retry risks a duplicate application", so every adapter has to declare it
 * rather than have the base class guess from step kinds.
 *
 * `skippedQuestions` names *optional* fields nothing could answer — blank is a
 * valid answer there, so the submission went ahead.
 *
 * `unansweredRequired` names *required* fields nothing could answer. That is a
 * stop, not a note: when it is non-empty the compiled script deliberately ends
 * before the submit click and the run comes back `needs_review` with these
 * labels (Requirement 9.4). `notes` is free metadata an adapter wants to
 * surface (which documents it could attach, how many pages it walked). All
 * three end up in {@see \App\Services\Apply\ApplyResult::$metadata}.
 */
final class ApplyPlan
{
    /**
     * @param  list<string>  $skippedQuestions
     * @param  list<string>  $unansweredRequired
     * @param  array<string, mixed>  $notes
     */
    public function __construct(
        public readonly ApplyStepScript $script,
        public readonly int $submitIndex,
        public readonly array $skippedQuestions = [],
        public readonly array $unansweredRequired = [],
        public readonly array $notes = [],
    ) {}

    /**
     * Was this script compiled without a submit click because a required
     * question has no answer? If so, nothing was ever sent to the employer.
     */
    public function pausedForQuestions(): bool
    {
        return $this->unansweredRequired !== [];
    }
}
