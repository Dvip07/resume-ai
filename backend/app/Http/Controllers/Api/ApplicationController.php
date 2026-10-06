<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationResponseStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\JobScore;
use App\Support\ApplicationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON API surface for the authenticated user's applications (Sanctum
 * bearer-token auth).
 *
 * The Blade-era App\Http\Controllers\Application controller has no
 * existing read logic to reuse (all methods are stubs), so this reads
 * directly from the `Application` model, scoped to the authenticated user.
 *
 * Validates: Requirements 10.1, 10.2, 10.4
 */
class ApplicationController extends Controller
{
    public function __construct(private readonly ApplicationPresenter $presenter)
    {
    }

    /**
     * List the authenticated user's applications (paginated).
     *
     * Each row carries its listing's `pipeline_stage` and the latest star
     * rating alongside the raw application columns, because that is what the
     * dashboard list renders (Requirement 10.1) and a per-row fetch for either
     * would be a round trip per row.
     *
     * The scores are collected in one query rather than through a relation:
     * `job_scores` is versioned by `attempt_number` (Requirement 5.6) and
     * scoped to (listing, user), so "the current rating" is a per-pair
     * max-attempt pick that no `belongsTo` expresses.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $applications = Application::query()
            ->with(ApplicationPresenter::relations())
            ->where('user_id', $userId)
            ->latest()
            ->paginate(15);

        $scores = $this->latestScoresFor(
            $applications->getCollection()->pluck('job_listing_id')->all(),
            $userId,
        );

        $applications->setCollection(
            $applications->getCollection()->map(
                fn (Application $application) => $this->presenter->summary($application, $scores)
            )
        );

        return response()->json($applications);
    }

    /**
     * Show a single application owned by the authenticated user, with
     * everything the detail screen needs: the full JD, the score rationale,
     * signed document links, and the automation log (Requirement 10.2).
     */
    public function show(Request $request, Application $application): JsonResponse
    {
        if ($application->user_id !== $request->user()->id) {
            abort(404);
        }

        $application->load(ApplicationPresenter::relations());

        return response()->json($this->presenter->detail($application));
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
     * Record a manual status update for one of the user's applications
     * (Requirement 10.4).
     *
     * ## Why this never touches `status`
     *
     * `applications.status` is written only by the pipeline (TailorResume
     * creates the row as `pending`, the apply step moves it on), as is the
     * listing's `pipeline_stage`. A user reporting "I got an interview" is
     * reporting something the automation cannot observe, so it lands in the
     * long-unused, nullable `response_status` column instead. The two never
     * overwrite each other, which is exactly what "persisted distinctly" asks
     * for: the dashboard can show the automation's progress and the
     * employer's response side by side, and a later apply retry won't erase
     * what the user reported.
     *
     * A `status` key in the payload is rejected rather than ignored, so a
     * frontend trying to drive the pipeline through this endpoint fails loudly.
     *
     * ## Audit trail
     *
     * Every accepted patch appends an entry to
     * `metadata.manual_status_history` (value, optional note, timestamp) on top
     * of overwriting `response_status`. The history is what makes "manual" and
     * "automated" distinguishable after the fact — without it, the current
     * `response_status` alone can't say when, or how often, a human intervened.
     * Existing `metadata` keys are preserved.
     */
    public function update(Request $request, Application $application): JsonResponse
    {
        if ($application->user_id !== $request->user()->id) {
            abort(404);
        }

        $validated = $request->validate([
            // Required: an empty patch has nothing to record, and silently
            // returning 200 would look like a successful save to the user.
            'response_status' => ['required', Rule::enum(ApplicationResponseStatus::class)],
            'response_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['prohibited'],
        ], [
            'status.prohibited' => 'The automation-driven status cannot be set manually.',
        ]);

        $metadata = $application->metadata ?? [];
        $history = $metadata['manual_status_history'] ?? [];

        // Guard against a legacy/hand-edited scalar under the key, which would
        // otherwise turn the append below into a silent data loss.
        if (! is_array($history) || ($history !== [] && ! array_is_list($history))) {
            $history = [];
        }

        $history[] = [
            'response_status' => $validated['response_status'],
            'note' => $validated['response_note'] ?? null,
            'recorded_at' => now()->toIso8601String(),
            'source' => 'manual',
        ];

        $metadata['manual_status_history'] = $history;

        $application->update([
            'response_status' => $validated['response_status'],
            'metadata' => $metadata,
        ]);

        // The same shape `show()` returns, so the frontend can drop the
        // response straight into its detail cache after a manual update.
        $fresh = $application->fresh()->load(ApplicationPresenter::relations());

        return response()->json($this->presenter->detail($fresh));
    }
}
