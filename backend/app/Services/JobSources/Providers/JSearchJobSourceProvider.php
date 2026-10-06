<?php

namespace App\Services\JobSources\Providers;

use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\JobSourceProvider;
use App\Services\JobSources\NormalizedJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * JSearch (RapidAPI) as the second aggregator source (Requirement 3.1,
 * task 8.3, design.md "Job Source Providers").
 *
 * This is the replacement for the dead `linkedin-job-api.p.rapidapi.com`
 * integration that sat commented out in
 * App\Http\Controllers\JobListingController::index() — removed as part of this
 * task per Requirement 12.3. JSearch is a maintained aggregator that re-indexes
 * LinkedIn/Indeed/Glassdoor/ZipRecruiter postings from Google for Jobs, so we
 * get that coverage from one supported API instead of scraping those boards or
 * depending on an abandoned per-board wrapper.
 *
 * ## Open decision #2
 *
 * design.md leaves "which second aggregator" open pending a RapidAPI account.
 * Nothing in this class is JSearch-specific beyond `queryParameters()` and
 * `normalize()`; swapping to another aggregator means writing a sibling
 * provider and changing one line of `config/job_sources.php`, not touching
 * discovery. Because the decision isn't confirmed and the API needs a paid-tier
 * key, the source ships **disabled by default**
 * (`JOB_SOURCE_JSEARCH_ENABLED=false`) and refuses to run without a key rather
 * than firing unauthenticated calls.
 *
 * ## Query shaping
 *
 * JSearch takes one free-text `query` ("django developer in toronto"), not
 * separate keyword/location fields, so this provider issues one call per role
 * with the location appended, mirroring the Adzuna provider's per-role loop and
 * honouring `$query->limit` as a hard ceiling across roles. `remote_jobs_only`
 * carries `JobSearchQuery::$remoteOnly` — JSearch can actually filter on it,
 * unlike Adzuna.
 *
 * ## Salary and currency
 *
 * JSearch reports pay as a min/max plus a `job_salary_period` that is often
 * HOUR or MONTH. NormalizedJob is defined in whole annual units, so bounds are
 * annualized through `services.jsearch.salary_period_multipliers` before being
 * handed over. Currency comes from `job_salary_currency` when present; when
 * it's missing the posting's `job_country` is looked up in
 * `services.jsearch.currencies`. If neither resolves, the salary is dropped
 * (not guessed) for the same reason the Adzuna provider drops it: the model
 * tier thresholds do no FX conversion, so an unlabelled figure would be read as
 * though it were already in the threshold currency (Requirement 4.3/4.4).
 */
class JSearchJobSourceProvider implements JobSourceProvider
{
    public const KEY = 'jsearch';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * One search per role, stopping as soon as `$query->limit` jobs are
     * collected. Postings are de-duplicated by JSearch `job_id` within the run;
     * cross-run/cross-source dedupe belongs to the orchestrator (task 8.7).
     *
     * @return Collection<int, NormalizedJob>
     */
    public function search(JobSearchQuery $query): Collection
    {
        $jobs = collect();
        $seenIds = [];

        foreach ($query->roles as $role) {
            if ($jobs->count() >= $query->limit) {
                break;
            }

            foreach ($this->fetchRole($role, $query) as $payload) {
                if (! is_array($payload)) {
                    continue;
                }

                $externalId = isset($payload['job_id']) ? trim((string) $payload['job_id']) : '';

                if ($externalId !== '' && isset($seenIds[$externalId])) {
                    continue;
                }

                $job = $this->normalize($payload);

                if ($job === null) {
                    continue;
                }

                if ($externalId !== '') {
                    $seenIds[$externalId] = true;
                }

                $jobs->push($job);

                if ($jobs->count() >= $query->limit) {
                    return $jobs;
                }
            }
        }

        return $jobs;
    }

