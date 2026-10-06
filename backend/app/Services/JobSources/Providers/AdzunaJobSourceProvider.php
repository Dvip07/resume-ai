<?php

namespace App\Services\JobSources\Providers;

use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\JobSourceProvider;
use App\Services\JobSources\NormalizedJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adzuna as a first-class job source (Requirement 3.1).
 *
 * This is the refactor of the Guzzle call that used to live inline in
 * App\Http\Controllers\JobListingController::index() — same endpoint, same
 * `config('services.adzuna')` credentials, same `what`/`location0`/
 * `max_days_old` query shape. What changed is everything around it: the call no
 * longer runs inside a web request, no longer writes to `job_listings`, and no
 * longer decides what a row looks like. It takes a JobSearchQuery and returns
 * NormalizedJob values; persistence and dedupe belong to
 * JobDiscoveryOrchestrator (task 8.7) and dispatch to DiscoverJobsForUser
 * (task 8.9).
 *
 * The HTTP client is Laravel's (rather than a raw Guzzle client) so discovery
 * shares the framework's timeout/retry surface and tests can fake the
 * transport without a live API key.
 *
 * ## Currency
 *
 * Adzuna is a per-country API: the country appears in the URL path and there is
 * no currency anywhere in the response body — a figure's currency is implied by
 * which vertical you queried. NormalizedJob requires a currency whenever a
 * salary is present and performs no FX conversion, so this provider resolves
 * the code from `services.adzuna.currencies` for the configured country. If the
 * country isn't mapped, the salary is dropped (and logged once per job) rather
 * than labelled with a guess: an unlabelled or mislabelled figure would be
 * silently misread by the salary-based model tier thresholds, whereas a missing
 * one just falls back to the complexity heuristic (Requirement 4.4).
 */
