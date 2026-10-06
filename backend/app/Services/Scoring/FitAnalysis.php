<?php

namespace App\Services\Scoring;

/**
 * Prompt 1's verdict: what the posting asks for, what the candidate already
 * has, and what is missing (Requirement 5.1).
 *
 * Immutable and free of Eloquent — {@see JobScoringService} produces it, prompt
 * 2 consumes it, and the queued scoring job (task 10.3) is what writes
 * {@see \App\Models\JobScore::$fit_analysis} from {@see toArray()}. That split
 * is what lets the whole two-prompt flow be tested against a faked router with
 * no database.
 *
 * `rawOutput` is the model's untouched response text, carried because
 * `job_scores.raw_prompt_1_output` stores it verbatim for auditing and for
 * debugging parse failures (Requirement 5.4).
 */
final class FitAnalysis
{
    /**
     * @param array<int, string> $requiredSkills skills/qualifications the posting asks for
     * @param array<int, string> $matchedSkills  required skills the profile evidences
     * @param array<int, string> $missingSkills  required skills the profile does not evidence
     * @param int                $attempts       how many model calls this took (2 = the
     *                                           stricter-instruction retry of Req 5.5 was used)
     */
    public function __construct(
        public readonly array $requiredSkills,
        public readonly string $seniority,
        public readonly array $matchedSkills,
        public readonly array $missingSkills,
        public readonly string $gapSummary,
        public readonly string $rawOutput,
        public readonly string $modelUsed,
        public readonly string $tierUsed,
        public readonly int $attempts = 1,
    ) {
    }

    /**
     * The persisted shape of `job_scores.fit_analysis`, and the same payload
     * handed to prompt 2.
     *
     * One shape for both on purpose: what the rating decision was based on is
     * exactly what a human reviewing the score later needs to see, so there is
     * no chance of the audit trail and the prompt input drifting apart.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requiredSkills' => $this->requiredSkills,
            'seniority' => $this->seniority,
            'matchedSkills' => $this->matchedSkills,
            'missingSkills' => $this->missingSkills,
            'gapSummary' => $this->gapSummary,
        ];
    }

    /** Requirements detected in the JD — a model-routing complexity signal (Req 4.4). */
    public function requirementCount(): int
    {
        return count($this->requiredSkills);
    }

    /** Skills the posting wants and the profile lacks — the other routing signal. */
    public function skillGapSize(): int
    {
        return count($this->missingSkills);
    }

    /**
     * Share of required skills the profile evidences, 0.0-1.0, or null when the
     * model found no requirements at all (in which case there is no ratio to
     * report and callers must not read 0.0 as "matches nothing").
     */
    public function coverageRatio(): ?float
    {
        $required = $this->requirementCount();

        if ($required === 0) {
            return null;
        }

        return round(min(count($this->matchedSkills), $required) / $required, 4);
    }

    /** @return array<string, mixed> Log-friendly summary; never the whole raw output. */
    public function context(): array
    {
        return [
            'seniority' => $this->seniority,
            'required_skills' => $this->requirementCount(),
            'matched_skills' => count($this->matchedSkills),
            'missing_skills' => $this->skillGapSize(),
            'coverage_ratio' => $this->coverageRatio(),
            'model_used' => $this->modelUsed,
            'tier_used' => $this->tierUsed,
            'attempts' => $this->attempts,
        ];
    }
}
