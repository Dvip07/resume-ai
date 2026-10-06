<?php

namespace App\Jobs;

use App\Enums\PipelineStage;
use App\Enums\TailoredDocumentType;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\TailoredDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Apply\ApplyAdapter;
use App\Services\Apply\ApplyAdapterRegistry;
use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyDailyLimiter;
use App\Services\Apply\ApplyResult;
use App\Services\Apply\OptInGatedApplyAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The apply stage of the pipeline (`… → Tailor → Apply`, design.md §4 and §7):
 * the one place an {@see ApplyAdapter} is chosen, run, and its outcome written
 * to the `applications` row and the listing's `pipeline_stage`
 * (Requirements 9.1, 9.5).
 *
 * The division of labour mirrors {@see TailorResume}'s. The adapters own all the
 * ATS policy — selectors, field mapping, the judgement about whether a run
 * confirmed, failed or needs a person — and {@see ApplyContext} is read-only so
 * they cannot touch pipeline state. {@see ApplyAdapterRegistry} owns adapter
 * precedence. Everything those refuse to decide lives here: whether this listing
 * may be applied to at all, what an outcome means for the row, and what the
 * audit trail looks like.
 *
 * ## Order of operations
 *
 * 1. the listing, user, profile and tailored documents are loaded; a missing
 *    prerequisite ends the run before any browser work
 * 2. the listing moves to `applying`, so a crash mid-run is visible as a stage
 *    rather than as silence
 * 3. the registry picks the first adapter supporting `application_url`
 * 4. the day's apply budget is reserved for (user, adapter), or the run stops
 *    here re-attemptable (see below)
 * 5. the adapter runs, or — with no adapter — a `needs_review` result is
 *    synthesised without a single worker call
 * 6. the outcome is persisted: `applications.status`, `apply_adapter_used`,
 *    `automation_log`, `applied_at`, and the listing's terminal stage
 *
 * ## Being capped is a pause, not an outcome
 *
 * {@see ApplyDailyLimiter} is consulted after the adapter is resolved — the
 * per-platform cap is keyed by adapter, so the question cannot be asked any
 * earlier — and before {@see runAdapter()}, so a capped user drives nothing
 * (Requirement 9.7).
 *
 * The three terminal statuses are all wrong for it. `failed` and `needs_review`
 * both say *this posting* is a problem, and neither comes back on its own; the
 * truth is that the posting is fine and tomorrow's budget will apply to it. So
 * the capped run is the one path that leaves no terminal state behind:
 * `applications.status` stays `pending` and the listing is put back to
 * `tailored`, which is where it was when this job picked it up. Re-dispatching
 * it tomorrow is then an ordinary apply run.
 *
 * Nor is a queue retry spent on it. `release()` would re-run within the hour,
 * against a budget that only resets at midnight, and would burn the single
 * retry that exists to cover a flaky worker. The job simply returns.
 *
 * Returning silently would be a dead end though — a listing that sat at
 * `tailored` with nothing to show for it — so the attempt is recorded on the
 * application row's `automation_log` with `status` `capped`, the cap that
 * refused it, and the remaining budget, which is what the dashboard needs to
 * say "you have reached today's limit" rather than showing a blank.
 *
 * Reverting `applying` → `tailored` is the only backward stage move in the
 * pipeline (design.md Property 7). It is not a regression: `applying` was
 * written by *this* run moments earlier, and undoing it restores the state the
 * job found rather than losing progress.
 *
 * ## No adapter is a first-class outcome, not an error
 *
 * Most postings live on a company's own careers page with no vendor
 * fingerprint. Those get `needs_review` with an explicit "apply manually" note
 * and the posting link, which is what the dashboard's call to action reads
 * ({@see \App\Support\ApplicationPresenter}). The worker is never called, so an
 * unsupported ATS costs nothing, and the run is not retried — a retry cannot
 * conjure an adapter.
 *
 * ## An adapter may not crash the queue worker
 *
 * {@see ApplyAdapter} forbids throwing to report a failed application, but a
 * posting can still find a null dereference or a malformed URL deep in an
 * adapter. Any escaping {@see Throwable} is therefore converted to a `failed`
 * result here — the conversion {@see ApplyResult} promises — so one stubborn
 * posting degrades to a review item instead of poisoning the queue. `failed` and
 * not `needs_review`, because an unexpected exception says nothing about the
 * posting needing a human; it is the retryable kind of broken.
 *
 * ## Stage mapping
 *
 * | ApplyResult    | listing stage  | applications.status | applied_at |
 * |----------------|----------------|---------------------|------------|
 * | `applied`      | `applied`      | `applied`           | now        |
 * | `failed`       | `failed`       | `failed`            | untouched  |
 * | `needs_review` | `needs_review` | `needs_review`      | untouched  |
 *
 * `applied_at` is only written on a confirmed submission. The column is NOT
 * NULL with a `useCurrent()` default, so until then it reads as "row created
 * at" exactly as {@see TailorResume::link()} describes — writing it on a failure
 * would turn that into a false claim that something was submitted.
 *
 * ## A gated adapter without consent is a hand-off, not an attempt
 *
 * {@see OptInGatedApplyAdapter} adapters — today only LinkedIn Easy Apply
 * (Requirement 9.3) — are resolved with the user id, so one the user has not
 * opted in to is never returned at all. When such an adapter *would* have
 * claimed the posting, {@see settleOptInBlocked()} records `needs_review`
 * naming the setting and telling the user to apply manually. The worker is not
 * called and the daily budget is not spent: the gate is checked before the cap
 * because consent is not a resource.
 *
 * ## Who dispatches it
 *
 * {@see \App\Services\Apply\AutoApplyDispatcher}, called by the tailoring jobs
 * once the documents for a posting are stored (Requirement 11.3) — and only for
 * a listing that cleared the user's auto-apply gate, which is why this job does
 * not re-ask that question. It can also be dispatched by hand, and every
 * precondition it actually depends on (the stage, the profile, a usable resume)
 * is re-checked here, so a hand dispatch is as safe as a chained one.
 */
