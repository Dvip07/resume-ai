<?php

namespace App\Jobs;

use App\Enums\EnrichmentOutcome;
use App\Enums\PipelineStage;
use App\Models\JobListing;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Enrichment\JobEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The one enrichment implementation (Requirements 3.4, 12.3, task 9.3).
 *
 * It replaces three divergent scrapers that all did roughly this and none of it
 * the same way:
 *
 *  - `JobListingController::scrapeAndUpdateJobDescription()` — ran inside a web
 *    request, on a page view, driving Chrome through Panther with random
 *    `sleep()` calls; a slow or blocked posting was a slow or failed page load,
 *    and its extracted text was frequently thrown away by a bug in its own
 *    control flow.
 *  - `JobListingController::updateJobDescriptions()` (route
 *    `GET /update-job-descriptions`) — the same thing again, hard-coded to a
 *    single `api_id` left over from debugging.
 *  - the `jobs:update-descriptions` command + `App\Jobs\UpdateJobDescription` —
 *    a Spatie-async pool that re-scraped *every* row every thirty seconds with
 *    one selector (`.job-description`), no robots.txt check and no retry
 *    semantics.
 *
 * All three are deleted. What is left here is a queued job that owns exactly
 * two things — *which* row to enrich, and *what the outcome means for the
 * pipeline* — and delegates the fetching and extraction to
 * {@see JobEnrichmentService}, which knows nothing about Eloquent and is
 * therefore testable against fixture HTML.
 *
 * ## Outcome → `pipeline_stage`
 *
 * | Outcome                              | Result                                        |
 * |--------------------------------------|-----------------------------------------------|
 * | `Success`                            | description saved; stage stays `enriching`    |
 * | `NoContent`                          | best-effort text kept if longer; `needs_review` |
 * | `Blocked`                            | `needs_review` (a bot wall is never bypassed) |
 * | `RobotsDisallowed`                   | `needs_review` (Requirement 3.4c)             |
 * | `InvalidUrl`                         | `needs_review` (nothing to fetch, ever)       |
 * | `FetchFailed`                        | rethrown → queue retry → `failed` on exhaustion |
 *
 * A successful run deliberately leaves the row at `enriching` rather than
 * inventing an "enriched" stage: the next owner of the row is scoring, and
 * `scored` is its transition to make (task 10.3). The stage vocabulary stays in
 * {@see PipelineStage} and each stage is advanced by the job that completes it.
 *
 * On the two paths that leave a scoreable description behind — a successful
 * fetch, and a row that already had one — {@see self::score()} continues the
 * chain into {@see ScoreJobListing} for the user this enrichment was dispatched
 * for (Requirement 11.3). Dead ends score nothing; see that method.
 *
 * `needs_review` is used for every dead end instead of `failed` because these
 * are all cases a person can resolve in seconds (paste the description, fix the
 * URL, decide the posting isn't worth it) while no amount of retrying will
 * (design.md, "Error handling"). `failed` is reserved for the transport
 * failures that were genuinely retried and still didn't work.
 */
class EnrichJobDescription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Transport failures get two more chances before the row is marked failed. */
    public int $tries = 3;

    /** Seconds between attempts. Career pages rate-limit, so back off hard. */
    public array $backoff = [120, 600];

    /**
     * @param int      $jobListingId Row to enrich.
     * @param bool     $force        Re-enrich even when the stored description
     *                               already clears the minimum length. The default
     *                               (false) is what makes this job safe to dispatch
     *                               from discovery on every sighting of a job — the
     *                               behaviour the thirty-second re-scrape of every
     *                               row lacked.
     * @param int|null $scoreForUser Whose pipeline this enrichment belongs to, so
     *                               the `Enrich → Score` link can be made
     *                               (Requirement 11.3). Nullable because `job_listings`
     *                               rows are shared between users and an enrichment
     *                               can legitimately be triggered for the row itself —
     *                               a backfill, an operator fixing a bad scrape — with
     *                               no particular pipeline behind it. Null means
     *                               "enrich only"; nothing is scored.
     */
    public function __construct(
        public readonly int $jobListingId,
        public readonly bool $force = false,
        public readonly ?int $scoreForUser = null,
    ) {}

    public function handle(JobEnrichmentService $enrichment): void
    {
        $listing = JobListing::find($this->jobListingId);

        if ($listing === null) {
            // Deleted between dispatch and execution. Retrying cannot help.
            Log::notice('Skipping enrichment: the job listing no longer exists.', [
                'job_listing_id' => $this->jobListingId,
            ]);

            return;
        }

        if (! $this->force && ! $this->needsEnrichment($listing)) {
            Log::debug('Skipping enrichment: the stored description is already usable.', [
                'job_listing_id' => $listing->id,
                'length' => mb_strlen((string) $listing->description),
            ]);

            // Nothing to fetch is not a dead end: the description is scoreable
            // as it stands, which is the condition the next stage was waiting
            // for.
            $this->score($listing);

            return;
        }

        $this->markEnriching($listing);

        $result = $enrichment->fetchDescription($listing->application_url);
        $context = $result->context() + [
            'job_listing_id' => $listing->id,
            'url' => $listing->application_url,
            'attempt' => $this->attemptNumber(),
        ];

        if ($result->successful()) {
            $this->store($listing, (string) $result->description);
            Log::info('Enriched a job description.', $context);

            $this->score($listing);

            return;
        }

        if ($result->outcome === EnrichmentOutcome::FetchFailed) {
            // Transient by definition (timeout, DNS, 5xx). Hand it back to the
            // queue rather than deciding a stage from one bad minute; `failed()`
            // records the give-up once the retries are spent.
            Log::warning('Enrichment fetch failed; leaving it to the queue to retry.', $context);

            throw new RuntimeException(
                "Enrichment fetch failed for job listing {$listing->id}: ".($result->reason ?? 'unknown reason')
            );
        }

        $this->flagForReview($listing, $result, $context);
    }

    /**
     * Is there anything worth fetching for this row?
     *
     * Delegates to {@see JobListing::needsEnrichment()} — which mirrors
     * {@see \App\Services\JobSources\NormalizedJob::needsEnrichment()} — so
     * discovery and enrichment agree on what "too short to score" means,
     * rather than each carrying its own threshold.
     */
    protected function needsEnrichment(JobListing $listing): bool
    {
        return $listing->needsEnrichment();
    }

    /**
     * Move the row into `enriching` so the dashboard can show work in progress
     * and a concurrent stage can see the row is claimed.
     *
     * Terminal stages are left alone deliberately: a manual re-run of a
     * `needs_review` posting is legitimate, but an `applied` row must never be
     * dragged back into the pipeline by a stray dispatch (design.md Property 7,
     * stages move forward only).
     */
    protected function markEnriching(JobListing $listing): void
    {
        if ($listing->pipeline_stage === PipelineStage::Enriching) {
            return;
        }

        if ($listing->pipeline_stage === PipelineStage::Applied
            || $listing->pipeline_stage === PipelineStage::Applying) {
            return;
        }

        $listing->update(['pipeline_stage' => PipelineStage::Enriching]);
    }

    /** Persist a full description. */
    protected function store(JobListing $listing, string $description): void
    {
        $listing->update(['description' => $description]);
    }

    /**
     * The `Enrich → Score` link (Requirement 11.3).
     *
     * Dispatched rather than chained, for the reason
     * {@see DiscoverJobsForUser} gives about the link before it: one posting's
     * dead end is that row's outcome, not a reason to stop the rest of a
     * discovery run.
     *
     * Only ever reached from the two paths where a scoreable description exists —
     * a successful fetch, or a stored description that never needed one. A dead
     * end ({@see flagForReview()}) deliberately scores nothing: `needs_review`
     * means a person has to supply the text, and scoring a bot wall's "please
     * enable JavaScript" would buy a confident rating of nothing. Re-triggering
     * is Requirement 5.6's job.
     *
     * {@see ScoreJobListing} re-checks everything that matters anyway — the
     * stage, the profile's existence, whether the row already moved on — so a
     * dispatch from here makes no promises on its behalf.
     */
    protected function score(JobListing $listing): void
    {
        if ($this->scoreForUser === null) {
            return;
        }

        ScoreJobListing::dispatch((int) $listing->id, $this->scoreForUser);

        Log::debug('Queued scoring for an enriched job listing.', [
            'job_listing_id' => $listing->id,
            'user_id' => $this->scoreForUser,
        ]);
    }

    /**
     * Record a dead end as `needs_review`, keeping any partial text that beats
     * what is already stored.
     *
     * The "longer wins" rule is the orchestrator's, reused: a below-threshold
     * fragment is not scoreable on its own, but it is what a reviewer reads to
     * decide whether the posting is worth chasing, and it is strictly more
     * information than the teaser it replaces.
     *
     * @param array<string, mixed> $context
     */
    protected function flagForReview(JobListing $listing, EnrichmentResult $result, array $context): void
    {
        $attributes = ['pipeline_stage' => PipelineStage::NeedsReview];

        if ($result->description !== null
            && mb_strlen($result->description) > mb_strlen((string) $listing->description)) {
            $attributes['description'] = $result->description;
        }

        $listing->update($attributes);

        Log::warning('Enrichment needs a human: flagged the job listing for review.', $context);
    }

    /**
     * Attempt number for logs, tolerant of being invoked outside a worker (a
     * synchronous dispatch or a direct `handle()` call has no queue job to ask).
     */
    protected function attemptNumber(): int
    {
        try {
            return max(1, $this->attempts());
        } catch (Throwable) {
            return 1;
        }
    }

    /**
     * Retries exhausted on a transport failure (or the job was killed): the
     * stage that owned the row gave up, which is exactly what `failed` means
     * (Requirement 11.2).
     */
    public function failed(?Throwable $e): void
    {
        Log::error('Enrichment failed permanently for a job listing.', [
            'job_listing_id' => $this->jobListingId,
            'reason' => $e?->getMessage(),
        ]);

        JobListing::where('id', $this->jobListingId)
            ->whereNotIn('pipeline_stage', [
                PipelineStage::Applied->value,
                PipelineStage::Applying->value,
            ])
            ->update(['pipeline_stage' => PipelineStage::Failed->value]);
    }
}
