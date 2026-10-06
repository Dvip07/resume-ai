<?php

namespace App\Services\JobSources;

use App\Enums\PipelineStage;
use App\Models\JobListing;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The fan-out stage of the pipeline (Requirements 3.2, 3.3): ask every enabled
 * source for jobs, fold the results into `job_listings` without creating
 * duplicates, and leave new rows at `pipeline_stage = discovered` for
 * enrichment/scoring to pick up.
 *
 * It names no provider class — sources come from {@see JobSourceRegistry},
 * which reads `config/job_sources.php` — so adding a source stays additive
 * (Requirement 3.2). Everything downstream reads `job_listings` and never
 * learns which source produced a row.
 *
 * ## Fault tolerance
 *
 * One dead source does not fail the run. A provider that throws is logged and
 * skipped (design.md, "Provider rate-limit/budget exhaustion"), as is a single
 * unusable result inside an otherwise good response. Callers that need to know
 * read {@see JobDiscoveryResult::failures()} — worth alerting on when
 * {@see JobDiscoveryResult::allProvidersFailed()} is true, since that means
 * misconfiguration rather than a quiet job market.
 *
 * ## Daily budgets (Requirement 3.6)
 *
 * Before each provider is called, {@see ProviderDailyLimiter} reserves one call
 * against that source's daily allowance. A source out of budget is skipped and
 * recorded in {@see JobDiscoveryResult::rateLimited()} — deliberately *not* a
 * failure: "Adzuna is done for today" is a normal, expected outcome, while
 * `failures()` means something is broken, and conflating them would make
 * `allProvidersFailed()` cry wolf every evening.
 *
 * ## Deduplication (Requirement 3.3)
 *
 * Two keys are tried, in this order, against the whole `job_listings` table:
 *
 *  1. **The source's own external id** — `source_key` + `api_id`. Exact, so it
 *     is preferred: it still matches after the employer edits the title, which
 *     the heuristic hash would not.
 *  2. **The composite hash** — `dedupe_hash`, over normalized
 *     title + company + location ({@see JobDedupeHasher}). This is what catches
 *     the same posting arriving from *different* sources, where the ids are
 *     unrelated and `api_id` alone can't help — the gap Requirement 3.3 calls
 *     out.
 *
 * Because rows are persisted as the fan-out proceeds, this also dedupes within
 * a single run: whichever provider is listed first in config wins the row, and
 * the later provider's copy is folded into it.
 *
 * ## What an update may and may not change
 *
 * A duplicate never resets progress. `pipeline_stage` is left exactly as it is,
 * which is what keeps stage transitions moving forward only (design.md
 * Property 7) — rediscovering a job that already reached `applied` must not
 * send it back to `discovered` and re-apply to it. `source_key`/`api_id` stay
 * with the source that discovered the row first, so its exact-id path keeps
 * working.
 *
 * Otherwise the update fills gaps: a longer description replaces a shorter one
 * (aggregators return teasers, and one source's full text is worth having),
 * missing salary/location/posted_at get populated, and an already-enriched
 * description is never clobbered by a shorter teaser from a later run.
 */
class JobDiscoveryOrchestrator
{
    public function __construct(
        private readonly JobSourceRegistry $registry,
        private readonly ProviderDailyLimiter $limiter = new ProviderDailyLimiter(),
        private readonly JobDedupeHasher $hasher = new JobDedupeHasher(),
    ) {}

    /**
     * Run discovery for one query across every enabled source.
     *
     * Never throws for provider-level problems; see the class docs.
     */
    public function discover(JobSearchQuery $query): JobDiscoveryResult
    {
        $result = new JobDiscoveryResult();

        foreach ($this->registry->enabled() as $sourceKey => $provider) {
            $result->registerProvider($sourceKey);

            // Requirement 3.6: a source that has spent its daily budget is
            // skipped, not called, and not treated as a failure — one exhausted
            // provider must not fail the run (design.md, "Provider rate-limit/
            // budget exhaustion").
            if (! $this->limiter->attempt($sourceKey, $query->userId)) {
                $result->recordRateLimited($sourceKey, $this->limiter->limitFor($sourceKey));

                Log::notice('Job source has spent its daily budget; skipping it for this run.', [
                    'source' => $sourceKey,
                    'user_id' => $query->userId,
                    'daily_limit' => $this->limiter->limitFor($sourceKey),
                ]);

                continue;
            }

            try {
                // Providers are contractually required to throw rather than
                // return partial results, so a value here is a complete answer.
                $jobs = $provider->search($query);
            } catch (Throwable $e) {
                $result->recordProviderFailure($sourceKey, $e);

                Log::warning('Job source failed during discovery; skipping it for this run.', [
                    'source' => $sourceKey,
                    'user_id' => $query->userId,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($jobs as $job) {
                if (! $job instanceof NormalizedJob) {
                    $result->recordSkipped($sourceKey);

                    Log::warning('Job source returned a value that is not a NormalizedJob.', [
                        'source' => $sourceKey,
                        'type' => get_debug_type($job),
                    ]);

                    continue;
                }

                $this->persist($job, $sourceKey, $result);
            }
        }

        Log::info('Job discovery run complete.', ['user_id' => $query->userId] + $result->toArray());

        return $result;
    }

    /**
     * Fold one normalized job into `job_listings`: update the existing row if
     * the dedupe rules find one, otherwise insert at `discovered`.
     *
     * `$sourceKey` is the provider being iterated, which the registry has
     * already proven equal to `$job->sourceKey`; it is passed separately so
     * per-provider stats stay attributed to the caller even if a provider
     * mislabels a job.
     */
    private function persist(NormalizedJob $job, string $sourceKey, JobDiscoveryResult $result): void
    {
        try {
            $hash = $job->dedupeHash($this->hasher);
            $existing = $this->findDuplicate($job, $hash);

            if ($existing !== null) {
                $this->fillGaps($existing, $job, $hash);
                $result->recordUpdated($sourceKey, (int) $existing->id);

                return;
            }

            $result->recordCreated($sourceKey, (int) $this->create($job, $hash)->id);
        } catch (Throwable $e) {
            // A single bad row (a DB constraint, a racing insert from a
            // concurrent run) must not cost us the rest of the results.
            $result->recordSkipped($sourceKey);

            Log::warning('Could not persist a discovered job.', [
                'source' => $sourceKey,
                'external_id' => $job->externalId,
                'fingerprint' => $job->dedupeFingerprint($this->hasher),
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Requirement 3.3's two-key lookup: the source's own id first (exact), then
     * the composite hash (cross-source).
     */
    private function findDuplicate(NormalizedJob $job, string $hash): ?JobListing
    {
        if ($job->externalId !== null) {
            $byExternalId = JobListing::query()
                ->where('source_key', $job->sourceKey)
                ->where('api_id', $job->externalId)
                ->first();

            if ($byExternalId !== null) {
                return $byExternalId;
            }
        }

        return JobListing::query()->where('dedupe_hash', $hash)->first();
    }

    private function create(NormalizedJob $job, string $hash): JobListing
    {
        return JobListing::query()->create([
            'api_id' => $job->externalId,
            'title' => $job->title,
            'company' => $job->company,
            'location' => $job->location,
            // NOT NULL in the original schema; '' is the honest value for the
            // aggregators that ship no description, and is what makes the row
            // a candidate for enrichment (Requirement 3.4).
            'description' => $job->description ?? '',
            // Legacy free-text column, kept in step with `source_key` so the
            // Blade-era reads of `api_source` still see something sensible.
            'api_source' => $job->sourceKey,
            'source_key' => $job->sourceKey,
            'dedupe_hash' => $hash,
            'posted_at' => $job->postedAt,
            'is_active' => true,
            // Populated by the scoring stage; NOT NULL, so seed it empty.
            'parsed_skills' => [],
            'application_url' => $job->applicationUrl,
            // Every row enters the pipeline here (Requirement 3.3).
            'pipeline_stage' => PipelineStage::Discovered,
            'salary_min' => $job->salaryMin,
            'salary_max' => $job->salaryMax,
            'currency' => $job->currency,
        ]);
    }

    /**
     * Enrich an existing row from a duplicate sighting without undoing any
     * pipeline progress. See the class docs for the rules; `pipeline_stage`,
     * `source_key`/`api_id` and `application_url` are intentionally not
     * reassigned once set.
     */
    private function fillGaps(JobListing $existing, NormalizedJob $job, string $hash): void
    {
        $updates = [];

        // A longer description is a better one: aggregators truncate, and the
        // enrichment stage's full text must survive a later teaser.
        if ($job->description !== null
            && mb_strlen($job->description) > mb_strlen((string) $existing->description)) {
            $updates['description'] = $job->description;
        }

        foreach (['location' => $job->location, 'application_url' => $job->applicationUrl] as $column => $value) {
            if ($value !== '' && trim((string) $existing->{$column}) === '') {
                $updates[$column] = $value;
            }
        }

        if ($existing->posted_at === null && $job->postedAt !== null) {
            $updates['posted_at'] = $job->postedAt;
        }

        // Salary bounds move together with their currency — mixing a bound from
        // one source with a currency from another would produce a figure the
        // (FX-blind) tier thresholds would misread.
        if (! $existing->hasSalary() && $job->hasSalary()) {
            $updates['salary_min'] = $job->salaryMin;
            $updates['salary_max'] = $job->salaryMax;
            $updates['currency'] = $job->currency;
        }

        // Legacy rows predate the discovery columns: claim them for the source
        // that just re-found them so the exact-id path works from now on.
        if ($existing->source_key === null) {
            $updates['source_key'] = $job->sourceKey;
            $updates['api_id'] = $existing->api_id ?? $job->externalId;
        }

        // Keep the hash current: it changes when the source edits a title, and
        // rows matched by external id would otherwise keep a stale hash and
        // stop colliding with the same posting from other sources.
        if ($existing->dedupe_hash !== $hash) {
            $updates['dedupe_hash'] = $hash;
        }

        if ($updates !== []) {
            $existing->fill($updates)->save();
        }
    }
}
