<?php

namespace App\Jobs;

use App\Enums\PipelineStage;
use App\Exceptions\JobScoringException;
use App\Models\JobListing;
use App\Models\JobScore;
use App\Models\UserAutomationSetting;
use App\Models\UserProfile;
use App\Services\Scoring\FitAnalysis;
use App\Services\Scoring\JobScoringService;
use App\Services\Scoring\RatingDecision;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The scoring stage of the pipeline (`Discover → Enrich → Score → Tailor`,
 * design.md §4), and the only place the two-prompt result turns into a
 * decision about a listing (Requirements 5.2, 5.3, 5.4, 5.5).
 *
 * The division of labour with {@see JobScoringService} is deliberate and the
 * service's docblock states the other half of it: the service runs prompts and
 * either returns value objects or throws {@see JobScoringException}, and knows
 * nothing about `job_scores`, `pipeline_stage` or auto-apply. Everything the
 * service refuses to decide lives here — which attempt number a score gets,
 * what a rating means for the row, and what a prompt failure means for the user.
 *
 * ## What it does with a successful score
 *
 *  1. persists one `job_scores` row: prompt 1's analysis, prompt 2's rating,
 *     rationale and recommendation, plus both raw outputs (Requirement 5.4)
 *  2. moves the row to `scored` — the stage now truthfully describes it
 *  3. branches on the user's settings:
 *
 * | Gate                                                | `pipeline_stage` |
 * |-----------------------------------------------------|------------------|
 * | rating ≥ user's threshold **and** auto-apply enabled | `tailoring`      |
 * | anything else                                        | `store_only`     |
 *
 * The gate is {@see UserAutomationSetting::allowsAutoApplyAt()}, not
 * `config('scoring.auto_apply_star_threshold')` directly: the config value is
 * the platform default that the settings row (or its unsaved defaults fallback)
 * already folds in, and it is only half the test. Requirement 5.2 gates
 * tailoring on the user's global auto-apply toggle as well, so a 5-star fit for
 * a user who never switched automation on stays `store_only`.
 *
 * Prompt 2's own `recommendedAction` is persisted but never obeyed. It is a
 * model's suggestion; the decision belongs to the star rating measured against
 * the user's bar. Storing both is what makes a disagreement visible after the
 * fact (see {@see RatingDecision::disagreesWithThreshold()}).
 *
 * ## Handing over to tailoring (Requirement 11.3)
 *
 * A row that clears the gate moves to `tailoring` and {@see self::tailor()}
 * dispatches {@see TailorResume}, which is the single place that dispatch is
 * made. A row that does not clear it is left terminal, so the gate decided here
 * is also the decision about whether anything is ever submitted automatically —
 * the apply gate re-reads the same settings and the same score before dispatching
 * {@see \App\Jobs\SubmitApplication}, so a `store_only` row that is later
 * tailored by hand (Requirement 9.8) still never auto-applies.
 *
 * ## What a prompt failure means (Requirement 5.5)
 *
 * The stricter-format retry has already happened inside the service by the time
 * a {@see JobScoringException} reaches here, and beneath that the router has
 * already tried every model in the tier (Requirement 4.5). So this is not a
 * transient failure to retry — it is a listing that could not be scored, and
 * Requirement 5.5 is explicit that it must not be silently dropped. The row
 * moves to `needs_review` with the failure reason logged.
 *
 * `needs_review` rather than `store_only`: both are terminal and neither
 * auto-applies, but they say different things to the person reading the
 * dashboard. `store_only` means "scored, and not worth applying to
 * automatically"; this row has no score at all, and the only way it gets one is
 * a human re-triggering scoring (Requirement 5.6) or fixing whatever made the
 * description unscoreable. Surfacing it as `store_only` would bury an
 * unanswered question in a list of answered ones. Nothing auto-applies from
 * `needs_review`, so the guarantee Requirement 5.5 actually cares about — no
 * automated submission on an unparsed score — holds either way.
 *
 * Note the reason is currently logged rather than stored: `job_listings` has no
 * review-reason column (see the migration in task 8.6), so the frontend's
 * `needs_review` call-to-action (task 14.3) will need one added when it is
 * built. {@see JobScoringException::reviewReason()} exists and is already
 * bounded for that column.
 */
