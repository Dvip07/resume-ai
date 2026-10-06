<?php

namespace App\Services\JobSources\Providers;

use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\JobSourceProvider;
use App\Services\JobSources\NormalizedJob;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LLM web search as a discovery source (Requirement 3.1, task 8.5, design.md
 * "Job Source Providers").
 *
 * The other three sources query a structured index: an aggregator API or a
 * company's own board feed. This one covers what those indexes miss — a role
 * posted only on a company's careers page, or on a board nobody thought to
 * configure a slug for. It asks a web-search-augmented model, through
 * {@see ModelRouterService}, for postings matching the user's roles and
 * location, and gets back a structured list of `{title, company, url,
 * location, salary?}` exactly as design.md specifies.
 *
 * ## Everything here is an unverified lead
 *
 * This is the defining property of the source, not a caveat. A model can
 * invent a company, resurface a role filled six months ago, or cite a URL that
 * 404s. So:
 *
 *  - **any description the model writes is discarded.** The job is handed on
 *    with `description = null`, which makes {@see NormalizedJob::needsEnrichment()}
 *    true and puts the lead through the enrichment stage (Requirement 3.4),
 *    where the real posting at `applicationUrl` is fetched before anything is
 *    scored. Accepting model prose here would mean scoring — and tailoring a
 *    resume against — text the employer never wrote.
 *  - **`externalId` is always null.** There is no source-side id to be stable
 *    against, so dedupe falls to the `title+company+location` hash
 *    (Requirement 3.3), which is exactly the case that hash exists for.
 *  - **claims that can't be sanity-checked are dropped, not repaired.** A
 *    posting with no usable http(s) URL is skipped; a salary with no currency
 *    loses the salary; a posted date in the future or older than
 *    `max_posted_age_days` becomes null. Dropping a field costs one signal.
 *    Keeping a fabricated one corrupts the pipeline downstream of here.
 *
 * ## Model routing
 *
 * The call pins the `web_search` tier rather than letting salary/complexity
 * pick one: a model without live web access answers this prompt from training
 * data, which is the one failure mode that looks like success. Tier contents
 * stay in config (Requirement 4.2) and the pin is a capability requirement —
 * see the `$tierOverride` note on {@see ModelRouterService::complete()}.
 * OpenRouter's `:online` model suffix backs the tier; where a provider has no
 * native search, OpenRouter runs Exa underneath, which is the Exa/Tavily
 * fallback Requirement 3.1 allows for, without a second integration.
 *
 * One call per run covers every role, not one per role like the aggregator
 * providers: web search is billed per request on top of tokens, and a single
 * prompt listing the roles searches them all just as well.
 */
class LlmWebSearchJobSourceProvider implements JobSourceProvider
{
    public const KEY = 'llm_search';

    public function __construct(private readonly ModelRouterService $router) {}

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @return Collection<int, NormalizedJob>
     */
    public function search(JobSearchQuery $query): Collection
    {
        $requested = min($query->limit, max(1, (int) $this->config('max_results', 15)));

        // Transport/auth/parse failure propagates as ModelRouterException, per
        // the JobSourceProvider contract: a missing OPENROUTER_API_KEY or a
        // dead endpoint must not read as "the web has no matching jobs today".
        // The orchestrator (task 8.7) logs and skips the source.
        $result = $this->router->complete(
            taskType: (string) $this->config('task_type', 'job_search'),
            messages: $this->messages($query, $requested),
            // Routing is pinned below, so the context carries no signals: this
            // task's model choice is about capability, not stakes.
            context: ModelTierContext::unknown(),
            jsonSchema: $this->responseSchema(),
            tierOverride: (string) $this->config('tier', 'web_search'),
        );

        return $this->normalizeAll($result->parsedJson, $requested);
    }

    /**
     * @param array<mixed> $parsedJson
     *
     * @return Collection<int, NormalizedJob>
     */
    private function normalizeAll(array $parsedJson, int $limit): Collection
    {
        $postings = $parsedJson['jobs'] ?? [];

        if (! is_array($postings)) {
            return collect();
        }

        $jobs = collect();
        $seenUrls = [];

        foreach ($postings as $posting) {
            if (! is_array($posting)) {
                continue;
            }

            // A model asked for 15 results will occasionally return 20, and a
            // careless one repeats the same posting under two titles.
            $urlKey = mb_strtolower(rtrim(trim((string) ($posting['url'] ?? '')), '/'));

            if ($urlKey === '' || isset($seenUrls[$urlKey])) {
                continue;
            }

            $job = $this->normalize($posting);

            if ($job === null) {
                continue;
            }

            $seenUrls[$urlKey] = true;
            $jobs->push($job);

            if ($jobs->count() >= $limit) {
                break;
            }
        }

        return $jobs;
    }

