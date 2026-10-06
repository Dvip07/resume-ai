<?php

namespace App\Jobs;

use App\Enums\PipelineStage;
use App\Enums\TailoredDocumentStatus;
use App\Enums\TailoredDocumentType;
use App\Exceptions\LatexEngineException;
use App\Exceptions\ResumeCompilationException;
use App\Exceptions\ResumeTailoringException;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\TailoredDocument;
use App\Models\UserProfile;
use App\Services\Apply\AutoApplyDispatcher;
use App\Services\Storage\TailoredDocumentStorage;
use App\Services\Tailoring\CompiledResume;
use App\Services\Tailoring\CoverLetterRequirementDetector;
use App\Services\Tailoring\FabricationGuard;
use App\Services\Tailoring\FabricationReport;
use App\Services\Tailoring\ResumeTailoringPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The tailoring stage of the pipeline (`Discover → Enrich → Score → Tailor`,
 * design.md §4): the only place a compiled resume becomes a durable, linked
 * artefact (Requirements 6.5, 8.1, 8.2, 8.3).
 *
 * It is the counterpart of {@see ScoreJobListing} one stage on, and the division
 * of labour with its collaborators is the same shape — every one of them was
 * written to *not* know about this job's decisions, and their docblocks say so:
 *
 *  - {@see ResumeTailoringPipeline} generates, renders, and re-prompts on a
 *    compile failure. It returns a {@see CompiledResume} or throws, and
 *    "touches no database".
 *  - {@see FabricationGuard}/{@see FabricationReport} inspect and report. There
 *    is deliberately "no `pipeline_stage` write anywhere in this class", so that
 *    the guard can be run twice on the same document without side effects.
 *  - {@see TailoredDocumentStorage} builds keys and writes bytes, and raises
 *    rather than returns on failure so an unchecked `false` can never become a
 *    persisted `s3_path` pointing at nothing.
 *
 * Everything those three refuse to decide lives here: when the row exists, what
 * a fabrication finding means for the listing, which failures are the document's
 * fault and which are the host's, and what gets linked to what.
 *
 * ## Order of operations, and why the row comes first
 *
 * 1. a `tailored_documents` row is created `pending` **before** the render
 * 2. {@see ResumeTailoringPipeline::tailorAndCompile()} produces the PDF
 * 3. {@see FabricationGuard::inspectCompiled()} inspects it (Requirement 6.6)
 * 4. the PDF and the `.tex` go to S3 (Requirements 8.1, 8.2)
 * 5. the row moves to `rendered` carrying both keys, the provenance and the
 *    fabrication flags (Requirement 8.3: the path is persisted on the owning
 *    record, as a key rather than a public URL)
 * 6. the document and a pending `applications` row are linked both ways
 *    (Requirement 6.5)
 * 7. the listing moves to `tailored`, or to `needs_review`
 *
 * Step 1 is first on purpose, and {@see TailoredDocumentStatus} states the
 * intent it serves: "a crashed render leaves a record instead of nothing". A row
 * written only after a successful compile would make the most interesting
 * failures — the worker OOM-killed mid-render, the engine hanging until the
 * queue timeout — completely invisible, leaving a listing stuck at `tailoring`
 * with no evidence of an attempt. It also gives {@see TailoredDocumentStorage}
 * the id its keys hang off before the first byte is written, which is the whole
 * reason those keys never need rewriting.
 *
 * The row is created with `s3_path` at its `''` default, which the migration
 * defines as "nothing uploaded yet", and `pending` is not usable
 * ({@see TailoredDocumentStatus::isUsable()}), so nothing downstream can mistake
 * an in-flight row for a document it may attach to a submission.
 *
 * ## Two kinds of failure, and why they are handled oppositely
 *
 * | Thrown                        | Meaning                              | Document | Listing        | Queue      |
 * |-------------------------------|--------------------------------------|----------|----------------|------------|
 * | {@see ResumeTailoringException}  | the model will not produce usable content | `failed` | `needs_review` | swallowed  |
 * | {@see ResumeCompilationException}| content that will not typeset        | `failed` | `needs_review` | swallowed  |
 * | {@see LatexEngineException}      | no TeX engine on the host            | `pending`| (see below)    | propagated |
 * | {@see InvalidArgumentException}  | unknown template key                 | `pending`| (see below)    | propagated |
 * | {@see RuntimeException} (upload) | S3 write failed                      | `pending`| (see below)    | propagated |
 *
 * The first two are *this document's* problem and have already spent every
 * retry the design allows them — the response budget inside
 * {@see \App\Services\Tailoring\ResumeTailoringService}, the compile budget in
 * the pipeline, and beneath both the router's walk through every model in the
 * tier. Re-running would re-pay for all of it to fail the same way, so they are
 * caught here and converted into a review item, exactly as
 * {@see ScoreJobListing::flagForReview()} converts a spent
 * {@see \App\Exceptions\JobScoringException}.
 *
 * The last three are properties of the host or the configuration and say nothing
 * about the content, so they are left to fail the job: the queue's
 * release-and-retry is the correct response to a transient one, and a permanent
 * one belongs in `failed_jobs` where an operator will see it. Requirement 8.5 is
 * explicit about this for the upload case — retry with backoff, and do not mark
 * the step complete until storage succeeds — and letting the exception through
 * gets both for free, because the row never reaches `rendered`.
 *
 * ## Why `needs_review` and not `failed` for the content failures
 *
 * The same distinction {@see ScoreJobListing} draws, and it is worth restating
 * because both stages resolve it the same way: `failed` is what a stage sets
 * when it "gave up after its retries" for reasons nobody asked for — which in
 * that job means {@see ScoreJobListing::failed()}, the infrastructure path — and
 * `needs_review` is where a run goes when it completed, produced an answer, and
 * that answer needs a person. A spent tailoring or compile budget is the second
 * kind: the work ran to its designed conclusion and the conclusion is "a human
 * must look at this", with {@see ResumeCompilationException::failedIdentically()}
 * even telling them whether re-running is worth trying. Both stages are terminal
 * and neither auto-applies, so the guarantee that matters holds either way; the
 * difference is only whether the dashboard invites action.
 *
 * `failed` is therefore reserved for {@see self::failed()} here too, where the
 * job died rather than concluded.
 *
 * ## A fabrication finding stops the pipeline (Requirements 6.6, 9.4)
 *
 * A document that inspects dirty is still uploaded and still reaches `rendered`
 * — the PDF exists, the flags describe what is wrong with it, and a reviewer
 * needs to read the actual document to judge a finding. What changes is the
 * *listing*: it goes to `needs_review` rather than `tailored`, which is what
 * keeps it out of the auto-apply path, since nothing dispatches submission from
 * `needs_review`. The report's own docblock puts this decision here, and
 * {@see FabricationReport::requiresReview()} rather than `isClean()` is called so
 * that an advisory finding class added later does not have to be understood at
 * this call site.
 *
 * The reason is logged rather than stored, for the same reason
 * {@see ScoreJobListing} logs its own: `job_listings` has no review-reason
 * column yet. The structured findings *are* persisted, on
 * `tailored_documents.fabrication_flags`, so nothing about the finding itself is
 * lost — only the sentence form of it.
 *
 * ## Where it sits in the chain (Requirement 11.3)
 *
 * {@see ScoreJobListing::tailor()} dispatches this job for a listing that cleared
 * the user's auto-apply gate, and Requirement 9.8's manual "tailor this" trigger
 * reaches the same rows by hand. On a clean run {@see handOff()} continues the
 * chain — either {@see TailorCoverLetter} (which then submits) or
 * {@see \App\Services\Apply\AutoApplyDispatcher} directly. A flagged or failed
 * run hands on nothing, because `needs_review` is where the chain is supposed to
 * stop.
 *
 * This job still only ever writes {@see TailoredDocumentType::Resume} rows —
 * hence the hardcoded type — and the cover letter remains a separate job for a
 * separate document.
 */
