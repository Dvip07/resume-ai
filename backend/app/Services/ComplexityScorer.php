<?php

namespace App\Services;

/**
 * Turns the raw signals a caller can cheaply measure — JD length, how many
 * requirements were detected, how many skills the resume is missing — into the
 * single 0-100 score ModelRouterService compares against
 * `services.openrouter.complexity_thresholds` when a listing has no salary
 * data (Requirement 4.4).
 *
 * Everything about the shape of the score lives in
 * `config('services.openrouter.complexity')`, so the heuristic can be retuned
 * without touching code (Requirement 4.2):
 *
 *   - `weights.{jd_length,requirement_count,skill_gap}` — the relative pull of
 *     each signal. The defaults sum to 100, but any positive numbers work:
 *     the result is normalized by the total weight, so 4/3/3 and 40/30/30
 *     score identically.
 *   - `*_saturation` — the raw value at which a signal contributes its full
 *     weight. Larger raw values are clamped, so one 40k-character JD can't
 *     drown out the other two signals.
 *
 * A signal with a non-positive saturation contributes nothing rather than
 * dividing by zero, and a zero-signal context scores 0 — which keeps the
 * router at `default_tier` instead of guessing the expensive tier.
 */
class ComplexityScorer
{
    /**
     * @param int $jdLength         characters of job-description text
     * @param int $requirementCount distinct requirements/qualifications detected
     * @param int $skillGapSize     skills the JD wants that the resume lacks
     *
     * @return int 0-100, higher meaning harder work
     */
    public function score(int $jdLength = 0, int $requirementCount = 0, int $skillGapSize = 0): int
    {
        $signals = [
            'jd_length' => [$jdLength, $this->number('jd_length_saturation')],
            'requirement_count' => [$requirementCount, $this->number('requirement_count_saturation')],
            'skill_gap' => [$skillGapSize, $this->number('skill_gap_saturation')],
        ];

        $weighted = 0.0;
        $totalWeight = 0.0;

        foreach ($signals as $name => [$raw, $saturation]) {
            $weight = $this->weight($name);

            if ($weight <= 0) {
                continue;
            }

            $totalWeight += $weight;
            $weighted += $weight * $this->ratio($raw, $saturation);
        }

        if ($totalWeight <= 0) {
            return 0;
        }

        return (int) max(0, min(100, round(100 * $weighted / $totalWeight)));
    }

    /**
     * How much of its full weight a signal earns: 0 at zero, 1 once it reaches
     * saturation, linear in between.
     */
    private function ratio(int $raw, float $saturation): float
    {
        if ($saturation <= 0 || $raw <= 0) {
            return 0.0;
        }

        return min(1.0, $raw / $saturation);
    }

    private function weight(string $signal): float
    {
        return $this->number("weights.{$signal}");
    }

    private function number(string $key): float
    {
        $value = config("services.openrouter.complexity.{$key}");

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
