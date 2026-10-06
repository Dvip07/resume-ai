<?php

namespace App\Http\Controllers\Api;

use App\Enums\PipelineStage;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\JobScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline failure counts for the dashboard's status widget
 * (Requirement 11.2).
 *
 * The pipeline runs on a queue worker, so when something gives up it does so
 * out of sight of any request. This endpoint is the one place the frontend can
 * ask "did anything break?" without reading a log file.
 *
 * ## Two different kinds of count, deliberately kept apart
 *
 * - `stages` is **the authenticated user's** work: their listings sitting in a
 *   failure-ish stage (`failed`, `needs_review`). A listing is "theirs" when
 *   they have a score or an application against it — `job_listings` itself is a
 *   shared, de-duplicated catalogue with no owner column.
 * - `queue` is **operational and global**. The `failed_jobs` table is the
 *   framework's own dead-letter store: rows there are not attributable to a
 *   user (one row may be a discovery fan-out for somebody else, or a job with
 *   no user at all), and nothing in the schema lets us scope them. It is
 *   reported as a platform health number, which is why the frontend labels it
 *   as such rather than as "your failures". Do not be tempted to present it as
 *   per-user — the attribution does not exist.
 *
 * Everything here is a count or a single newest row. No pagination, no joins
 * per listing: the widget renders on every dashboard load, so it has to be
 * cheap.
 */
class PipelineHealthController extends Controller
{
    /** Stages that mean "a human should look at this" (PipelineStage). */
    private const FAILURE_STAGES = [
        PipelineStage::Failed,
        PipelineStage::NeedsReview,
    ];

    public function show(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        return response()->json([
            'stages' => $this->stageCounts($userId),
            'queue' => $this->queueHealth(),
            'latest_failure' => $this->latestFailure($userId),
        ]);
    }

    /**
     * Per-stage counts of the user's listings in a failure-ish stage, plus the
     * total. Stages with nothing in them are still present as `0` so the
     * frontend never has to guess whether a missing key means zero or unknown.
     *
     * @return array<string, int>
     */
    private function stageCounts(int $userId): array
    {
        $counts = DB::table('job_listings')
            ->selectRaw('pipeline_stage, count(*) as aggregate')
            ->whereIn('pipeline_stage', array_map(fn (PipelineStage $s) => $s->value, self::FAILURE_STAGES))
            ->whereIn('id', $this->listingIdsForUser($userId))
            ->groupBy('pipeline_stage')
            ->pluck('aggregate', 'pipeline_stage')
            ->all();

        $stages = [];
        foreach (self::FAILURE_STAGES as $stage) {
            $stages[$stage->value] = (int) ($counts[$stage->value] ?? 0);
        }
        $stages['total'] = array_sum($stages);

        return $stages;
    }

    /**
     * The listings this user has any stake in: anything they have been scored
     * against, plus anything they have an application row for.
     *
     * @return array<int, int>
     */
    private function listingIdsForUser(int $userId): array
    {
        $scored = JobScore::query()->where('user_id', $userId)->pluck('job_listing_id');
        $applied = Application::query()->where('user_id', $userId)->pluck('job_listing_id');

        return $scored->merge($applied)
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Queue-level health: how many jobs are in the dead-letter table and when
     * the newest one landed. Global, for the reasons in the class docblock.
     *
     * Guarded by a schema check because `failed_jobs` is created by a framework
     * migration that an install can legitimately be missing; a dashboard widget
     * must not 500 over that.
     *
     * @return array<string, mixed>
     */
    private function queueHealth(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['failed_jobs' => 0, 'newest_failed_at' => null, 'scope' => 'platform'];
        }

        $newest = DB::table('failed_jobs')->max('failed_at');

        return [
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
            'newest_failed_at' => $newest ? (string) $newest : null,
            // Read by the frontend's label, so the number is never shown as if
            // it belonged to this user.
            'scope' => 'platform',
        ];
    }

    /**
     * The user's most recently touched failed/needs-review application, with
     * the reason if the apply attempt recorded one in its `automation_log`
     * (Requirement 9.5). Null when nothing of theirs has failed.
     *
     * Cheap on purpose: one row, and the reason is read from the log already on
     * that row rather than reconstructed from stage history.
     *
     * @return array<string, mixed>|null
     */
    private function latestFailure(int $userId): ?array
    {
        $application = Application::query()
            ->where('user_id', $userId)
            ->whereHas('jobListing', function ($query) {
                $query->whereIn(
                    'pipeline_stage',
                    array_map(fn (PipelineStage $s) => $s->value, self::FAILURE_STAGES),
                );
            })
            ->with('jobListing:id,title,company,pipeline_stage')
            ->latest('updated_at')
            ->first();

        if ($application === null) {
            return null;
        }

        $log = is_array($application->automation_log) ? $application->automation_log : [];
        $reason = $log['failure_reason'] ?? $log['reason'] ?? null;

        return [
            'application_id' => (int) $application->id,
            'stage' => $application->jobListing?->pipeline_stage?->value,
            'job_title' => $application->jobListing?->title,
            'company' => $application->jobListing?->company,
            'occurred_at' => $application->updated_at?->toIso8601String(),
            'reason' => is_string($reason) && $reason !== '' ? $reason : null,
        ];
    }
}