class AdzunaJobSourceProvider implements JobSourceProvider
{
    public const KEY = 'adzuna';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * One search per role (Adzuna's `what` takes a single keyword phrase),
     * stopping as soon as `$query->limit` jobs have been collected.
     *
     * Postings are de-duplicated by Adzuna id within the run, since two role
     * keywords routinely surface the same posting. Cross-run/cross-source
     * dedupe is the orchestrator's job.
     *
     * @return Collection<int, NormalizedJob>
     */
    public function search(JobSearchQuery $query): Collection
    {
        $jobs = collect();
        $seenIds = [];

        foreach ($query->roles as $role) {
            $remaining = $query->limit - $jobs->count();

            if ($remaining < 1) {
                break;
            }

            foreach ($this->fetchRole($role, $query, $remaining) as $payload) {
                if (! is_array($payload)) {
                    continue;
                }

                $externalId = isset($payload['id']) ? trim((string) $payload['id']) : '';

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
     * The raw `results` array for one role.
     *
     * Transport/auth failure throws (per the JobSourceProvider contract) so the
     * orchestrator can log-and-skip the source instead of mistaking an outage
     * for "no jobs today".
     *
     * @return array<int, mixed>
     */
    private function fetchRole(string $role, JobSearchQuery $query, int $remaining): array
    {
        $response = Http::timeout($this->config('timeout', 15))
            ->acceptJson()
            ->get($this->endpoint(), $this->queryParameters($role, $query, $remaining))
            ->throw();

        $data = $response->json();

        // Adzuna answers 200 with an `error`/`exception` body for some bad
        // requests (e.g. an unknown country vertical), which `throw()` won't
        // catch.
        if (is_array($data) && (isset($data['error']) || isset($data['exception']))) {
            Log::warning('Adzuna returned an error body.', [
                'source' => $this->key(),
                'role' => $role,
                'error' => $data['error'] ?? $data['exception'],
            ]);

            return [];
        }

        $results = is_array($data) ? ($data['results'] ?? []) : [];

        return is_array($results) ? $results : [];
    }

    /**
     * Search endpoint, page 1 — the same URL the legacy controller called, with
     * the country made configurable instead of hard-coded to `ca`.
     */
    private function endpoint(): string
    {
        $baseUrl = rtrim((string) $this->config('base_url', 'https://api.adzuna.com/v1/api/jobs'), '/');

        return $baseUrl.'/'.$this->country().'/search/1';
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParameters(string $role, JobSearchQuery $query, int $remaining): array
    {
        // Never ask for more than we can still use, and never more than the
        // configured page size (which keeps one call's cost bounded).
        $perPage = max(1, min($remaining, (int) $this->config('results_per_page', 5)));

        $parameters = [
            'app_id' => $this->config('app_id'),
            'app_key' => $this->config('app_key'),
            'results_per_page' => $perPage,
            'what' => $role,
            'max_days_old' => (int) $this->config('max_days_old', 30),
        ];

        if ($query->location !== null) {
            $parameters['location0'] = $query->location;
        }

        return $parameters;
    }

    /**
     * Map one Adzuna result onto a NormalizedJob, or null when the payload is
     * too incomplete to be usable (no title/company/URL). A single malformed
     * posting shouldn't fail the whole run.
     *
     * @param array<string, mixed> $payload
     */
    private function normalize(array $payload): ?NormalizedJob
    {
        [$salaryMin, $salaryMax, $currency] = $this->salary($payload);

        try {
            return new NormalizedJob(
                sourceKey: $this->key(),
                title: (string) ($payload['title'] ?? ''),
                company: (string) ($payload['company']['display_name'] ?? ''),
                applicationUrl: (string) ($payload['redirect_url'] ?? ''),
                location: (string) ($payload['location']['display_name'] ?? ''),
                externalId: isset($payload['id']) ? (string) $payload['id'] : null,
                description: isset($payload['description']) ? (string) $payload['description'] : null,
                postedAt: $this->postedAt($payload['created'] ?? null),
                salaryMin: $salaryMin,
                salaryMax: $salaryMax,
                currency: $currency,
            );
        } catch (Throwable $e) {
            Log::warning('Skipped an unusable Adzuna posting.', [
                'source' => $this->key(),
                'external_id' => $payload['id'] ?? null,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Adzuna reports salaries as floats in the country's currency, per year.
     * Bounds are rounded to whole units and dropped entirely when the currency
     * can't be determined.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    private function salary(array $payload): array
    {
        $min = $this->salaryBound($payload['salary_min'] ?? null);
        $max = $this->salaryBound($payload['salary_max'] ?? null);

        if ($min === null && $max === null) {
            return [null, null, null];
        }

        // Adzuna occasionally reports the bounds the wrong way round; NormalizedJob
        // rejects an inverted pair, and swapping is the obvious reading.
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $currency = $this->currency();

        if ($currency === null) {
            Log::warning(
                'Dropped an Adzuna salary: no currency is mapped for the configured country, '
                .'and an unlabelled figure would be misread by the salary tier thresholds.',
                [
                    'source' => $this->key(),
                    'country' => $this->country(),
                    'external_id' => $payload['id'] ?? null,
                ]
            );

            return [null, null, null];
        }

        return [$min, $max, $currency];
    }

    private function salaryBound(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $amount = (int) round((float) $value);

        // A zero or negative bound is Adzuna saying "unknown", not "unpaid".
        return $amount > 0 ? $amount : null;
    }

    /** ISO-4217 code for the configured country vertical, or null if unmapped. */
    private function currency(): ?string
    {
        $map = $this->config('currencies', []);
        $code = is_array($map) ? ($map[$this->country()] ?? null) : null;

        if (! is_string($code) || ! preg_match('/^[A-Za-z]{3}$/', trim($code))) {
            return null;
        }

        return strtoupper(trim($code));
    }

    private function country(): string
    {
        return strtolower(trim((string) $this->config('country', 'ca')));
    }

    /** Adzuna's `created` is an ISO-8601 timestamp; be forgiving about junk. */
    private function postedAt(mixed $created): ?Carbon
    {
        if (! is_string($created) || trim($created) === '') {
            return null;
        }

        try {
            return Carbon::parse($created);
        } catch (Throwable) {
            return null;
        }
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("services.adzuna.{$key}", $default);
    }
}
