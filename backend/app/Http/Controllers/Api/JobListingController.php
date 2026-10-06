<?php

namespace App\Http\Controllers\Api;

use App\Enums\PipelineStage;
use App\Http\Controllers\Controller;
use App\Jobs\DiscoverJobsForUser;
use App\Jobs\ScoreJobListing;
use App\Jobs\SubmitApplication;
use App\Jobs\TailorResume;
use App\Models\JobListing;
use App\Models\JobScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON API surface for job listings (Sanctum bearer-token auth).
 *
 * This is a thin read layer over the `job_listings` table plus three manual
 * pipeline triggers. The Adzuna-fetch-and-upsert side effect that used to run
 * on every Blade page view is not reproduced here: discovery belongs to the
 * pipeline (JobDiscoveryOrchestrator, driven by the queued DiscoverJobsForUser
 * job), not to a request-scoped read. Likewise, the on-demand Panther/
 * ChromeDriver scraping triggered from the Blade `show()` method is not
 * reproduced here; description enrichment is a background job concern
 * (App\Jobs\EnrichJobDescription), not something a GET should trigger
 * synchronously.
 *
 * Every write endpoint here follows the same shape: validate, queue, answer
 * 202. Scoring, tailoring and applying each take seconds to minutes and talk
 * to third parties, which no request may hold open (Requirement 11.1). The
 * frontend polls the reads for the outcome.
 *
 * Validates: Requirements 10.1, 10.2, 11.1
 */
class JobListingController extends Controller
{
    /**
     * List active job listings (paginated), most recently posted first.
     *
     * Each row carries the authenticated user's current star rating for that
     * listing under `score`, because the list renders the rating next to the
     * stage (Requirement 10.1) and a per-row fetch would be a round trip per
     * row. Scores are per (listing, user) and versioned by `attempt_number`
     * (Requirement 5.6), so "the current rating" is a max-attempt pick that no
     * `belongsTo` expresses — hence the one extra query rather than a relation.
     *
     * Filters (design.md `GET /api/jobs`), all optional and combinable:
     * - `pipeline_stage` — exact stage match.
     * - `source`         — matches either `source_key` (the pipeline's own
     *                      identifier) or the legacy `api_source` column, so a
     *                      caller does not have to know which one a given row
     *                      was written with.
     * - `rating`         — minimum stars on the user's *latest* attempt.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $validated = $request->validate([
            'pipeline_stage' => ['sometimes', 'nullable', Rule::enum(PipelineStage::class)],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rating' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $query = JobListing::query()
            ->where('is_active', true)
            ->orderBy('posted_at', 'desc');

        if (! empty($validated['pipeline_stage'])) {
            $query->where('pipeline_stage', $validated['pipeline_stage']);
        }

        if (! empty($validated['source'])) {
            $source = $validated['source'];
            $query->where(function ($builder) use ($source) {
                $builder->where('source_key', $source)
                    ->orWhere('api_source', $source);
            });
        }

        if (! empty($validated['rating'])) {
            $query->whereIn('id', $this->listingIdsRatedAtLeast(
                (int) $validated['rating'],
                $userId,
            ));
        }

        $jobListings = $query->paginate(15)->withQueryString();

        $scores = $this->latestScoresFor(
            $jobListings->getCollection()->pluck('id')->all(),
            $userId,
        );

        $jobListings->setCollection(
            $jobListings->getCollection()->map(
                fn (JobListing $listing) => $this->withScore($listing, $scores)
            )
        );

        return response()->json($jobListings);
    }

    /**
     * Show a single job listing, with the user's current score attached so the
     * detail screen can render the rating and its rationale in one read
     * (Requirement 10.2).
     */
    public function show(Request $request, JobListing $jobListing): JsonResponse
    {
        $userId = (int) $request->user()->id;

        return response()->json($this->withScore(
            $jobListing,
            $this->latestScoresFor([$jobListing->id], $userId),
        ));
    }

    /**
     * Manually trigger a discovery run for the authenticated user
     * (`POST /api/jobs/discover` per design.md).
     *
     * Queues DiscoverJobsForUser and answers 202 immediately — the fan-out
     * across sources takes seconds to minutes, which no request should hold
     * (Requirement 11.1). The frontend polls `GET /api/jobs` for the results.
     *
     * Roles/location default to the user's profile; optional overrides let the
     * user search for something other than their suggested roles.
     */
    public function discover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        DiscoverJobsForUser::dispatch(
            $request->user()->id,
            $validated['roles'] ?? null,
            $validated['location'] ?? null,
            $validated['limit'] ?? null,
        );