class SubmitApplication implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Two attempts.
     *
     * Lower than tailoring's three, and the reason is the risk being managed: a
     * retried apply run can mean a *duplicate application*, which is worse for
     * the user than a review item. One retry covers the worker having a bad
     * minute; beyond that a person should look. Only `failed` outcomes are
     * retryable at all ({@see ApplyResult::isRetryable()}) and a wall or an
     * unanswered question is terminal by construction.
     */
    public int $tries = 2;

    /**
     * `automation_log.status` for a run stopped by the daily cap. Deliberately
     * not an {@see ApplyResult} status: the three of those are terminal and
     * land on `applications.status`, whereas this one is a note on a row that
     * stays `pending` (Requirement 9.7).
     */
    public const STATUS_CAPPED = 'capped';

    /** Seconds before the single retry: long enough for a worker redeploy. */
    public array $backoff = [120];

    /**
     * @param  int  $jobListingId  the posting to apply to
     * @param  int  $userId  whose profile answers every field, and who owns the
     *                       documents being attached
     * @param  array<string, string>  $answers  pre-resolved screening answers,
     *                                          passed straight through to {@see ApplyContext}. The
     *                                          re-submit path after a user answers a paused
     *                                          question (Requirement 9.4) is what this is for;
     *                                          adapters still fall back to the profile and to
     *                                          their configured defaults.
     */
    public function __construct(
        public readonly int $jobListingId,
        public readonly int $userId,
        public readonly array $answers = [],
    ) {}

    public function handle(ApplyAdapterRegistry $registry, ApplyDailyLimiter $limiter): void
    {
        $listing = JobListing::find($this->jobListingId);

        if ($listing === null) {
            // Deleted between dispatch and execution. Retrying cannot help.
            Log::notice('Skipping application submission: the job listing no longer exists.', $this->context());

            return;
        }

        if ($listing->pipeline_stage === PipelineStage::Applied) {
            // design.md Property 7: stages move forward only, and this one is
            // the stage where going backwards costs a duplicate application.
            Log::notice('Skipping application submission: the listing has already been applied to.', $this->context([
                'pipeline_stage' => $listing->pipeline_stage?->value,
            ]));

            return;
        }

        $user = User::find($this->userId);
        $profile = UserProfile::query()->where('user_id', $this->userId)->first();

        if ($user === null || $profile === null) {
            // Every answer an adapter fills in comes from the profile, which
            // appears once a resume has been parsed (Requirement 2.2). Not a
            // fault, and not retryable.
            Log::notice('Skipping application submission: the user has no parsed profile yet.', $this->context());

            return;
        }

        $resume = $this->document($listing, TailoredDocumentType::Resume);
        $coverLetter = $this->document($listing, TailoredDocumentType::CoverLetter);

        $listing->update(['pipeline_stage' => PipelineStage::Applying]);

        if ($resume === null) {
            // The resume is the submission. Tailoring either has not run or did
            // not produce something usable, so there is nothing to attach and
            // the right move is a review item rather than a blank application.
            $this->settle($listing, null, ApplyResult::needsReview(
                'No tailored resume is ready for this posting, so there is nothing to submit. Re-run tailoring, or '
                .'apply manually using the posting link.'
            ));

            return;
        }

        $context = new ApplyContext(
            job: $listing,
            user: $user,
            profile: $profile,
            tailoredResumePath: (string) $resume->s3_path,
            coverLetterPath: $coverLetter === null ? null : (string) $coverLetter->s3_path,
            answers: $this->stringAnswers(),
        );

        $url = $context->applicationUrl();

        // The user id is part of adapter resolution, not just of the run: an
        // opt-in-gated adapter is only offered to a user who has consented to
        // it (Requirement 9.3).
        $adapter = $registry->resolve($url, $this->userId);

        if ($adapter === null) {
            $gated = $registry->gatedMatchFor($url, $this->userId);

            if ($gated !== null) {
                // A gated adapter would have claimed this posting but may not
                // run. Said explicitly, because "no automation supports this
                // site" would be a lie — and the worker is never called.
                $this->settleOptInBlocked($listing, $registry->keyFor($gated), $gated);

                return;
            }

            // See the class docblock: no adapter is an outcome, not an error.
            $this->settle($listing, null, ApplyResult::needsReview($this->manualApplyReason($url)));

            return;
        }

        $adapterKey = (string) $registry->keyFor($adapter);

        // Requirement 9.7. After resolution so the platform cap has a key to
        // look up; before apply() so a capped user drives nothing.
        if (! $limiter->attempt($adapterKey, $this->userId)) {
            $this->settleCapped($listing, $adapterKey, $limiter);

            return;
        }

        $this->settle($listing, $adapterKey, $this->runAdapter($adapter, $context));
    }

    /**
     * Record a capped attempt and put the listing back where this run found it.
     *
     * See the class docblock: no terminal status fits, so none is written. The
     * row keeps `pending`, the listing returns to `tailored`, and the log entry
     * is what makes the pause visible.
     */
    protected function settleCapped(JobListing $listing, string $adapterKey, ApplyDailyLimiter $limiter): void
    {
        $application = Application::query()->firstOrCreate(
            [
                'user_id' => $this->userId,
                'job_listing_id' => $listing->getKey(),
            ],
            ['status' => 'pending'],
        );

        $application->update([
            'apply_adapter_used' => $adapterKey,
            'automation_log' => [
                'adapter' => $adapterKey,
                'status' => self::STATUS_CAPPED,
                'application_url' => $listing->application_url,
                'attempted_at' => now()->toIso8601String(),
                'failure_reason' => 'Today\'s application limit has been reached, so this posting was not submitted. '
                    .'It will be attempted again tomorrow, or you can apply manually using the posting link.',
                'unanswered_questions' => [],
                'screenshots' => [],
                'confirmation_url' => null,
                'steps' => [],
                'metadata' => [
                    'capped' => true,
                    'global_used_today' => $limiter->usedToday(null, $this->userId),
                    'global_cap' => $limiter->globalLimitFor($adapterKey, $this->userId),
                    'platform_used_today' => $limiter->usedToday($adapterKey, $this->userId),
                    'platform_cap' => $limiter->platformLimitFor($adapterKey),
                    'remaining_today' => $limiter->remaining($adapterKey, $this->userId),
                ],
            ],
        ]);

        $listing->update(['pipeline_stage' => PipelineStage::Tailored]);

        Log::warning('Skipped an application: the user is at their daily apply limit.', $this->context([
            'application_id' => $application->getKey(),
            'adapter' => $adapterKey,
            'status' => self::STATUS_CAPPED,
            'pipeline_stage' => PipelineStage::Tailored->value,
            'remaining_today' => $limiter->remaining($adapterKey, $this->userId),
        ]));
    }

    /**
     * A gated adapter matched the posting but the user has not opted in to it
     * (Requirement 9.3).
     *
     * `needs_review` rather than `failed`: nothing is broken and a retry cannot
     * change a consent setting. The adapter key is still recorded, because
     * "LinkedIn, blocked by your settings" is a far more useful row than an
     * anonymous review item — and the note names the setting so the dashboard
     * can point at it. Nothing was driven, so the daily budget is not touched.
     */
    protected function settleOptInBlocked(
        JobListing $listing,
        string $adapterKey,
        OptInGatedApplyAdapter $adapter,
    ): void {
        Log::warning('Skipped an application: the user has not opted in to this apply automation.', $this->context([
            'adapter' => $adapterKey,
            'opt_in_setting' => $adapter->optInSettingName(),
        ]));

        $this->settle($listing, $adapterKey, ApplyResult::needsReview(
            $adapter->optInRequiredReason(),
            [],
            [],
            null,
            [
                'adapter' => $adapterKey,
                'opt_in_setting' => $adapter->optInSettingName(),
                'opt_in' => false,
                // Stated in the audit trail, not merely implied by the absence
                // of screenshots: no browser was opened for this posting.
                'worker_called' => false,
            ]
        ));
    }

    /**
     * Run the adapter, converting anything that escapes it into a `failed`
     * result. See the class docblock for why that conversion lives here.
     */
    protected function runAdapter(ApplyAdapter $adapter, ApplyContext $context): ApplyResult
    {
        try {
            return $adapter->apply($context);
        } catch (Throwable $e) {
            Log::error('An apply adapter threw; converted it into a failed application attempt.', $this->context([
                'adapter' => $adapter::class,
                'exception' => $e::class,
                'reason' => $e->getMessage(),
            ]));

            return ApplyResult::failed(
                'The apply automation stopped unexpectedly: '.$e->getMessage()
            );
        }
    }

    /**
     * The newest usable document of `$type` for this (listing, user) pair, or
     * null when there is none.
     *
     * `rendered` alone is not enough — {@see TailoredDocument::isUsable()} also
     * insists on a non-empty `s3_path`, and a row whose upload failed is
     * exactly the one that must not be attached to a submission.
     */
    protected function document(JobListing $listing, TailoredDocumentType $type): ?TailoredDocument
    {
        $document = TailoredDocument::query()
            ->where('user_id', $this->userId)
            ->where('job_listing_id', $listing->getKey())
            ->ofType($type)
            ->rendered()
            ->latest('id')
            ->first();

        return $document !== null && $document->isUsable() ? $document : null;
    }

    /**
     * Persist the outcome: the `applications` row, its audit trail, and the
     * listing's terminal stage (Requirement 9.5).
     *
     * The application row is found-or-created on (user, listing) — the natural
     * key the table is indexed on and the same match {@see TailorResume::link()}
     * makes — so a re-submission updates the row tailoring already created
     * rather than double-counting the application. The document links are
     * written here too, because this job may run for a pair whose documents were
     * produced before `applications` existed.
     */
    protected function settle(JobListing $listing, ?string $adapterKey, ApplyResult $result): void
    {
        $application = Application::query()->firstOrCreate(
            [
                'user_id' => $this->userId,
                'job_listing_id' => $listing->getKey(),
            ],
            ['status' => 'pending'],
        );

        $attributes = [
            'status' => $result->status,
            'apply_adapter_used' => $adapterKey,
            'automation_log' => $this->automationLog($listing, $adapterKey, $result),
        ];

        if ($result->isApplied()) {
            $attributes['applied_at'] = now();
        }

        $application->update($attributes);

        $listing->update(['pipeline_stage' => $this->stageFor($result)]);

        $context = $this->context([
            'application_id' => $application->getKey(),
            'adapter' => $adapterKey,
            'status' => $result->status,
            'pipeline_stage' => $this->stageFor($result)->value,
            'screenshots' => count($result->screenshotPaths),
            'reason' => $result->failureReason,
        ]);

        match (true) {
            $result->isApplied() => Log::info('Submitted an application.', $context),
            $result->needsHumanReview() => Log::warning('An application needs a person to finish it.', $context),
            default => Log::error('An application attempt failed.', $context),
        };
    }

    /**
     * The evidence trail for one attempt (Requirement 9.5).
     *
     * Keyed for {@see \App\Support\ApplicationPresenter}, which reads
     * `unanswered_questions`, `failure_reason` and `metadata.blocked` to build a
     * review item's call to action — so those three keep their names and their
     * place at the top of the log. `screenshots` holds S3 keys rather than
     * bytes, `steps` is lifted out of the adapter metadata because the step log
     * is the first thing anyone debugging selector rot wants, and the full
     * adapter metadata is kept nested so an adapter can add detail without this
     * job knowing about it.
     *
     * @return array<string, mixed>
     */
    protected function automationLog(JobListing $listing, ?string $adapterKey, ApplyResult $result): array
    {
        $metadata = $result->metadata;

        return [
            'adapter' => $adapterKey,
            'status' => $result->status,
            'application_url' => $listing->application_url,
            'attempted_at' => now()->toIso8601String(),
            'failure_reason' => $result->failureReason,
            'unanswered_questions' => $result->unansweredQuestions,
            'screenshots' => $result->screenshotPaths,
            'confirmation_url' => $result->confirmationUrl,
            'steps' => is_array($metadata['steps'] ?? null) ? $metadata['steps'] : [],
            'metadata' => $metadata,
        ];
    }

    protected function stageFor(ApplyResult $result): PipelineStage
    {
        return match ($result->status) {
            ApplyResult::STATUS_APPLIED => PipelineStage::Applied,
            ApplyResult::STATUS_NEEDS_REVIEW => PipelineStage::NeedsReview,
            default => PipelineStage::Failed,
        };
    }

    /**
     * The "apply manually" note for a URL no adapter claims. Names the reason
     * rather than implying something broke, and says what to do next; the link
     * itself is already on the dashboard row.
     */
    protected function manualApplyReason(string $url): string
    {
        if ($url === '') {
            return 'This posting carries no application link, so it has to be applied to manually.';
        }

        return 'No apply automation supports this posting\'s application site, so it has to be applied to manually '
            .'using the posting link. Your tailored documents are ready to download and attach.';
    }

    /**
     * Answers arrive from a dispatcher (and therefore, ultimately, from a
     * request), so they are narrowed to non-empty strings before an adapter
     * types any of them into a form.
     *
     * @return array<string, string>
     */
    protected function stringAnswers(): array
    {
        $answers = [];

        foreach ($this->answers as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $trimmed = trim((string) $value);

            if ($trimmed !== '') {
                $answers[$key] = $trimmed;
            }
        }

        return $answers;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function context(array $extra = []): array
    {
        return [
            'job_listing_id' => $this->jobListingId,
            'user_id' => $this->userId,
        ] + $extra;
    }
}
