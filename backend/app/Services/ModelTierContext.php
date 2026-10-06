<?php

namespace App\Services;

/**
 * The routing inputs a caller knows about a unit of work: what the role pays
 * (salary-based escalation, Requirement 4.3) and how hard the task looks
 * (complexity-based escalation, Requirement 4.4).
 *
 * Still a value object — the threshold comparisons live in
 * ModelRouterService::selectTier() and the arithmetic behind
 * `$complexityScore` lives in ComplexityScorer. Callers that have the raw
 * signals rather than a score should use `fromSignals()` instead of computing
 * one themselves.
 *
 * Currency: `$currency` is carried for logging and future normalization only.
 * `config('services.openrouter.salary_thresholds')` holds plain integers with
 * no unit attached, so the router compares salary figures against them
 * as-is and performs NO currency conversion. The thresholds are therefore
 * interpreted in whatever currency the listing is in. Callers that ingest
 * multi-currency listings are responsible for normalizing to a single currency
 * before constructing the context — otherwise a 120,000 JPY role would read as
 * a six-figure one.
 */
class ModelTierContext
{
    public function __construct(
        public readonly ?int $salaryMin = null,
        public readonly ?int $salaryMax = null,
        public readonly ?string $currency = null,
        public readonly int $complexityScore = 0,
    ) {}

    /**
     * Build a context from raw signals, deriving the complexity score with the
     * configured weights/saturations. This is the intended entry point for
     * pipeline jobs: they know the JD text and the fit analysis, not a score.
     *
     * @param int $jdLength         characters of job-description text
     * @param int $requirementCount requirements/qualifications detected in the JD
     * @param int $skillGapSize     skills the JD wants that the resume lacks
     */
    public static function fromSignals(
        ?int $salaryMin = null,
        ?int $salaryMax = null,
        ?string $currency = null,
        int $jdLength = 0,
        int $requirementCount = 0,
        int $skillGapSize = 0,
        ?ComplexityScorer $scorer = null,
    ): self {
        $scorer ??= new ComplexityScorer();

        return new self(
            salaryMin: $salaryMin,
            salaryMax: $salaryMax,
            currency: $currency,
            complexityScore: $scorer->score($jdLength, $requirementCount, $skillGapSize),
        );
    }

    /**
     * Nothing known about the work — the router falls back to the configured
     * default tier.
     */
    public static function unknown(): self
    {
        return new self();
    }

    public function hasSalary(): bool
    {
        return $this->salaryMin !== null || $this->salaryMax !== null;
    }

    /**
     * The single figure salary thresholds are compared against: the top of the
     * advertised range when both bounds are known, otherwise whichever bound
     * we have.
     *
     * The ceiling rather than the midpoint, because Requirement 4.3's reason
     * for escalating is that the application is worth more effort, and what a
     * posting is worth is what it could pay. Negative or absent figures read
     * as 0, which keeps the tier at the default.
     */
    public function salaryBasis(): int
    {
        $known = array_filter(
            [$this->salaryMin, $this->salaryMax],
            fn (?int $value) => $value !== null
        );

        return $known === [] ? 0 : max(0, max($known));
    }
}