        return response()->json([
            'message' => 'Job discovery has been queued.',
        ], 202);
    }

    /**
     * Ask for a fresh verdict on one listing (`POST /api/jobs/{id}/rescore`,
     * Requirement 5.6).
     *
     * Uses ScoreJobListing::rescore(), the explicit user-initiated entry point:
     * it lets a `tailoring`/`tailored` row through where an automated dispatch
     * would be dropped, and still refuses `applying`/`applied`. The previous
     * score is never overwritten — the job inserts the next attempt — so the
     * endpoint is safe to call twice.
     */
    public function rescore(Request $request, JobListing $jobListing): JsonResponse
    {
        dispatch(ScoreJobListing::rescore(
            $jobListing->id,
            (int) $request->user()->id,
        ));

        return response()->json([
            'message' => 'Re-scoring has been queued.',
        ], 202);
    }

    /**
     * Manually trigger tailoring for one listing (`POST /api/jobs/{id}/tailor`).
     *
     * This is the way out of `store_only`: a listing the pipeline decided not
     * to auto-apply to still gets documents if the user asks. The job owns the
     * stage guard and the cover-letter decision, so nothing is pre-judged here.
     */
    public function tailor(Request $request, JobListing $jobListing): JsonResponse
    {
        $validated = $request->validate([
            'template_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        TailorResume::dispatch(
            $jobListing->id,
            (int) $request->user()->id,
            $validated['template_key'] ?? null,
        );

        return response()->json([
            'message' => 'Tailoring has been queued.',
        ], 202);
    }

    /**
     * Manually trigger submission for one listing (`POST /api/jobs/{id}/apply`).
     *
     * `answers` carries replies to application questions the automation could
     * not answer on an earlier attempt (Requirement 10.3) — the shape is
     * per-adapter, so it is accepted as a flat string map and handed through.
     * SubmitApplication enforces consent, the daily limit, and that documents
     * exist; none of that is duplicated here.
     */
    public function apply(Request $request, JobListing $jobListing): JsonResponse
    {
        $validated = $request->validate([
            'answers' => ['sometimes', 'array'],
            'answers.*' => ['nullable', 'string', 'max:5000'],
        ]);

        SubmitApplication::dispatch(
            $jobListing->id,
            (int) $request->user()->id,
            $validated['answers'] ?? [],
        );

        return response()->json([
            'message' => 'The application has been queued for submission.',
        ], 202);
    }

    /**
     * Attach the user's current score to a listing for JSON output.
     *
     * Returned as a plain array rather than set on the model so the listing's
     * own attributes are untouched (nothing here is persisted) and a listing
     * with no score serialises `score: null` instead of omitting the key.
     *
     * @param  array<int, JobScore>  $scores  keyed by `job_listing_id`
     * @return array<string, mixed>
     */
    private function withScore(JobListing $listing, array $scores): array
    {
        $score = $scores[$listing->id] ?? null;

        return array_merge($listing->toArray(), [
            'score' => $score === null ? null : [
                'stars' => $score->stars,
                'recommended_action' => $score->recommended_action,
                'attempt_number' => $score->attempt_number,
                'rationale' => $score->rationale,
                'fit_analysis' => $score->fit_analysis,
                'created_at' => $score->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * The current (highest-attempt) score per listing for one user, keyed by
     * `job_listing_id`.
     *
     * @param  array<int, int|null>  $jobListingIds
     * @return array<int, JobScore>
     */
    private function latestScoresFor(array $jobListingIds, int $userId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $jobListingIds))));

        if ($ids === []) {
            return [];
        }

        return JobScore::query()
            ->whereIn('job_listing_id', $ids)
            ->where('user_id', $userId)
            // Ascending, so the highest attempt is the last write into the map.
            ->orderBy('attempt_number')
            ->get()
            ->keyBy('job_listing_id')
            ->all();
    }

    /**
     * Listing ids whose *latest* score for this user is at least `$stars`.
     *
     * The correlated max-attempt subquery is the point: filtering on any
     * attempt would resurface a listing the user re-scored downwards, which is
     * the opposite of what a "4 stars and up" filter means.
     *
     * @return array<int, int>
     */
    private function listingIdsRatedAtLeast(int $stars, int $userId): array
    {
        return JobScore::query()
            ->where('user_id', $userId)
            ->where('stars', '>=', $stars)
            ->whereRaw(
                'attempt_number = (select max(attempt_number) from job_scores as latest'
                .' where latest.job_listing_id = job_scores.job_listing_id'
                .' and latest.user_id = job_scores.user_id)'
            )
            ->pluck('job_listing_id')
            ->all();
    }
}
