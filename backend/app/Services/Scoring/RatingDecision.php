<?php

namespace App\Services\Scoring;

use App\Enums\RecommendedAction;

/**
 * Prompt 2's verdict: a star rating, a short rationale, and the action the model
 * recommends (Requirement 5.1).
 *
 * `recommendedAction` is a recommendation and nothing more. The pipeline still
 * decides for itself, from the star rating against
 * `auto_apply_star_threshold` and the user's `auto_apply_enabled` setting
 * (Requirements 5.2, 5.3) — see {@see meetsThreshold()} and task 10.3. Storing
 * the model's own suggestion alongside the decision the system took is what
 * makes a disagreement between the two visible after the fact.
 *
 * `rawOutput` is the untouched response text, persisted verbatim as
 * `job_scores.raw_prompt_2_output` (Requirement 5.4).
 */
final class RatingDecision
{
    /**
     * @param int $attempts how many model calls this took (2 = the
     *                      stricter-instruction retry of Req 5.5 was used)
     */
    public function __construct(
        public readonly int $stars,
        public readonly string $rationale,
        public readonly RecommendedAction $recommendedAction,
        public readonly string $rawOutput,
        public readonly string $modelUsed,
        public readonly string $tierUsed,
        public readonly int $attempts = 1,
    ) {
    }

    /**
     * Does the rating clear the configured auto-apply threshold?
     *
     * The system's own test, independent of `recommendedAction` — a model that
     * says `auto_apply` on a 2-star fit does not get to bypass the threshold.
     */
    public function meetsThreshold(int $threshold): bool
    {
        return $this->stars >= $threshold;
    }

    /** True when the model's suggestion disagrees with the threshold test. */
    public function disagreesWithThreshold(int $threshold): bool
    {
        return $this->meetsThreshold($threshold)
            !== ($this->recommendedAction === RecommendedAction::AutoApply);
    }

    /** @return array<string, mixed> Log-friendly summary; never the whole raw output. */
    public function context(): array
    {
        return [
            'stars' => $this->stars,
            'recommended_action' => $this->recommendedAction->value,
            'model_used' => $this->modelUsed,
            'tier_used' => $this->tierUsed,
            'attempts' => $this->attempts,
        ];
    }
}