class TailorResume implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Three attempts.
     *
     * Higher than {@see ScoreJobListing}'s two despite each attempt being more
     * expensive, because of what can actually reach the queue from here: a
     * content failure is swallowed, so a retry is only ever paid for an
     * environment fault, and one of those — a failed S3 write (Requirement 8.5)
     * — is both the most likely and the most likely to be transient. Two
     * retries is enough for a bucket having a bad minute without turning a
     * genuinely missing TeX engine into a long, expensive loop; that one fails
     * identically every time and belongs in `failed_jobs` quickly.
     */
    public int $tries = 3;

    /**
     * Seconds before each retry, lengthening.
     *
     * The first retry is for a blip, the second for something that needs a
     * moment (a throttled bucket, a redeploy finishing).
     */
    public array $backoff = [60, 300];

    /**
     * @param  int  $jobListingId  the listing to tailor against
     * @param  int  $userId  whose profile supplies every fact in the document, and
     *                       who owns the resulting S3 prefix. Tailored documents are
     *                       per (listing, user) pair for the same reason scores are.
     * @param  ?string  $templateKey  a template from Requirement 6.3; null takes the
     *                                configured default. Passed through untouched so
     *                                the pipeline stays the only place that resolves
     *                                it — validating it here would duplicate a rule
     *                                and let the two drift.
     */
    public function __construct(
        public readonly int $jobListingId,
        public readonly int $userId,
        public readonly ?string $templateKey = null,
    ) {}

    public function handle(
        ResumeTailoringPipeline $pipeline,
        FabricationGuard $guard,
        TailoredDocumentStorage $storage,
        CoverLetterRequirementDetector $detector,
        AutoApplyDispatcher $autoApply,
    ): void {
        $listing = JobListing::find($this->jobListingId);

        if ($listing === null) {
            // Deleted between dispatch and execution. Retrying cannot help.
            Log::notice('Skipping tailoring: the job listing no longer exists.', [
                'job_listing_id' => $this->jobListingId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        if ($this->isPastTailoring($listing)) {
            // design.md Property 7: stages move forward only. A duplicate
            // dispatch must not drag a submitted application back to
            // `tailoring`, nor spend a second full generation on a listing that
            // already has its documents.
            Log::notice('Skipping tailoring: the listing has already moved past this stage.', [
                'job_listing_id' => $listing->id,
                'user_id' => $this->userId,
                'pipeline_stage' => $listing->pipeline_stage?->value,
            ]);

            return;
        }

        $profile = UserProfile::query()->where('user_id', $this->userId)->first();

        if ($profile === null) {
            // Nothing to tailor from. Not a fault and not retryable: the profile
            // appears once a resume has been parsed (Requirement 2.2), and every
            // fact in the document comes from it.
            Log::notice('Skipping tailoring: the user has no parsed profile yet.', [
                'job_listing_id' => $listing->id,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $listing->update(['pipeline_stage' => PipelineStage::Tailoring]);

        // Before the render, so a crash leaves evidence. See the class docblock.
        $document = $this->pendingDocument($listing);

        try {
            $compiled = $pipeline->tailorAndCompile($listing, $profile, $this->templateKey);
        } catch (ResumeTailoringException|ResumeCompilationException $e) {
            $this->flagForReview($listing, $document, $e);

            return;
        }

        // Environment faults from the pipeline (LatexEngineException,
        // InvalidArgumentException) are intentionally not caught: they are the
        // host's problem, not this document's, so they fail the job instead of
        // marking a perfectly good generation as bad content.

        $report = $guard->inspectCompiled($compiled, $profile);

        $this->store($document, $compiled, $report, $storage);

        $this->link($listing, $document);

        $this->settle($listing, $document, $compiled, $report);

        if ($report->requiresReview()) {
            // A flagged document is stored but the listing is a review item, so
            // there is nothing to hand on: a cover letter for it would be spend
            // on an application that is not going out automatically.
            return;
        }

        $this->handOff($listing, $detector, $autoApply);
    }

    /**
     * The next link in the chain (Requirement 11.3):
     * `… → Tailor(resume [+ cover letter]) → Apply`.
     *
     * Exactly one of two things happens, and which one is decided here rather
     * than downstream — {@see AutoApplyDispatcher}'s docblock argues at length
     * why the ordering is structural instead of a readiness race:
     *
     *  - the posting asks for a cover letter → {@see TailorCoverLetter} is
     *    dispatched and *it* owns the apply dispatch, so no resume-only
     *    application can be submitted for a posting that needs a letter, and
     *    nothing is dispatched twice;
     *  - it does not → the auto-apply gate is consulted straight away.
     *
     * The detection runs once, here, where the description is already loaded.
     * {@see TailorCoverLetter} re-detects when it runs (its own docblock explains
     * why it cannot trust a dispatcher's verdict), so the verdict formed here is
     * an instruction to *consider* a letter, and is passed along only so
     * {@see TailorCoverLetter::failed()} can tell a blocking failure from a
     * harmless one. A detector failure never costs the chain anything: the
     * detector throws nothing and answers `not_requested` in the worst case,
     * which simply means the application goes out with a resume alone.
     *
     * `templateKey` is deliberately not forwarded: the resume template this job
     * was given says nothing about which cover-letter template to use, and the
     * letter job resolves its own default.
     */
    protected function handOff(
        JobListing $listing,
        CoverLetterRequirementDetector $detector,
        AutoApplyDispatcher $autoApply,
    ): void {
        $detection = $detector->detect($listing);

        if ($detection->wantsCoverLetter()) {
            TailorCoverLetter::dispatch(
                (int) $listing->id,
                $this->userId,
                null,
                null,
                $detection->requirement,
            );

            Log::info('Queued cover-letter tailoring before the application is submitted.', [
                'job_listing_id' => $listing->id,
                'user_id' => $this->userId,
            ] + $detection->context());

            return;
        }

        $autoApply->dispatchIfReady($listing, $this->userId, [
            'dispatched_from' => 'tailor_resume',
        ] + $detection->context());
    }

    /**
     * The `pending` row this run will fill in.
     *
     * An existing `pending` resume row for the pair is reused rather than
     * duplicated. Retries are the reason: an upload failure or a missing engine
     * fails the job *after* the row exists, and a fresh row per attempt would
     * leave a trail of three indistinguishable `pending` rows for one piece of
     * work, none of which is wrong and all of which look like separate attempts
     * to anyone reading the table. Reusing it also keeps the S3 keys stable
     * across retries, so a retry overwrites its own partial object instead of
     * orphaning it under a dead id.
     *
     * Only `pending` rows are reused. A `rendered` row is a finished document
     * that this run has no business overwriting, and a `failed` one is a record
     * of a conclusion — both are terminal
     * ({@see TailoredDocumentStatus::isTerminal()}), and a re-tailor that has got
     * this far deserves its own row so the history of the pair survives.
     *
     * `template_key` is written now rather than at `rendered` time so the row
     * says which template the in-flight render is using; it is the one column a
     * reused row may need updated.
     */
    protected function pendingDocument(JobListing $listing): TailoredDocument
    {
        $document = TailoredDocument::query()
            ->where('user_id', $this->userId)
            ->where('job_listing_id', $listing->id)
            ->ofType(TailoredDocumentType::Resume)
            ->where('status', TailoredDocumentStatus::Pending)
            ->latest('id')
            ->first();

        if ($document !== null) {
            // The pipeline resolves a null key to the configured default, and
            // this row should not claim a key the render did not use — so an
            // unspecified key leaves whatever the row already carries (its own
            // `default` column default on a first run).
            if ($this->templateKey !== null && trim($this->templateKey) !== '') {
                $document->update(['template_key' => trim($this->templateKey)]);
            }

            return $document;
        }

        $attributes = [
            'user_id' => $this->userId,
            'job_listing_id' => $listing->id,
            'type' => TailoredDocumentType::Resume,
            'status' => TailoredDocumentStatus::Pending,
        ];

        if ($this->templateKey !== null && trim($this->templateKey) !== '') {
            $attributes['template_key'] = trim($this->templateKey);
        }

        return TailoredDocument::create($attributes);
    }

    /**
     * Upload both artefacts, then promote the row to `rendered`
     * (Requirements 8.1, 8.2, 8.3).
     *
     * Upload first, update second, and never the other way round. A `rendered`
     * row is a promise that `s3_path` can be fetched
     * ({@see TailoredDocumentStatus::isUsable()}), and the auto-apply step will
     * take that promise at face value; writing the status before the bytes
     * exist would make a failed upload indistinguishable from a good document
     * for as long as nobody tried to download it. Because
     * {@see TailoredDocumentStorage} throws on failure, this ordering means a
     * storage problem leaves the row `pending` and lets the queue retry it,
     * which is precisely what Requirement 8.5 asks for.
     *
     * The `.tex` goes up from memory rather than from disk
     * ({@see TailoredDocumentStorage::uploadTexSource()}): the compiled source
     * is already held as a string, and writing it to a temp file just to hand
     * over a path would add a failure mode for nothing.
     *
     * The keys are re-derived from the storage service rather than assumed, so
     * this job never encodes the key layout — the one place that knows it stays
     * the one place that can change it (task 13.1 will absorb it).
     */
    protected function store(
        TailoredDocument $document,
        CompiledResume $compiled,
        FabricationReport $report,
        TailoredDocumentStorage $storage,
    ): void {
        $pdfKey = $storage->uploadPdf($document, $compiled->pdfPath());
        $texKey = $storage->uploadTexSource($document, $compiled->texSource());

        // recordFabricationReport() sets the attribute and returns the model
        // without saving, so the flags ride along in this one update rather than
        // costing a second write (Requirement 6.6).
        $document->recordFabricationReport($report)->update([
            's3_path' => $pdfKey,
            'tex_source_s3_path' => $texKey,
            'template_key' => $compiled->templateKey,
            'generation_model' => $compiled->tailored->modelUsed,
            'generation_tier' => $compiled->tailored->tierUsed,
            'status' => TailoredDocumentStatus::Rendered,
        ]);

        // `template_key` is written from the compiled result rather than from
        // $this->templateKey: the pipeline resolves null to the configured
        // default, and the row should record the template that actually
        // rendered, not the argument that was passed (Requirement 6.3).

        $this->discardLocalPdf($compiled);
    }

    /**
     * Link the document and a pending `applications` row to each other
     * (Requirement 6.5).
     *
     * Both directions, because both are read: `applications.tailored_resume_id`
     * is what {@see Application::tailoredResume()} follows when the auto-apply
     * step needs the PDF to attach, and `tailored_documents.application_id` is
     * what lets a document be traced back to the submission it was used for
     * without scanning the applications table.
     *
     * The application row is found-or-created rather than always created, since
     * this job can legitimately run twice for a pair (a re-tailor after a
     * fabrication flag is resolved) and a second row would double-count the
     * application. The match is on (user, listing) only — the natural key the
     * table is already indexed on — so a re-tailor re-points the same
     * application at the newer document.
     *
     * `status` is set to `pending` explicitly, overriding the column's legacy
     * `applied` default: nothing has been submitted at this point, and a row
     * claiming otherwise would corrupt the dashboard's counts. `applied_at` is
     * left to its `useCurrent()` default because the column is NOT NULL — it
     * therefore reads as "row created at" until the submission step overwrites
     * it, which is the least-wrong option available without a migration this
     * task does not own.
     */
    protected function link(JobListing $listing, TailoredDocument $document): void
    {
        $application = Application::query()->firstOrCreate(
            [
                'user_id' => $this->userId,
                'job_listing_id' => $listing->id,
            ],
            ['status' => 'pending'],
        );

        $application->update([
            TailoredDocumentType::Resume->applicationForeignKey() => $document->getKey(),
        ]);

        $document->update(['application_id' => $application->getKey()]);
    }

    /**
     * The end of a successful run: `tailored`, or `needs_review` when the guard
     * found something (Requirements 6.6, 9.4).
     *
     * @see self class docblock for why a flagged document is still stored and
     *      still `rendered`, and why only the listing's stage changes
     */
    protected function settle(
        JobListing $listing,
        TailoredDocument $document,
        CompiledResume $compiled,
        FabricationReport $report,
    ): void {
        $context = [
            'job_listing_id' => $listing->id,
            'user_id' => $this->userId,
            'tailored_document_id' => $document->getKey(),
            'application_id' => $document->application_id,
            's3_path' => $document->s3_path,
            'tex_source_s3_path' => $document->tex_source_s3_path,
        ] + $compiled->context() + $report->context();

        if ($report->requiresReview()) {
            $listing->update(['pipeline_stage' => PipelineStage::NeedsReview]);

            // The sentence form of the findings is logged rather than stored for
            // the same reason ScoreJobListing logs its review reason: there is no
            // review-reason column on `job_listings` yet. The structured findings
            // are on the document's `fabrication_flags`, so nothing is lost.
            Log::warning('Tailored a resume with unsupported claims; flagged it for manual review.', $context + [
                'reason' => $report->reviewReason(),
            ]);

            return;
        }

        $listing->update(['pipeline_stage' => PipelineStage::Tailored]);

        Log::info('Tailored and stored a resume for a job listing.', $context);
    }

    /**
     * A tailoring or compile budget was spent: close the document and send the
     * listing to review (Requirements 6.4, 6.6).
     *
     * The row is marked `failed` rather than deleted. Deleting it would destroy
     * the only record that the run happened, which is the whole reason the row is
     * created before the render; `failed` is defined for exactly this pair of
     * exceptions and states the useful part — "no PDF was stored" — while leaving
     * the timestamps and the template that was attempted in place for whoever
     * picks up the review item.
     *
     * `s3_path` stays at `''`, so {@see TailoredDocument::isUsable()} refuses it
     * on both counts.
     *
     * @see self class docblock for why the listing goes to `needs_review` and
     *      not `failed`
     */
    protected function flagForReview(
        JobListing $listing,
        TailoredDocument $document,
        ResumeTailoringException|ResumeCompilationException $e,
    ): void {
        $document->update(['status' => TailoredDocumentStatus::Failed]);

        $listing->update(['pipeline_stage' => PipelineStage::NeedsReview]);

        Log::error('Tailoring failed for a job listing; flagged it for manual review.', [
            'job_listing_id' => $listing->id,
            'user_id' => $this->userId,
            'tailored_document_id' => $document->getKey(),
            'failure' => $e instanceof ResumeCompilationException ? 'compile' : 'generation',
            // Only the compile failure can say "re-running is pointless", and it
            // is the single most useful thing a reviewer can be told.
            'failed_identically' => $e instanceof ResumeCompilationException
                ? $e->failedIdentically()
                : null,
            'reason' => $e->reviewReason(),
        ] + $e->context);
    }

    /**
     * Delete the compiled PDF from local disk now that S3 has it.
     *
     * {@see \App\Services\Latex\LatexRenderService} deliberately moves the PDF
     * *out* of its render directory before deleting that directory, so the file
     * survives for this upload and is left with no owner afterwards. Nothing else
     * cleans it up, and a worker that tailors all day would otherwise fill the
     * configured LaTeX working directory with megabytes of already-uploaded
     * resumes.
     *
     * Only after a successful upload, and never fatal. A failure to delete is
     * untidy; throwing here would fail a job whose real work — a stored, linked,
     * `rendered` document — is already complete and would send it back for a
     * retry that re-pays for the whole generation.
     */
    protected function discardLocalPdf(CompiledResume $compiled): void
    {
        $path = $compiled->pdfPath();

        if ($path === '' || ! is_file($path)) {
            return;
        }

        if (! File::delete($path)) {
            Log::notice('Could not delete the local copy of an uploaded tailored resume.', [
                'job_listing_id' => $this->jobListingId,
                'user_id' => $this->userId,
                'path' => $path,
            ]);
        }
    }

    /**
     * Has this row already moved beyond tailoring?
     *
     * `tailoring` is deliberately *not* closed: it is the stage a dispatch from
     * {@see ScoreJobListing::tailor()} arrives in, so treating it as past would
     * make this job refuse every legitimate run. `tailored` is closed because the
     * documents exist and a second generation would only cost money to produce a
     * near-identical PDF; `applying` and `applied` are closed for the reason
     * {@see ScoreJobListing::isPastScoring()} gives — the submission is out of the
     * platform's hands and cannot be un-sent.
     *
     * `needs_review`, `store_only`, `scored` and the earlier stages all stay open.
     * A reviewer resolving a fabrication flag or an operator re-triggering
     * tailoring (Requirement 9.8) is an explicit request for another attempt, and
     * a `store_only` row being tailored by hand is a user overriding their own
     * threshold — both are the point of a manual trigger rather than something to
     * guard against.
     */
    protected function isPastTailoring(JobListing $listing): bool
    {
        return in_array($listing->pipeline_stage, [
            PipelineStage::Tailored,
            PipelineStage::Applying,
            PipelineStage::Applied,
        ], true);
    }

    /**
     * Retries exhausted, or the worker was killed.
     *
     * Only environment faults get here — content failures are converted to
     * `needs_review` inside {@see handle()} — so this is the same situation
     * {@see ScoreJobListing::failed()} describes: the stage that owned the row
     * gave up, which is what `failed` means (Requirement 11.2).
     *
     * The `pending` document row left behind by the dead attempt is closed out to
     * `failed` here, and this is the only place that happens. Up to this point
     * `pending` was true — a retry was coming and would reuse the row — but once
     * the queue is finished with the job, nothing will ever render it, and a
     * `pending` row that no worker owns is a row the frontend polls forever
     * ({@see TailoredDocumentStatus::isTerminal()}). `failed` says "no PDF was
     * stored", which is exactly what happened, even though the cause was the host
     * rather than the content.
     *
     * Only `pending` rows are touched, so a document that rendered successfully
     * before a later step failed keeps its `rendered` status and its usable PDF.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('Tailoring failed permanently for a job listing.', [
            'job_listing_id' => $this->jobListingId,
            'user_id' => $this->userId,
            'template_key' => $this->templateKey,
            'reason' => $e?->getMessage(),
            'exception' => $e === null ? null : $e::class,
        ]);

        TailoredDocument::query()
            ->where('user_id', $this->userId)
            ->where('job_listing_id', $this->jobListingId)
            ->ofType(TailoredDocumentType::Resume)
            ->where('status', TailoredDocumentStatus::Pending)
            ->update(['status' => TailoredDocumentStatus::Failed->value]);

        JobListing::where('id', $this->jobListingId)
            ->whereNotIn('pipeline_stage', [
                PipelineStage::Applied->value,
                PipelineStage::Applying->value,
            ])
            ->update(['pipeline_stage' => PipelineStage::Failed->value]);
    }
}