class ScoreJobListing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One retry only. A scoring *prompt* failure never reaches the queue — the
     * service retries it and this job converts what survives into
     * `needs_review` — so a retry here can only be for an infrastructure fault
     * (DB gone, container error), and every retry re-pays for both prompts.
     */
    public int $tries = 2;

    /** Seconds before the single retry. */
    public array $backoff = [120];

    /**
     * @param int  $jobListingId row to score
     * @param int  $userId       whose profile it is scored against, and whose
     *                           automation settings gate the branch — scores are
     *                           per (listing, user) pair, since the same posting
     *                           rates differently for different candidates
     * @param bool $rescore      set only by {@see self::rescore()}: this is a
     *                           user asking for a fresh verdict, so stages that
     *                           a stray automated dispatch must not touch are
     *                           allowed. Never widened to `applying`/`applied`.
     */
    public function __construct(
        public readonly int $jobListingId,
        public readonly int $userId,
        public readonly bool $rescore = false,
    ) {}

    /**
     * The explicit re-score entry point (Requirement 5.6): a user editing their
     * profile and asking for this listing to be judged again.
     *
     * Same job, one difference — {@see self::isPastScoring()} also lets
     * `tailoring` and `tailored` rows through, because a user who wants a fresh
     * verdict on a listing whose resume is being tailored is making a
     * deliberate request, not a duplicate dispatch. `applying` and `applied`
     * stay closed on this path too: an application already at the employer
     * cannot be un-submitted, so re-scoring it could only rewrite history
     * (design.md Property 7).
     *
     * The previous score is untouched either way — {@see self::persist()}
     * inserts the next attempt.
     */
    public static function rescore(int $jobListingId, int $userId): self
    {
        return new self($jobListingId, $userId, rescore: true);
    }

    public function handle(JobScoringService $scoring): void
    {
        $listing = JobListing::find($this->jobListingId);

        if ($listing === null) {
            // Deleted between dispatch and execution. Retrying cannot help.
            Log::notice('Skipping scoring: the job listing no longer exists.', [
                'job_listing_id' => $this->jobListingId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        if ($this->isPastScoring($listing)) {
            // design.md Property 7: stages move forward only. A stray dispatch
            // must not drag a submitted application back to `scored`.
            Log::notice('Skipping scoring: the listing has already moved past this stage.', [
                'job_listing_id' => $listing->id,
                'user_id' => $this->userId,
                'pipeline_stage' => $listing->pipeline_stage?->value,
            ]);

            return;
        }

        $profile = UserProfile::query()->where('user_id', $this->userId)->first();

        if ($profile === null) {
            // Nothing to score against. Not a fault and not retryable: the
            // profile appears once a resume has been parsed (Requirement 2.2).
            Log::notice('Skipping scoring: the user has no parsed profile yet.', [
                'job_listing_id' => $listing->id,
                'user_id' => $this->userId,
            ]);

            return;
        }

        try {
            $analysis = $scoring->promptFitAnalysis($listing, $profile);
            $decision = $scoring->promptRatingDecision($listing, $analysis);
        } catch (JobScoringException $e) {
            $this->flagForReview($listing, $e);

            return;
        }

        $score = $this->persist($listing, $analysis, $decision);

        $listing->update(['pipeline_stage' => PipelineStage::Scored]);

        $this->branch($listing, $score, $decision);
    }

    /**
     * Write the audit row Requirement 5.4 asks for.
     *
     * The insert goes through {@see JobScore::createNextAttempt()}, which owns
     * the `attempt_number`, so a re-score lands alongside the previous verdict
     * instead of replacing it (Requirement 5.6, task 10.4) even if two workers
     * score the same pair at once. This job never updates or deletes a
     * `job_scores` row; the only write it makes to that table is this insert.
     *
     * Raw output from both prompts is stored verbatim, which is what lets a
     * rating be re-read — or a bad one diagnosed — without paying for the
     * models again.
     */
    protected function persist(JobListing $listing, FitAnalysis $analysis, RatingDecision $decision): JobScore
    {
        return JobScore::createNextAttempt((int) $listing->id, $this->userId, [
            'fit_analysis' => $analysis->toArray(),
            'stars' => $decision->stars,
            'rationale' => $decision->rationale,
            'recommended_action' => $decision->recommendedAction,
            'raw_prompt_1_output' => $analysis->rawOutput,
            'raw_prompt_2_output' => $decision->rawOutput,
        ]);
    }

    /**
     * The Requirement 5.2/5.3 branch: tailor, or keep and stop.
     *
     * Both halves of the gate are the user's, read through their automation
     * settings — the rating clears *their* threshold and they have automation
     * switched on. A user with no settings row gets the platform defaults,
     * which have auto-apply off, so the conservative branch is the one taken by
     * default.
     */
    protected function branch(JobListing $listing, JobScore $score, RatingDecision $decision): void
    {
        $settings = UserAutomationSetting::forUser($this->userId);

        $context = [
            'job_listing_id' => $listing->id,
            'user_id' => $this->userId,
            'job_score_id' => $score->id,
            'attempt_number' => $score->attempt_number,
            'auto_apply_enabled' => $settings->auto_apply_enabled,
            'star_threshold' => $settings->auto_apply_star_threshold,
        ] + $decision->context();

        if ($settings->allowsAutoApplyAt($decision->stars)) {
            $this->tailor($listing, $score, $context);

            return;
        }

        $listing->update(['pipeline_stage' => PipelineStage::StoreOnly]);

        Log::info('Scored a job listing; keeping it for manual review.', $context + [
            'reason' => $settings->auto_apply_enabled
                ? 'rating below the user\'s auto-apply threshold'
                : 'auto-apply is disabled for this user',
        ]);
    }

    /**
     * Route a strong match into the tailoring pipeline (Requirement 11.3).
     *
     * The stage is moved *before* the dispatch so a worker that picks the
     * tailoring job up immediately finds the row already claimed; `tailoring` is
     * deliberately open to {@see TailorResume} ({@see TailorResume::isPastTailoring()})
     * precisely so that ordering is safe.
     *
     * No score id is passed. {@see TailorResume} tailors against the *profile*
     * and the description, and the verdict it would do nothing with is re-read
     * from `job_scores` by the apply gate
     * ({@see \App\Services\Apply\AutoApplyDispatcher}) when the documents are
     * ready — one fewer argument to keep in step with a versioned table whose
     * latest attempt is the one that counts.
     *
     * From here the chain continues inside tailoring: a clean resume either
     * queues a cover letter or goes straight to the apply gate. A flagged or
     * failed document stops at `needs_review`.
     *
     * @param array<string, mixed> $context
     */
    protected function tailor(JobListing $listing, JobScore $score, array $context): void
    {
        $listing->update(['pipeline_stage' => PipelineStage::Tailoring]);

        TailorResume::dispatch((int) $listing->id, $this->userId);

        Log::info('Scored a job listing above the auto-apply threshold; queued for tailoring.', $context);
    }

    /**
     * Requirement 5.5's fallback: the prompts could not be scored even after
     * the stricter-format retry, so the listing is flagged for a human instead
     * of being dropped.
     *
     * @see self class docblock for why this is `needs_review` and not `store_only`
     */
    protected function flagForReview(JobListing $listing, JobScoringException $e): void
    {
        $listing->update(['pipeline_stage' => PipelineStage::NeedsReview]);

        Log::error('Scoring failed for a job listing; flagged it for manual review.', [
            'job_listing_id' => $listing->id,
            'user_id' => $this->userId,
            'prompt' => $e->prompt,
            'parse_failure' => $e->failedOnParsing(),
            'reason' => $e->reviewReason(),
        ] + $e->context);
    }

    /**
     * Has this row already moved beyond scoring?
     *
     * Only the stages where later work is under way or done. `scored`,
     * `store_only` and `needs_review` are all re-scoreable on either path: a
     * user editing their profile and re-triggering scoring is exactly
     * Requirement 5.6, and the new score is versioned rather than overwritten.
     *
     * On the {@see self::rescore()} path `tailoring` and `tailored` open up as
     * well — the request is explicit, and the tailored document was built
     * against a verdict the user is now disputing. `applying` and `applied`
     * never open: the submission is out of the platform's hands.
     */
    protected function isPastScoring(JobListing $listing): bool
    {
        $closed = [
            PipelineStage::Applying,
            PipelineStage::Applied,
        ];

        if (! $this->rescore) {
            $closed[] = PipelineStage::Tailoring;
            $closed[] = PipelineStage::Tailored;
        }

        return in_array($listing->pipeline_stage, $closed, true);
    }

    /**
     * Retries exhausted, or the worker was killed — an infrastructure failure,
     * since prompt failures never get here. The stage that owned the row gave
     * up, which is what `failed` means (Requirement 11.2).
     */
    public function failed(?Throwable $e): void
    {
        Log::error('Scoring failed permanently for a job listing.', [
            'job_listing_id' => $this->jobListingId,
            'user_id' => $this->userId,
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