    /**
     * One search result as a NormalizedJob, or null when it is too incomplete
     * or too implausible to pass on. One bad entry never fails the run.
     *
     * @param array<string, mixed> $posting
     */
    private function normalize(array $posting): ?NormalizedJob
    {
        [$salaryMin, $salaryMax, $currency] = $this->salary($posting);

        try {
            return new NormalizedJob(
                sourceKey: $this->key(),
                title: (string) ($posting['title'] ?? ''),
                company: (string) ($posting['company'] ?? ''),
                applicationUrl: (string) ($posting['url'] ?? ''),
                location: (string) ($posting['location'] ?? ''),
                // No source-side id exists for a search hit (see class docs).
                externalId: null,
                // Deliberately dropped even when the model supplies one, so the
                // lead is enriched from the live posting before scoring.
                description: null,
                postedAt: $this->postedAt($posting),
                salaryMin: $salaryMin,
                salaryMax: $salaryMax,
                currency: $currency,
            );
        } catch (Throwable $e) {
            Log::warning('Skipped an unusable LLM web-search job lead.', [
                'source' => $this->key(),
                'title' => $posting['title'] ?? null,
                'url' => $posting['url'] ?? null,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whole annual units plus an ISO currency, or all-null.
     *
     * Same rule as the aggregator providers, applied harder: a model is quite
     * happy to report "120000" with no currency and no period. Without a
     * currency the figure is dropped, because
     * {@see \App\Services\ModelTierContext} does no FX conversion and would
     * read it as though it were already in the threshold currency
     * (Requirement 4.3).
     *
     * @param array<string, mixed> $posting
     *
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    private function salary(array $posting): array
    {
        $min = $this->salaryBound($posting['salary_min'] ?? null);
        $max = $this->salaryBound($posting['salary_max'] ?? null);

        if ($min === null && $max === null) {
            return [null, null, null];
        }

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $currency = isset($posting['salary_currency'])
            ? strtoupper(trim((string) $posting['salary_currency']))
            : '';

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            Log::warning(
                'Dropped an LLM web-search salary: no ISO currency was reported, and an '
                .'unlabelled figure would be misread by the salary tier thresholds.',
                [
                    'source' => $this->key(),
                    'url' => $posting['url'] ?? null,
                    'reported_currency' => $posting['salary_currency'] ?? null,
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

        // The prompt asks for annual figures. A three-digit number is an hourly
        // rate the model failed to convert, or a monthly one; either way it is
        // not what it claims to be, so it goes rather than reading as a
        // near-zero salary that suppresses tier escalation.
        return $amount >= 1000 ? $amount : null;
    }

    /**
     * A model-reported date, kept only when it is plausible: not in the future,
     * not older than `max_posted_age_days`. Anything else is null, which the
     * rest of the pipeline already treats as "the source didn't say".
     *
     * @param array<string, mixed> $posting
     */
    private function postedAt(array $posting): ?Carbon
    {
        if (! $this->config('trust_dates', true)) {
            return null;
        }

        $value = $posting['posted_at'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $postedAt = Carbon::parse(trim($value));
        } catch (Throwable) {
            return null;
        }

        $maxAgeDays = max(1, (int) $this->config('max_posted_age_days', 30));

        if ($postedAt->isFuture() || $postedAt->lt(Carbon::now()->subDays($maxAgeDays))) {
            return null;
        }

        return $postedAt;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function messages(JobSearchQuery $query, int $requested): array
    {
        $roles = implode(', ', $query->roles);
        $location = $query->location ?? 'anywhere';

        $constraints = [
            "Target roles (any of these): {$roles}.",
            "Location: {$location}.",
            'Only postings that are currently open and accepting applications.',
            'Posted within the last '.max(1, (int) $this->config('max_posted_age_days', 30)).' days.',
            'Return at most '.$requested.' postings.',
        ];

        if ($query->remoteOnly) {
            $constraints[] = 'Remote positions only.';
        }

        $system = <<<'PROMPT'
        You are a job-search researcher. Use web search to find real, currently
        open job postings and report them as structured data.

        Rules you must follow:
        - Every posting must come from a search result you actually retrieved.
          Never fill a slot from memory, and never guess a URL.
        - `url` must be the exact link to that posting's own page (the company's
          careers site or its ATS/job-board page), not a search-results page, a
          homepage, or a listing aggregator's index.
        - Report the employer in `company`, not a recruiting agency, unless the
          agency is genuinely the employer.
        - Salary only when the posting states it. Convert it to a whole ANNUAL
          amount and give the ISO-4217 currency code. If the posting states no
          pay, or you cannot tell the currency, leave all three salary fields
          null. Do not estimate a market rate.
        - `posted_at` is an ISO-8601 date, only if the posting states one.
          Otherwise null. Do not approximate it from a "3 weeks ago" label if
          you are unsure.
        - Fewer accurate postings is the correct answer. Returning an empty list
          is better than padding it. Do not repeat the same posting twice.
        PROMPT;

        $user = "Find job postings matching:\n- ".implode("\n- ", $constraints);

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * The structured-output contract. Strict mode requires every property to be
     * listed in `required` and `additionalProperties: false`, so the optional
     * fields are expressed as nullable types rather than being omitted.
     *
     * Note there is no description field: see the class docs on why a
     * model-authored description is not wanted, and would be discarded anyway.
     *
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'name' => 'job_search_results',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['jobs'],
                'properties' => [
                    'jobs' => [
                        'type' => 'array',
                        'description' => 'Job postings found via web search. May be empty.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => [
                                'title',
                                'company',
                                'url',
                                'location',
                                'salary_min',
                                'salary_max',
                                'salary_currency',
                                'posted_at',
                            ],
                            'properties' => [
                                'title' => [
                                    'type' => 'string',
                                    'description' => 'Job title exactly as the posting states it.',
                                ],
                                'company' => [
                                    'type' => 'string',
                                    'description' => 'Hiring employer name.',
                                ],
                                'url' => [
                                    'type' => 'string',
                                    'description' => 'Absolute https URL of the posting itself.',
                                ],
                                'location' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'Location as stated, or "Remote".',
                                ],
                                'salary_min' => [
                                    'type' => ['number', 'null'],
                                    'description' => 'Stated annual minimum, whole units. Null if not stated.',
                                ],
                                'salary_max' => [
                                    'type' => ['number', 'null'],
                                    'description' => 'Stated annual maximum, whole units. Null if not stated.',
                                ],
                                'salary_currency' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'ISO-4217 code, e.g. CAD. Null if no salary is stated.',
                                ],
                                'posted_at' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'ISO-8601 date the posting states. Null if unknown.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("services.llm_job_search.{$key}", $default);
    }
}
