<?php

namespace App\Jobs;

use App\Enums\PipelineStage;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\JobSources\JobDiscoveryOrchestrator;
use App\Services\JobSources\JobDiscoveryResult;
use App\Services\JobSources\JobSearchQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Discovery, as a queued job (Requirement 11.1).
 *
 * This is the entry point of the pipeline
 * (`Discover → Enrich → Score → …`, design.md). It replaces the synchronous
 * fan-out that used to happen inside `JobListingController::index()`, where
 * every page view called Adzuna and upserted rows before rendering — slow,
 * un-retryable, and repeated per visit. Listing screens now read
 * `job_listings` only; filling that table is this job's business.
 *
 * ## Triggers
 *
 *  - the scheduled `jobs:discover` command (the normal path), and
 *  - `POST /api/jobs/discover`, the manual trigger in design.md's API surface,
 *    which dispatches this and returns immediately.
 *
 * ## What it decides, and what it doesn't
 *
 * Only *what to search for*: roles and location come from the user's profile
 * unless the caller overrides them (a manual run may pass what the user typed).
 * Which sources are called, their daily budgets, deduplication and persistence
 * all belong to {@see JobDiscoveryOrchestrator}; this job holds no provider
 * knowledge.
 *
 * ## Handing over to enrichment (Requirement 11.3)
 *
 * Once the fan-out is persisted, every row this run touched whose description
 * is still too short to score gets an {@see EnrichJobDescription} dispatch —
 * the `Discover → Enrich` link of the chain. What it deliberately does *not* do
 * is move `pipeline_stage` on the way past: `enriching` is enrichment's to set
 * and `scored` is the scoring job's (task 10.3). See {@see self::enrich()}.
 *
 * ## Failure behaviour (Requirement 11.2)
 *
 * A provider being down is not this job's failure — the orchestrator absorbs
 * that and reports it in {@see JobDiscoveryResult::failures()}, which is logged
 * here. What *does* fail the job is the run itself not happening (DB down,
 * container/config error); those retry on Laravel's `tries`/`backoff` and
 * finally land in `failed_jobs`.
 *
 * A user with no usable roles, or a user deleted between dispatch and
 * execution, is a no-op rather than a failure: retrying either would never
 * produce a different outcome.
 *
 * Overlapping runs for one user are allowed on purpose. They are harmless — the
 * orchestrator's dedupe folds the second run's results into the same rows and
 * never resets a stage — and the per-source daily budgets already bound what
 * repeated runs can spend. Suppressing them (`ShouldBeUnique`) would mean a
 * manual trigger silently doing nothing while a scheduled run sits in the
 * queue, which is worse than a redundant fan-out.
 */
class DiscoverJobsForUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Transient infrastructure problems get two more chances. */
    public int $tries = 3;

    /** Seconds between attempts: quick retry, then back off. */
    public array $backoff = [60, 300];

    /**
     * @param int               $userId    whose profile drives the search, and whose
     *                                     `provider_usage` counters the run spends
     * @param list<string>|null $roles     override for the profile's suggested roles
     * @param string|null       $location  override for the profile's location
     * @param int|null          $limit     per-provider result ceiling; null uses config
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?array $roles = null,
        public readonly ?string $location = null,
        public readonly ?int $limit = null,
    ) {}

    public function handle(JobDiscoveryOrchestrator $orchestrator): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            // Deleted between dispatch and execution. Nothing to search for,
            // and no amount of retrying brings the user back.
            Log::notice('Skipping job discovery: the user no longer exists.', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        $profile = UserProfile::query()->where('user_id', $this->userId)->first();

        try {
            $query = JobSearchQuery::forUser(
                user: $user,
                roles: $this->roles ?? $this->rolesFromProfile($profile),
                location: $this->location ?? $this->locationFromProfile($profile),
                limit: $this->limit,
            );
        } catch (InvalidArgumentException $e) {
            // No usable role: the profile has no `suggested_roles` yet (no
            // resume parsed) and the caller named none. Not a fault — there is
            // simply nothing to search for until the profile is filled in.
            Log::notice('Skipping job discovery: no roles to search for.', [
                'user_id' => $this->userId,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $result = $orchestrator->discover($query);

        $this->report($query, $result);

        $this->enrich($result);
    }

    /**
     * Hand the rows this run touched to the next stage (Requirement 11.3).
     *
     * This is the `Discover → Enrich → Score` chain of design.md, built by
     * dispatch rather than `Bus::chain()`: enrichment is per-listing and its
     * dead ends (robots disallowed, a bot wall) are *this row's* outcome, not a
     * reason to stop the other listings from the same run. A literal chain would
     * make one blocked posting cancel the rest.
     *
     * Only rows whose stored description is too short to score go to
     * enrichment; a row that already carries full text (from an aggregator that
     * ships it, or from a previous run) is handed straight to
     * {@see ScoreJobListing}, so no page is fetched for it and the chain does
     * not pass through a stage with nothing to do. The threshold is
     * `job_sources.min_description_length`, read through
     * {@see JobListing::needsEnrichment()} so discovery, enrichment and
     * normalization all agree on "too short to score".
     *
     * Stage progression is left to the stages themselves. Enrichment moves the
     * row to `enriching`, and `scored` is set by the scoring job when it has
     * actually scored (task 10.3) — discovery must not pre-announce work that
     * hasn't happened. Rows that already moved past enrichment are skipped
     * entirely, so a rediscovered `applied` posting is never pulled back
     * (design.md Property 7, stages move forward only).
     */
    private function enrich(JobDiscoveryResult $result): void
    {
        $touched = $result->touchedIds();

        if ($touched === []) {
            return;
        }

        $dispatched = [];

        $listings = JobListing::query()
            ->whereIn('id', $touched)
            // `discovered` is where the orchestrator leaves new rows;
            // `enriching` re-admits a row whose worker died mid-fetch, and is
            // the same stage rather than a step back. Every other stage either
            // already has a description or needs an explicit user-triggered
            // re-run.
            ->whereIn('pipeline_stage', [
                PipelineStage::Discovered->value,
                PipelineStage::Enriching->value,
            ])
            ->get();

        $scored = [];

        foreach ($listings as $listing) {
            // Length is compared in PHP, not SQL: `mb_strlen` counts characters
            // the way the enrichment threshold means them, and the row count
            // here is bounded by the per-provider result limit.
            if (! $listing->needsEnrichment()) {
                // Already scoreable, so enrichment would fetch a page for
                // nothing. Scoring is dispatched directly instead of being
                // routed through an enrichment job that would immediately skip
                // — the chain must not depend on a stage that has no work.
                ScoreJobListing::dispatch((int) $listing->id, $this->userId);
                $scored[] = (int) $listing->id;

                continue;
            }

            // The user id rides along so enrichment can make the next link
            // (Requirement 11.3): `job_listings` rows are shared, so the row
            // itself cannot say whose pipeline this run belongs to.
            EnrichJobDescription::dispatch((int) $listing->id, false, $this->userId);
            $dispatched[] = (int) $listing->id;
        }

        Log::info('Queued the next stage for the discovered job listings.', [
            'user_id' => $this->userId,
            'touched' => count($touched),
            'dispatched' => count($dispatched),
            'job_listing_ids' => $dispatched,
            'scored_directly' => count($scored),
            'scored_job_listing_ids' => $scored,
        ]);
    }

    /**
     * Log what the run learned. `allProvidersFailed()` is escalated because it
     * means misconfiguration (dead credentials, no source enabled) rather than
     * a quiet job market — the distinction JobDiscoveryResult exists to draw.
     */
    private function report(JobSearchQuery $query, JobDiscoveryResult $result): void
    {
        $context = [
            'user_id' => $this->userId,
            'roles' => $query->roles,
            'location' => $query->location,
        ] + $result->toArray();

        if ($result->allProvidersFailed()) {
            Log::error('Job discovery reached no working source for this user.', $context);

            return;
        }

        Log::info('Job discovery finished for user.', $context);
    }

    /**
     * Target roles from the parsed profile (`suggested_roles`, written by
     * AnalyzeResumeJob).
     *
     * The column is cast to `array`; anything the cast can't decode arrives as
     * null and is treated as "no roles yet". JobSearchQuery trims and drops
     * blanks, so raw values pass through untouched.
     *
     * @return list<string>
     */
    private function rolesFromProfile(?UserProfile $profile): array
    {
        $roles = $profile?->suggested_roles;

        return is_array($roles) ? array_values($roles) : [];
    }

    /**
     * Where to search. `user_profiles.location` is cast to an array shaped like
     * `['city' => …, 'state' => …, 'country' => …]`; the sources take free text,
     * so the parts that exist are joined most-specific first.
     *
     * Note this is wider than the pre-pipeline behaviour, which sent `country`
     * alone — a country-wide search is what made the Blade screen return Vancouver
     * jobs to a Toronto user. Providers that can't use the extra precision ignore
     * the tail of the string.
     */
    private function locationFromProfile(?UserProfile $profile): ?string
    {
        $location = $profile?->location;

        if (! is_array($location)) {
            return null;
        }

        $parts = [];

        foreach (['city', 'state', 'country'] as $part) {
            $value = trim((string) ($location[$part] ?? ''));

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Permanent failure (retries exhausted, or the job was killed). Recorded so
     * the `failed_jobs` row has a readable companion in the logs
     * (Requirement 11.2).
     */
    public function failed(?Throwable $e): void
    {
        Log::error('Job discovery failed permanently for user.', [
            'user_id' => $this->userId,
            'reason' => $e?->getMessage(),
        ]);
    }
}
