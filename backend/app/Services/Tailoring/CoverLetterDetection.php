<?php

namespace App\Services\Tailoring;

use App\Enums\CoverLetterDetectionMethod;
use App\Enums\CoverLetterRequirement;

/**
 * The detector's verdict on one posting (Requirements 7.1, 7.2): what the
 * posting asks for, how that was worked out, what text says so, and how much
 * the answer should be trusted.
 *
 * Immutable and persistence-free, like {@see \App\Services\Scoring\FitAnalysis}
 * and {@see \App\Services\Scoring\RatingDecision}: the detector's whole contract
 * is to return one of these, which is what lets it be tested against a faked
 * router with no database.
 *
 * The evidence is not decoration. Requirement 7.2 turns this object into a
 * spending decision, and a spending decision that cannot be explained cannot be
 * tuned — when a user asks why their application went out without a cover
 * letter, the answer is either a phrase from the posting or "the posting never
 * mentioned one", and both are here.
 */
final class CoverLetterDetection
{
    /**
     * @param  array<int, string>  $evidence  the matched phrases, or the model's stated
     *                                        reason; empty when the posting never
     *                                        mentioned a cover letter
     * @param  float  $confidence  0.0-1.0
     * @param  int  $attempts  model calls spent (0 for a keyword-only decision)
     */
    public function __construct(
        public readonly CoverLetterRequirement $requirement,
        public readonly CoverLetterDetectionMethod $method,
        public readonly array $evidence = [],
        public readonly float $confidence = 0.0,
        public readonly ?string $modelUsed = null,
        public readonly ?string $tierUsed = null,
        public readonly int $attempts = 0,
    ) {}

    /**
     * Should the caller dispatch cover-letter tailoring (Requirement 7.1)?
     *
     * Delegated to the enum rather than reimplemented, so there is exactly one
     * definition of "wanted" in the system.
     */
    public function wantsCoverLetter(): bool
    {
        return $this->requirement->wantsCoverLetter();
    }

    public function isMandatory(): bool
    {
        return $this->requirement->isMandatory();
    }

    /** Did this answer cost a model call? */
    public function spentModelCall(): bool
    {
        return $this->method->spentModelCall();
    }

    /**
     * @return array<string, mixed> Log-friendly summary for the dispatching
     *                              caller (task 12.3)
     */
    public function context(): array
    {
        return [
            'cover_letter_requirement' => $this->requirement->value,
            'detection_method' => $this->method->value,
            'confidence' => $this->confidence,
            // Bounded: evidence is untrusted posting text, and a log line is not
            // the place to reproduce a paragraph of it.
            'evidence' => array_slice($this->evidence, 0, 3),
            'model_used' => $this->modelUsed,
            'tier_used' => $this->tierUsed,
            'attempts' => $this->attempts,
        ];
    }
}
