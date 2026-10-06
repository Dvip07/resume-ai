<?php

namespace App\Services\JobSources;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One job posting as every source is required to express it (Requirement 3.2):
 * the shared shape that lets discovery, scoring, tailoring and apply stay
 * ignorant of where a job came from.
 *
 * Immutable and free of persistence concerns — mapping onto `job_listings`
 * happens in the orchestrator (task 8.7), after the columns from task 8.6
 * exist.
 *
 * ## Field expectations for providers
 *
 * - `sourceKey` MUST equal the producing provider's `key()`.
 * - `externalId` is the source's own stable id for the posting when it has one
 *   (Adzuna's `id`, Greenhouse's job id). It is the *preferred* dedupe key per
 *   Requirement 3.3 — exact, where {@see dedupeHash()} is heuristic — so pass
 *   it whenever the source provides one, and leave it null rather than
 *   inventing one (an LLM search result has no id).
 * - `description` is routinely null or truncated from aggregators; that's
 *   expected and is what triggers enrichment (Requirement 3.4). Providers
 *   should pass through whatever they got without padding it.
 * - `applicationUrl` must be an absolute http(s) URL, since enrichment and the
 *   apply adapters both dereference it.
 * - `postedAt` null when the source doesn't say. Providers parse source date
 *   formats; this DTO stores an instant.
 *
 * ## Salary and currency
 *
 * Salary figures are whole units of `currency` per year (no cents, no hourly
 * rates — a provider seeing an hourly rate should either annualize it or omit
 * it).
 *
 * `currency` is REQUIRED whenever either salary bound is present, and is
 * normalized to an uppercase ISO-4217 code. This is enforced rather than
 * suggested because of the gap flagged in task 7.3: the salary thresholds in
 * `config('services.openrouter.salary_thresholds')` are bare integers and
 * {@see \App\Services\ModelTierContext} performs NO FX conversion, so a figure
 * with an unknown currency is silently interpreted as though it were in the
 * threshold currency — a ¥8,000,000 role would read as premium, a €40,000 one
 * would not read as it should. Carrying the code at least makes the mismatch
 * detectable, and lets a later conversion step be added in one place.
 *
 * A provider whose source reports pay with no currency information should
 * supply the source's documented default (e.g. Adzuna's per-country currency)
 * rather than guessing, or omit the salary entirely. Omitting it is safe: the
 * router falls back to its complexity heuristic (Requirement 4.4).
 */
class NormalizedJob
{
    public readonly string $sourceKey;
    public readonly ?string $externalId;
    public readonly string $title;
    public readonly string $company;
    public readonly string $location;
    public readonly ?string $description;
    public readonly string $applicationUrl;
    public readonly ?Carbon $postedAt;
    public readonly ?int $salaryMin;
    public readonly ?int $salaryMax;
    public readonly ?string $currency;

    /**
     * @throws InvalidArgumentException when a required field is blank, the URL
     *                                  isn't absolute http(s), a salary bound
     *                                  is negative or inverted, or a salary is
     *                                  given without a currency
     */
    public function __construct(
        string $sourceKey,
        string $title,
        string $company,
        string $applicationUrl,
        string $location = '',
        ?string $externalId = null,
        ?string $description = null,
        ?Carbon $postedAt = null,
        ?int $salaryMin = null,
        ?int $salaryMax = null,
        ?string $currency = null,
    ) {
        $this->sourceKey = self::require($sourceKey, 'sourceKey');
        $this->title = self::require($title, 'title');
        $this->company = self::require($company, 'company');
        $this->applicationUrl = self::requireUrl($applicationUrl);

        // Location is allowed to be unknown: LLM search results and some ATS
        // boards genuinely don't carry one, and dropping the job over it would
        // lose a real lead. It still participates in the dedupe hash as ''.
        $this->location = trim($location);

        $externalId = $externalId === null ? null : trim($externalId);
        $this->externalId = $externalId === '' ? null : $externalId;

        $this->description = $description === null || trim($description) === ''
            ? null
            : $description;

        $this->postedAt = $postedAt;

        [$this->salaryMin, $this->salaryMax, $this->currency] =
            self::validateSalary($salaryMin, $salaryMax, $currency);
    }

    /**
     * Requirement 3.3's heuristic composite key. Delegates to
     * {@see JobDedupeHasher} so incoming jobs and stored `job_listings` rows
     * are hashed by identical rules; see that class for the normalization
     * rules and their documented limits.
     */
    public function dedupeHash(?JobDedupeHasher $hasher = null): string
    {
        return ($hasher ?? new JobDedupeHasher())
            ->hash($this->title, $this->company, $this->location);
    }

    /** The readable string the hash is computed from — for logs and test output. */
    public function dedupeFingerprint(?JobDedupeHasher $hasher = null): string
    {
        return ($hasher ?? new JobDedupeHasher())
            ->fingerprint($this->title, $this->company, $this->location);
    }

    public function hasSalary(): bool
    {
        return $this->salaryMin !== null || $this->salaryMax !== null;
    }

    /**
     * True when the description is missing or too short to score against,
     * i.e. this job needs the enrichment stage (Requirement 3.4). The
     * threshold is config-driven so it can be tuned with the enrichment work
     * in task 9 without touching providers.
     */
    public function needsEnrichment(): bool
    {
        $minimum = (int) config('job_sources.min_description_length', 400);

        return $this->description === null || mb_strlen($this->description) < $minimum;
    }

    private static function require(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("NormalizedJob requires a non-empty {$field}.");
        }

        return $value;
    }

    private static function requireUrl(string $url): string
    {
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                "NormalizedJob requires an absolute http(s) applicationUrl, got '{$url}'."
            );
        }

        return $url;
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    private static function validateSalary(?int $min, ?int $max, ?string $currency): array
    {
        foreach (['salaryMin' => $min, 'salaryMax' => $max] as $field => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException("NormalizedJob {$field} cannot be negative, got {$value}.");
            }
        }

        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException(
                "NormalizedJob salaryMin ({$min}) cannot exceed salaryMax ({$max}); "
                . 'the provider has the bounds reversed.'
            );
        }

        $currency = $currency === null ? null : strtoupper(trim($currency));
        $currency = $currency === '' ? null : $currency;

        if ($min === null && $max === null) {
            // No pay data: a stray currency code is meaningless, so drop it.
            return [null, null, null];
        }

        if ($currency === null) {
            throw new InvalidArgumentException(
                'NormalizedJob requires a currency when a salary is present — thresholds do no '
                . 'FX conversion, so an unlabelled figure would be misread. Supply the source\'s '
                . 'documented currency or omit the salary.'
            );
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException(
                "NormalizedJob currency must be a 3-letter ISO-4217 code, got '{$currency}'."
            );
        }

        return [$min, $max, $currency];
    }
}