    /**
     * The raw `data` array for one role.
     *
     * Transport/auth failure throws (per the JobSourceProvider contract) so the
     * orchestrator can log-and-skip the source instead of reading an outage or
     * an exhausted RapidAPI quota as "no jobs today".
     *
     * @return array<int, mixed>
     */
    private function fetchRole(string $role, JobSearchQuery $query): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout((int) $this->config('timeout', 15))
            ->acceptJson()
            ->get($this->endpoint(), $this->queryParameters($role, $query))
            ->throw();

        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        // JSearch answers 200 with a non-OK status + `error` body for some bad
        // requests (unsupported country, malformed query), which `throw()`
        // won't catch.
        $status = isset($data['status']) ? strtoupper((string) $data['status']) : 'OK';

        if ($status !== 'OK' || isset($data['error'])) {
            Log::warning('JSearch returned an error body.', [
                'source' => $this->key(),
                'role' => $role,
                'status' => $data['status'] ?? null,
                'error' => $data['error'] ?? null,
            ]);

            return [];
        }

        $results = $data['data'] ?? [];

        return is_array($results) ? $results : [];
    }

    private function endpoint(): string
    {
        $baseUrl = rtrim((string) $this->config('base_url', 'https://jsearch.p.rapidapi.com'), '/');

        return $baseUrl.'/search';
    }

    /**
     * @return array<string, string>
     *
     * @throws RuntimeException when no RapidAPI key is configured
     */
    private function headers(): array
    {
        $key = trim((string) $this->config('api_key'));

        if ($key === '') {
            throw new RuntimeException(
                'JSearch is enabled but no RapidAPI key is configured. Set JSEARCH_RAPIDAPI_KEY '
                .'(or disable the source with JOB_SOURCE_JSEARCH_ENABLED=false) — an unauthenticated '
                .'call would just burn a discovery slot.'
            );
        }

        $host = (string) $this->config('host', 'jsearch.p.rapidapi.com');

        return [
            'X-RapidAPI-Key' => $key,
            'X-RapidAPI-Host' => $host,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParameters(string $role, JobSearchQuery $query): array
    {
        $parameters = [
            'query' => $query->location === null ? $role : $role.' in '.$query->location,
            'page' => 1,
            'num_pages' => max(1, (int) $this->config('num_pages', 1)),
            'date_posted' => (string) $this->config('date_posted', 'week'),
        ];

        if ($query->remoteOnly) {
            $parameters['remote_jobs_only'] = 'true';
        }

        // Optional: scopes results to one country vertical. Left unset by
        // default so the free-text location in `query` decides.
        $country = trim((string) $this->config('country', ''));

        if ($country !== '') {
            $parameters['country'] = strtolower($country);
        }

        return $parameters;
    }

    /**
     * Map one JSearch result onto a NormalizedJob, or null when the payload is
     * too incomplete to be usable (no title/employer/apply link). A single
     * malformed posting shouldn't fail the whole run.
     *
     * @param array<string, mixed> $payload
     */
    private function normalize(array $payload): ?NormalizedJob
    {
        [$salaryMin, $salaryMax, $currency] = $this->salary($payload);

        try {
            return new NormalizedJob(
                sourceKey: $this->key(),
                title: (string) ($payload['job_title'] ?? ''),
                company: (string) ($payload['employer_name'] ?? ''),
                applicationUrl: (string) ($payload['job_apply_link'] ?? ''),
                location: $this->location($payload),
                externalId: isset($payload['job_id']) ? (string) $payload['job_id'] : null,
                description: isset($payload['job_description'])
                    ? (string) $payload['job_description']
                    : null,
                postedAt: $this->postedAt($payload),
                salaryMin: $salaryMin,
                salaryMax: $salaryMax,
                currency: $currency,
            );
        } catch (Throwable $e) {
            Log::warning('Skipped an unusable JSearch posting.', [
                'source' => $this->key(),
                'external_id' => $payload['job_id'] ?? null,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * JSearch splits the location across city/state/country. Recompose it into
     * the single display string NormalizedJob carries, skipping blanks so a
     * remote posting with only a country doesn't come out as ", , US".
     *
     * @param array<string, mixed> $payload
     */
    private function location(array $payload): string
    {
        $parts = [];

        foreach (['job_city', 'job_state', 'job_country'] as $field) {
            $value = isset($payload[$field]) ? trim((string) $payload[$field]) : '';

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        if ($parts === []) {
            // A fully remote posting often carries no place at all.
            return ! empty($payload['job_is_remote']) ? 'Remote' : '';
        }

        $location = implode(', ', $parts);

        return ! empty($payload['job_is_remote']) ? 'Remote — '.$location : $location;
    }

    /**
     * Annualized whole-unit bounds plus the resolved currency, or all-null when
     * the currency can't be established.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    private function salary(array $payload): array
    {
        $multiplier = $this->periodMultiplier($payload['job_salary_period'] ?? null);

        $min = $this->salaryBound($payload['job_min_salary'] ?? null, $multiplier);
        $max = $this->salaryBound($payload['job_max_salary'] ?? null, $multiplier);

        if ($min === null && $max === null) {
            return [null, null, null];
        }

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $currency = $this->currency($payload);

        if ($currency === null) {
            Log::warning(
                'Dropped a JSearch salary: the posting names no currency and its country is '
                .'unmapped, and an unlabelled figure would be misread by the salary tier thresholds.',
                [
                    'source' => $this->key(),
                    'external_id' => $payload['job_id'] ?? null,
                    'country' => $payload['job_country'] ?? null,
                ]
            );

            return [null, null, null];
        }

        return [$min, $max, $currency];
    }

    private function salaryBound(mixed $value, ?float $multiplier): ?int
    {
        if ($multiplier === null || $value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $amount = (int) round((float) $value * $multiplier);

        // Zero or negative is JSearch saying "unknown", not "unpaid".
        return $amount > 0 ? $amount : null;
    }

    /**
     * Factor that converts a bound in the reported period into an annual
     * figure. Null means the period is unrecognized (or absent), in which case
     * the salary is dropped: assuming "annual" would understate an hourly rate
     * by ~2000x and skew tier selection.
     */
    private function periodMultiplier(mixed $period): ?float
    {
        $period = strtoupper(trim((string) $period));

        if ($period === '') {
            return null;
        }

        $map = $this->config('salary_period_multipliers', []);
        $multiplier = is_array($map) ? ($map[$period] ?? null) : null;

        return is_numeric($multiplier) && (float) $multiplier > 0 ? (float) $multiplier : null;
    }

    /**
     * `job_salary_currency` when the posting has one, else the country's code
     * from config, else null.
     *
     * @param array<string, mixed> $payload
     */
    private function currency(array $payload): ?string
    {
        $reported = isset($payload['job_salary_currency'])
            ? strtoupper(trim((string) $payload['job_salary_currency']))
            : '';

        if (preg_match('/^[A-Z]{3}$/', $reported)) {
            return $reported;
        }

        $country = isset($payload['job_country'])
            ? strtolower(trim((string) $payload['job_country']))
            : '';

        if ($country === '') {
            return null;
        }

        $map = $this->config('currencies', []);
        $code = is_array($map) ? ($map[$country] ?? null) : null;

        if (! is_string($code) || ! preg_match('/^[A-Za-z]{3}$/', trim($code))) {
            return null;
        }

        return strtoupper(trim($code));
    }

    /**
     * Prefer the epoch timestamp; fall back to the ISO string. Junk in either
     * leaves postedAt null rather than failing the posting.
     *
     * @param array<string, mixed> $payload
     */
    private function postedAt(array $payload): ?Carbon
    {
        $timestamp = $payload['job_posted_at_timestamp'] ?? null;

        if (is_numeric($timestamp) && (int) $timestamp > 0) {
            try {
                return Carbon::createFromTimestampUTC((int) $timestamp);
            } catch (Throwable) {
                // fall through to the ISO string
            }
        }

        $datetime = $payload['job_posted_at_datetime_utc'] ?? null;

        if (! is_string($datetime) || trim($datetime) === '') {
            return null;
        }

        try {
            return Carbon::parse($datetime);
        } catch (Throwable) {
            return null;
        }
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("services.jsearch.{$key}", $default);
    }
}
