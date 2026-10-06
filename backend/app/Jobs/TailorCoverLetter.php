<?php

namespace App\Jobs;

use App\Enums\CoverLetterRequirement;
use App\Enums\PipelineStage;
use App\Enums\TailoredDocumentStatus;
use App\Enums\TailoredDocumentType;
use App\Exceptions\CoverLetterTailoringException;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\TailoredDocument;
use App\Models\UserProfile;
use App\Services\Apply\AutoApplyDispatcher;
use App\Services\Latex\LatexRenderService;
use App\Services\Latex\RenderedDocument;
use App\Services\Storage\TailoredDocumentStorage;
use App\Services\Tailoring\CoverLetterRecipient;
use App\Services\Tailoring\CoverLetterRequirementDetector;
use App\Services\Tailoring\CoverLetterTailoringService;
use App\Services\Tailoring\TailoredCoverLetterContent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The cover-letter half of the tailoring stage (Requirements 7.1, 7.3, 7.4):
 * generate a letter for a posting that asks for one, render it, store both
 * artefacts in S3, and link them to the same `applications` row the tailored
 * resume hangs off.
 *
 * The sibling of {@see TailorResume} and written to read as one — same
 * tries/backoff, same "pending row before the render" rule, same upload-then-
 * promote ordering, same content-failure-versus-environment-fault split. Read
 * that class first; this docblock covers only where the cover letter genuinely
 * differs, and every difference below exists because a cover letter is
 * *conditional, companion* work while a resume is the application itself.
 *
 * ## The gate is re-checked here, not trusted from the dispatcher
 *
 * Requirement 7.2 is a cost control, and it is only real if the object that
 * spends the money enforces it. So {@see CoverLetterRequirementDetector} is run
 * again inside {@see handle()} and a posting whose verdict does not
 * {@see \App\Services\Tailoring\CoverLetterDetection::wantsCoverLetter()} is a
 * logged no-op.
 *
 * That is not defensive habit. A queued job is separated from its dispatcher by
 * time: a listing can be re-enriched between dispatch and execution (so the
 * verdict the dispatcher formed was computed against a description that no
 * longer exists), a retry can re-run this job hours later, and a hand-dispatched
 * or swept job (see "Not wired up yet" below) has no dispatcher verdict at all.
 * A job that generated unconditionally would make task 12.2's detector
 * decorative — the gate would live only in the caller, where a single missing
 * `if` silently reintroduces exactly the model spend Requirement 7.2 exists to
 * avoid. Re-detection is cheap by construction: the detector answers most
 * postings from keywords at zero cost and only escalates the ambiguous middle,
 * which is far cheaper than the letter this job would otherwise write.
 *
 * {@see $requirement} is still accepted from the dispatcher, but only for the
 * one question re-detection cannot answer later — see {@see failed()}.
 *
 * ## A failed letter must not hold back an optional application
 *
 * This is the substance of Requirement 7.4's "same retry/fallback behavior", and
 * "same" cannot mean identical here: {@see TailorResume} answers a spent budget
 * with `needs_review` because without a resume there is nothing to submit. A
 * cover letter splits in two, on {@see CoverLetterRequirement::isMandatory()},
 * which exists for precisely this branch:
 *
 * | Detected requirement | Letter fails                                  |
 * |----------------------|-----------------------------------------------|
 * | `Required`           | listing → `needs_review`; the application is   |
 * |                      | incomplete and must not be auto-applied       |
 * | `Optional`           | listing untouched; the resume goes out alone   |
 *
 * The document row is marked `failed` in both cases — the record of the attempt
 * is worth the same either way — and the difference is only whether the listing
 * is pulled out of the auto-apply path. Sending an optional letter's failure to
 * `needs_review` would convert a nice-to-have into a blocker and put a human in
 * front of applications that are perfectly submittable, which is the exact
 * inversion {@see CoverLetterRequirement}'s docblock warns about.
 *
 * ## This job never advances the pipeline stage
 *
 * The one structural difference from {@see TailorResume}, and it is deliberate.
 * `job_listings.pipeline_stage` is a single column describing the *application's*
 * progress, and {@see TailorResume} owns the `tailoring → tailored` transition on
 * it. If this job also wrote the stage, two queued jobs racing over one column
 * would let a fast cover letter declare a listing `tailored` before its resume
 * exists — a state the auto-apply step reads as "documents are ready".
 *
 * So the only stage write here is the one *downgrade* described above
 * (`needs_review` for a failed mandatory letter), which is safe to apply from
 * either side because it removes a listing from the auto-apply path rather than
 * admitting it. Success is recorded on the document row and in the log, and
 * `tailored` remains the resume job's word.
 *
 * ## Retry and fallback, and why the loop is inline
 *
 * Requirement 7.4 points at Requirement 6.4, whose loop lives in
 * {@see \App\Services\Tailoring\ResumeTailoringPipeline}: generate, render, and
 * on a compile failure feed the engine's complaint back to the model as a
 * corrective prompt, bounded by `pipeline.latex_retry_max`. Three ways to get
 * that behaviour here, and the choice is worth recording:
 *
 *  1. **Reuse that pipeline.** Impossible without lying about types: it is bound
 *     to {@see \App\Services\Tailoring\ResumeTailoringService},
 *     {@see \App\Services\Tailoring\TailoredResumeContent} and `renderResume()`
 *     end to end, and returns a {@see \App\Services\Tailoring\CompiledResume}.
 *  2. **Generalise it.** The right eventual answer is a
 *     `CoverLetterTailoringPipeline` *sibling* — a new class, not a change to
 *     that one, whose public contract stays exactly as it is. It is a new file,
 *     and this slice is one file; it is also not yet worth its keep, because of
 *     what item 3 discovered.
 *  3. **Inline, here.** What this class does, in {@see generateAndCompile()}:
 *     the same `latex_retry_max + 1` render budget, the same environment-fault
 *     passthrough, the same "errors identical every time" signal.
 *
 * The honest limitation, stated rather than discovered later: this loop
 * *re-generates*, it does not *correct*.
 * {@see \App\Services\Tailoring\CoverLetterTailoringService} exposes no
 * `tailorWithCorrection()` seam (its resume counterpart does), so there is no
 * supported way from here to carry the engine's complaint into the next prompt.
 * A fresh generation is still a real instrument — the failures a retry can fix
 * are overlong prose, an unbreakable 90-character token pasted out of a job
 * description, or a glyph the font lacks, and non-deterministic sampling
 * genuinely produces different prose — but it is a blunter one, and adding that
 * seam plus the sibling pipeline is the follow-up this comment is here to
 * justify. Until then the budget is shared with resumes on purpose: one
 * `pipeline.latex_retry_max` is what "the same retry behaviour" means, and a
 * second knob would let the two drift with nothing to gain.
 *
 * A compile failure that exhausts the budget is *not* raised as an exception.
 * {@see \App\Exceptions\ResumeCompilationException} is the wrong type — its
 * {@see \App\Exceptions\ResumeCompilationException::reviewReason()} is
 * user-visible and says "resume" — and this job is the terminal handler for the
 * failure anyway, with no caller above it to catch a new one. So the loop
 * returns `null` and {@see handle()} applies the mandatory/optional fallback
 * directly. If a second caller ever needs to distinguish these, a
 * `CoverLetterCompilationException` sibling is the file to add.
 *
 * ## Two kinds of failure, exactly as in {@see TailorResume}
 *
 * | Thrown                              | Meaning                             | Document | Listing            | Queue      |
 * |-------------------------------------|-------------------------------------|----------|--------------------|------------|
 * | {@see CoverLetterTailoringException}| the model will not write usable prose| `failed` | see the table above| swallowed  |
 * | compile budget exhausted (no throw) | prose that will not typeset         | `failed` | see the table above| swallowed  |
 * | {@see \App\Exceptions\LatexEngineException} | no TeX engine on the host   | `pending`| untouched          | propagated |
 * | {@see InvalidArgumentException}     | unknown cover-letter template key    | `pending`| untouched          | propagated |
 * | {@see RuntimeException} (upload)    | S3 write failed                     | `pending`| untouched          | propagated |
 *
 * The reasoning is {@see TailorResume}'s, unchanged: the first two have already
 * spent every retry the design allows and say something about *this document*,
 * so they are converted into a recorded outcome; the last three are properties of
 * the host and are left to fail the job, which is what gets Requirement 8.5's
 * retry-with-backoff for a failed upload for free, because the row never reaches
 * `rendered`.
 *
 * ## Deferred, and named so nothing here pretends otherwise
 *
 *  - **`applications.tailored_cover_letter_id` was added later, by task 14.1.**
 *    {@see link()} always writes `tailored_documents.application_id` and writes
 *    the reverse FK only when {@see Schema::hasColumn()} says the column is
 *    there, logging a notice when it is not. With 14.1 applied both directions
 *    are written and the guard is a no-op; it stays because a database that has
 *    not yet run that migration must still store the letter rather than crash
 *    the queue. Requirement 7.3's "link it to the same `applications` row as the
 *    tailored resume" is satisfied against the same found-or-created row
 *    {@see TailorResume::link()} uses.
 *  - **No fabrication guard.** {@see \App\Services\Tailoring\FabricationGuard} is
 *    typed to {@see \App\Services\Tailoring\TailoredResumeContent}; the
 *    cover-letter inspection is task 11.5's remaining half, and
 *    {@see TailoredCoverLetterContent::$facts} exists to feed it. Until then
 *    `fabrication_flags` stays null on these rows, which reads as "not
 *    inspected" rather than "inspected clean" — worth knowing before anyone
 *    treats an unflagged letter as vetted.
 *
 * ## The chain passes through here (Requirement 11.3)
 *
 * {@see TailorResume::handOff()} dispatches this job when its detection says the
 * posting wants a letter, and hands the apply dispatch over with it: for such a
 * posting, {@see SubmitApplication} is queued by {@see handOff()} below and
 * nowhere else. That is what makes "never submit a resume-only application for a
 * posting that needs a letter, and never submit twice" a property of the
 * structure rather than of a check — {@see AutoApplyDispatcher} records the
 * alternative that was rejected.
 *
 * The obligation that creates is worth stating plainly: *every* terminal path
 * through {@see handle()} calls {@see handOff()}, including the ones that produce
 * no letter at all (a re-detection that disagrees, a letter already rendered, an
 * optional letter that failed). A path that returned without it would leave a
 * tailored resume with nobody left to submit it. Whether submitting is actually
 * appropriate is not decided here; the gate re-reads the listing's stage and the
 * user's settings, so a failed mandatory letter and a hand-tailored `store_only`
 * job are both refused in one place.
 *
 * It can still be dispatched by hand or by a sweep, and — because of the gate
 * above — a sweep does not need to know which postings want a letter.
 */
class TailorCoverLetter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Three attempts, matching {@see TailorResume::$tries}.
     *
     * The argument carries over unchanged: content failures are swallowed, so a
     * retry is only ever paid for an environment fault, and the most likely of
     * those — a failed S3 write (Requirement 8.5) — is also the most likely to
     * clear on its own.
     */
    public int $tries = 3;

    /** Seconds before each retry, lengthening. See {@see TailorResume::$backoff}. */
    public array $backoff = [60, 300];

    /**
     * @param  int  $jobListingId  the listing to write a letter for
     * @param  int  $userId  whose profile supplies every fact in the letter, and who
     *                       owns the resulting S3 prefix
     * @param  ?string  $templateKey  a cover-letter template key; null takes the
     *                                configured default. Passed through untouched so
     *                                {@see \App\Services\Tailoring\CoverLetterTailoringService}
     *                                stays the only place that resolves it.
     * @param  ?CoverLetterRecipient  $recipient  a hiring contact the *caller* actually
     *                                            knows. Never derived from a model, and
     *                                            never guessed here — absent it, the
     *                                            template's "Dear Hiring Manager,"
     *                                            fallback applies.
     * @param  ?CoverLetterRequirement  $requirement  the dispatcher's verdict, carried only
     *                                                so {@see failed()} can tell a blocking
     *                                                failure from a harmless one after the
     *                                                worker has died. It is *not* the gate:
     *                                                {@see handle()} re-detects, and its own
     *                                                verdict wins over this one wherever both
     *                                                exist. See the class docblock.
     */
    public function __construct(
        public readonly int $jobListingId,
        public readonly int $userId,
        public readonly ?string $templateKey = null,
        public readonly ?CoverLetterRecipient $recipient = null,
        public readonly ?CoverLetterRequirement $requirement = null,
    ) {}

    public function handle(
        CoverLetterRequirementDetector $detector,
        \App\Services\Tailoring\CoverLetterTailoringService $tailoring,
        LatexRenderService $renderer,
        TailoredDocumentStorage $storage,
        AutoApplyDispatcher $autoApply,
    ): void {
        $listing = JobListing::find($this->jobListingId);

        if ($listing === null) {
            // Deleted between dispatch and execution. Retrying cannot help.
            Log::notice('Skipping cover letter: the job listing no longer exists.', [
                'job_listing_id' => $this->jobListingId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        if ($this->isPastTailoring($listing)) {
            // design.md Property 7: stages move forward only. A letter produced
            // after the application went out cannot be attached to it, so the
            // spend would buy nothing.
            Log::notice('Skipping cover letter: the application has already been submitted.', [
                'job_listing_id' => $listing->id,
                'user_id' => $this->userId,
                'pipeline_stage' => $listing->pipeline_stage?->value,
            ]);

            return;
        }

        $profile = UserProfile::query()->where('user_id', $this->userId)->first();

        if ($profile === null) {
            // Nothing to write from, and not retryable: the profile appears once a
            // resume has been parsed (Requirement 2.2).
            Log::notice('Skipping cover letter: the user has no parsed profile yet.', [
                'job_listing_id' => $listing->id,
                'user_id' => $this->userId,
            ]);

            return;
        }

        // Requirement 7.2's gate, enforced by the spender rather than the
        // dispatcher. The class docblock argues why this is re-checked.
        $detection = $detector->detect($listing);

        if (! $detection->wantsCoverLetter()) {
            Log::info('Skipping cover letter: the posting does not ask for one.', [
                'job_listing_id' => $listing->id,
                'user_id' => $this->userId,
                'dispatcher_requirement' => $this->requirement?->value,
            ] + $detection->context());

            // The chain still has to continue: this job was dispatched *because*
            // the resume job's detection wanted a letter, so refusing to write one
            // without handing the application on would strand a tailored resume
            // with nobody left to submit it. See the class docblock's "the chain
            // passes through here" section.
            $this->handOff($listing, $autoApply, ['cover_letter' => 'not_requested']);

            return;
        }

        if ($this->hasRenderedLetter($listing)) {
            // A finished letter for this pair already exists. Re-running would pay
            // for a near-identical document and leave two candidates for the same
            // slot on the application; a genuine re-generation should delete or
            // supersede the existing row first.
            Log::info('Skipping cover letter: one has already been rendered for this listing.', [
                'job_listing_id' => $listing->id,
                'user_id' => $this->userId,
            ]);

            // Both documents exist, which is the state the apply stage is waiting
            // for; the only reason this run has nothing to do is that a previous
            // one did it.
            $this->handOff($listing, $autoApply, ['cover_letter' => 'already_rendered']);

            return;
        }

        // Before the render, so a crash leaves evidence rather than nothing —
        // {@see TailorResume::pendingDocument()} makes the full argument.
        $document = $this->pendingDocument($listing);

        try {
            $compiled = $this->generateAndCompile($listing, $profile, $tailoring, $renderer, $document);
        } catch (CoverLetterTailoringException $e) {
            $this->recordFailure($listing, $document, $detection->requirement, 'generation', $e->reviewReason(), $e->context);

            // A mandatory letter's failure moved the listing to `needs_review`,
            // which the gate refuses on its own — so this call submits the
            // resume alone only when the letter was optional, which is exactly
            // what recordFailure() decided.
            $this->handOff($listing, $autoApply, ['cover_letter' => 'generation_failed']);

            return;
        }

        // LatexEngineException and InvalidArgumentException from the render are
        // intentionally not caught: they are the host's problem, not this
        // document's, so they fail the job instead of recording a perfectly good
        // generation as bad content.

        if ($compiled === null) {
            // The compile budget is spent. Already logged in detail by the loop.
            $this->recordFailure(
                $listing,
                $document,
                $detection->requirement,
                'compile',
                'the cover letter could not be typeset within the allowed attempts',
            );

            $this->handOff($listing, $autoApply, ['cover_letter' => 'compile_failed']);

            return;
        }

        [$letter, $rendered, $templateKey, $attempts] = $compiled;

        $this->store($document, $letter, $rendered, $templateKey, $storage);

        $this->link($listing, $document);

        Log::info('Tailored and stored a cover letter for a job listing.', [
            'job_listing_id' => $listing->id,
            'user_id' => $this->userId,
            'tailored_document_id' => $document->getKey(),
            'application_id' => $document->application_id,
            'template_key' => $templateKey,
            'compile_attempts' => $attempts,
            's3_path' => $document->s3_path,
            'tex_source_s3_path' => $document->tex_source_s3_path,
        ] + $letter->context() + $detection->context());

        // Both documents are now stored and linked to the same application row,
        // so the posting is ready to be submitted *with* its letter.
        $this->handOff($listing, $autoApply, ['cover_letter' => 'rendered']);
    }

    /**
     * Continue the chain: `Tailor → Apply` (Requirement 11.3).
     *
     * This job, not {@see TailorResume}, makes the apply dispatch for any posting
     * that wants a cover letter — that is what guarantees a letter-requiring
     * posting is never submitted resume-only and never submitted twice, and
     * {@see AutoApplyDispatcher} records why that ordering is structural rather
     * than a readiness race.
     *
     * The consequence is that *every* terminal path through {@see handle()} has to
     * come through here, including the ones where no letter was produced. None of
     * them has to decide whether submitting is appropriate: the gate re-reads the
     * listing's stage and the user's auto-apply settings, so a failed mandatory
     * letter (`needs_review`) and a hand-tailored `store_only` job are both
     * refused there, in one place, rather than by a condition repeated at each
     * call site.
     *
     * The listing is re-read before the gate sees it, because the paths that get
     * here may have written the stage a moment ago.
     *
     * @param  array<string, mixed>  $context
     */
    protected function handOff(JobListing $listing, AutoApplyDispatcher $autoApply, array $context = []): void
    {
        $autoApply->dispatchIfReady($listing->refresh(), $this->userId, [
            'dispatched_from' => 'tailor_cover_letter',
        ] + $context);
    }

    /**
     * Generate a letter and compile it, re-generating on a compile failure up to
     * `pipeline.latex_retry_max` times (Requirements 7.4, 6.4).
     *
     * Returns `[content, rendered document, template key, attempts]` on success,
     * or `null` when every allowed render failed to compile. `null` rather than an
     * exception because this job is the only caller and the only handler — see
     * the class docblock, which also explains why the retry re-generates instead
     * of correcting, and why that limitation is recorded rather than hidden.
     *
     * The template key is resolved once, before the loop: every attempt must
     * render through the same template, or a "the template is at fault" diagnosis
     * would be comparing two different documents.
     *
     * @return ?array{0: TailoredCoverLetterContent, 1: RenderedDocument, 2: string, 3: int}
     *
     * @throws CoverLetterTailoringException when the model will not write usable prose
     * @throws \App\Exceptions\LatexEngineException when no TeX engine is available
     * @throws InvalidArgumentException when the template key is not a known cover-letter template
     */
    protected function generateAndCompile(
        JobListing $listing,
        UserProfile $profile,
        \App\Services\Tailoring\CoverLetterTailoringService $tailoring,
        LatexRenderService $renderer,
        TailoredDocument $document,
    ): ?array {
        $templateKey = $this->templateKey !== null && trim($this->templateKey) !== ''
            ? trim($this->templateKey)
            : $tailoring->defaultTemplateKey();

        $maxRetries = max(0, (int) config('pipeline.latex_retry_max', 2));

        /** @var array<int, string> $errors */
        $errors = [];

        // One render more than the retry budget: "max N retries" means the first
        // attempt is not a retry.
        for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
            // A CoverLetterTailoringException propagates: it is the response
            // budget's own terminal failure and has nothing to do with typesetting,
            // and a model that will not follow the content contract will not follow
            // it better with a compile error attached.
            $letter = $tailoring->tailor($listing, $profile, $this->recipient);

            $rendered = $renderer->renderCoverLetter($templateKey, $letter->forTemplate());

            if ($rendered->success) {
                return [$letter, $rendered, $templateKey, $attempt];
            }

            $errors[] = (string) $rendered->compileError;

            Log::warning('Tailored cover letter failed to compile.', [
                'job_listing_id' => $listing->id,
                'user_id' => $this->userId,
                'tailored_document_id' => $document->getKey(),
                'template_key' => $templateKey,
                'attempt' => $attempt,
                'retries_remaining' => max(0, $maxRetries + 1 - $attempt),
                'exit_code' => $rendered->exitCode,
                'working_directory' => $rendered->workingDirectory,
                'compile_error' => Str::limit($errors[count($errors) - 1], 2000),
            ]);
        }

        Log::error('Cover letter compilation failed after every allowed attempt.', [
            'job_listing_id' => $listing->id,
            'user_id' => $this->userId,
            'tailored_document_id' => $document->getKey(),
            'template_key' => $templateKey,
            'attempts' => count($errors),
            // The single most useful thing a reviewer can be told, and the reason
            // ResumeCompilationException carries the same flag: identical errors
            // across different prose means the template or the engine is at fault
            // and re-running this job is pointless.
            'failed_identically' => $this->failedIdentically($errors),
        ]);

        return null;
    }

    /**
     * Did every attempt fail the same way?
     *
     * Computed here rather than borrowed from
     * {@see \App\Exceptions\ResumeCompilationException::failedIdentically()},
     * because reaching into a resume-named exception for one comparison would
     * couple the two documents' failure reporting for no benefit. A single
     * attempt is deliberately *not* "identical": one sample says nothing about
     * whether different content would fare differently.
     *
     * @param  array<int, string>  $errors
     */
    protected function failedIdentically(array $errors): bool
    {
        return count($errors) > 1 && count(array_unique($errors)) === 1;
    }

    /**
     * The `pending` row this run will fill in.
     *
     * Identical rules to {@see TailorResume::pendingDocument()}, including why an
     * existing `pending` row is reused (stable S3 keys across retries, and one
     * row per piece of work rather than one per attempt) and why a terminal row
     * never is. The only difference is the type filter.
     */
    protected function pendingDocument(JobListing $listing): TailoredDocument
    {
        $document = TailoredDocument::query()
            ->where('user_id', $this->userId)
            ->where('job_listing_id', $listing->id)
            ->ofType(TailoredDocumentType::CoverLetter)
            ->where('status', TailoredDocumentStatus::Pending)
            ->latest('id')
            ->first();

        if ($document !== null) {
            // An unspecified key leaves whatever the row already carries: the
            // tailoring service resolves null to the configured default, and this
            // row should not claim a key the render did not use.
            if ($this->templateKey !== null && trim($this->templateKey) !== '') {
                $document->update(['template_key' => trim($this->templateKey)]);
            }

            return $document;
        }

        $attributes = [
            'user_id' => $this->userId,
            'job_listing_id' => $listing->id,
            'type' => TailoredDocumentType::CoverLetter,
            'status' => TailoredDocumentStatus::Pending,
        ];

        if ($this->templateKey !== null && trim($this->templateKey) !== '') {
            $attributes['template_key'] = trim($this->templateKey);
        }

        return TailoredDocument::create($attributes);
    }

    /**
     * Is there already a rendered cover letter for this (user, listing) pair?
     *
     * Checked *after* the detection gate rather than before it, so the log still
     * records what the posting asks for on a skipped run — the cheap answer is
     * the common one and the query is one indexed lookup either way.
     */
    protected function hasRenderedLetter(JobListing $listing): bool
    {
        return TailoredDocument::query()
            ->where('user_id', $this->userId)
            ->where('job_listing_id', $listing->id)
            ->ofType(TailoredDocumentType::CoverLetter)
            ->rendered()
            ->exists();
    }

    /**
     * Upload both artefacts, then promote the row to `rendered`
     * (Requirements 7.3, 8.1, 8.2, 8.3).
     *
     * Upload first, update second, and never the other way round — the argument
     * is {@see TailorResume::store()}'s and applies unchanged: a `rendered` row is
     * a promise that `s3_path` can be fetched, and because
     * {@see TailoredDocumentStorage} throws rather than returning `false`, a
     * storage problem leaves the row `pending` for the queue to retry, which is
     * what Requirement 8.5 asks for.
     *
     * The keys need no cover-letter special case:
     * {@see TailoredDocumentStorage} types its filename stem off
     * {@see TailoredDocumentType}, so a row of this type keys as
     * `cover-letter.pdf` / `cover-letter.tex` by construction. This job therefore
     * never encodes the key layout, and task 13.1 can absorb it without touching
     * this file.
     *
     * `fabrication_flags` is deliberately left alone — see the class docblock.
     */
    protected function store(
        TailoredDocument $document,
        TailoredCoverLetterContent $letter,
        RenderedDocument $rendered,
        string $templateKey,
        TailoredDocumentStorage $storage,
    ): void {
        $pdfKey = $storage->uploadPdf($document, (string) $rendered->pdfPath);
        $texKey = $storage->uploadTexSource($document, $rendered->texSource);

        $document->update([
            's3_path' => $pdfKey,
            'tex_source_s3_path' => $texKey,
            // The template that actually rendered, not the argument that was
            // passed: null resolves to the configured default.
            'template_key' => $templateKey,
            'generation_model' => $letter->modelUsed,
            'generation_tier' => $letter->tierUsed,
            'status' => TailoredDocumentStatus::Rendered,
        ]);

        $this->discardLocalPdf($rendered);
    }

    /**
     * Link the letter to the same `applications` row as the tailored resume
     * (Requirement 7.3).
     *
     * The row is found-or-created on `(user_id, job_listing_id)` — the same
     * natural key {@see TailorResume::link()} matches on — so whichever of the two
     * jobs runs first creates it and the second attaches to it. `status` is set to
     * `pending` on creation, overriding the column's legacy `applied` default, for
     * the reason that job gives: nothing has been submitted yet, and a row
     * claiming otherwise would corrupt the dashboard's counts.
     *
     * Only `tailored_documents.application_id` is written unconditionally.
     * `applications.tailored_cover_letter_id` — the name
     * {@see TailoredDocumentType::CoverLetter} resolves to — arrived with task
     * 14.1's migration, so the reverse write is guarded by a schema check and a
     * missing column is logged rather than thrown. With the migration applied the
     * guard falls through and both directions are written; it remains for the
     * database that has not run 14.1 yet, where storing the letter still beats
     * failing the job.
     *
     * The schema check is not free, but it runs once per stored letter (a cached
     * column listing on the connection), which is nothing next to the render that
     * preceded it.
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

        $document->update(['application_id' => $application->getKey()]);

        $foreignKey = TailoredDocumentType::CoverLetter->applicationForeignKey();

        if (! Schema::hasColumn($application->getTable(), $foreignKey)) {
            Log::notice('Stored a cover letter without the reverse application link; the column is pending task 14.1.', [
                'job_listing_id' => $listing->id,
                'user_id' => $this->userId,
                'tailored_document_id' => $document->getKey(),
                'application_id' => $application->getKey(),
                'missing_column' => $application->getTable().'.'.$foreignKey,
            ]);

            return;
        }

        $application->update([$foreignKey => $document->getKey()]);
    }

    /**
     * Record a letter that could not be produced, and decide whether it blocks
     * the application (Requirement 7.4).
     *
     * The document row is closed out to `failed` either way: it is the record that
     * the run happened, which is the whole reason the row is created before the
     * render, and `s3_path` stays at `''` so {@see TailoredDocument::isUsable()}
     * refuses it on both counts.
     *
     * The listing only moves when the posting *mandated* a letter, in which case
     * the application is incomplete and `needs_review` keeps it out of the
     * auto-apply path. An optional letter's failure leaves the stage exactly as it
     * was, so the tailored resume goes out alone — the class docblock argues why
     * collapsing these two would be wrong in both directions.
     *
     * `needs_review` rather than `failed`, matching {@see TailorResume}: the run
     * reached its designed conclusion and that conclusion needs a person, which is
     * a different fact from "the stage died".
     *
     * @param  array<string, mixed>  $context
     */
    protected function recordFailure(
        JobListing $listing,
        TailoredDocument $document,
        CoverLetterRequirement $requirement,
        string $failure,
        string $reason,
        array $context = [],
    ): void {
        $document->update(['status' => TailoredDocumentStatus::Failed]);

        $blocking = $requirement->isMandatory();

        if ($blocking) {
            $listing->update(['pipeline_stage' => PipelineStage::NeedsReview]);
        }

        $logContext = [
            'job_listing_id' => $listing->id,
            'user_id' => $this->userId,
            'tailored_document_id' => $document->getKey(),
            'failure' => $failure,
            'cover_letter_requirement' => $requirement->value,
            'blocks_application' => $blocking,
            'reason' => $reason,
        ] + $context;

        if ($blocking) {
            Log::error('A required cover letter could not be produced; flagged the listing for manual review.', $logContext);

            return;
        }

        // Warning, not error: the application is still submittable, and paging an
        // operator over an optional document nobody asked for is how a useful log
        // level stops being read.
        Log::warning('An optional cover letter could not be produced; the application proceeds without it.', $logContext);
    }

    /**
     * Delete the compiled PDF from local disk now that S3 has it.
     *
     * {@see LatexRenderService} moves the PDF *out* of its render directory before
     * deleting that directory, so the file survives for the upload and is left
     * with no owner afterwards. Nothing else cleans it up.
     *
     * Never fatal, for {@see TailorResume::discardLocalPdf()}'s reason: throwing
     * here would send a job whose real work is already complete back for a retry
     * that re-pays for the whole generation.
     */
    protected function discardLocalPdf(RenderedDocument $rendered): void
    {
        $path = (string) $rendered->pdfPath;

        if ($path === '' || ! is_file($path)) {
            return;
        }

        if (! File::delete($path)) {
            Log::notice('Could not delete the local copy of an uploaded cover letter.', [
                'job_listing_id' => $this->jobListingId,
                'user_id' => $this->userId,
                'path' => $path,
            ]);
        }
    }

    /**
     * Has the application already gone out?
     *
     * A shorter list than {@see TailorResume::isPastTailoring()}'s, and the
     * difference is the point: `tailored` stays *open* here. The resume job sets
     * that stage when it finishes, and a cover letter is a companion artefact that
     * legitimately completes after it — treating `tailored` as past would make
     * this job refuse every run that lost the race with its sibling.
     *
     * `applying` and `applied` are closed because the submission is out of the
     * platform's hands: a letter produced now cannot be attached to it.
     */
    protected function isPastTailoring(JobListing $listing): bool
    {
        return in_array($listing->pipeline_stage, [
            PipelineStage::Applying,
            PipelineStage::Applied,
        ], true);
    }

    /**
     * Retries exhausted, or the worker was killed.
     *
     * Only environment faults reach here — content and compile failures are
     * recorded inside {@see handle()} — so the situation is
     * {@see TailorResume::failed()}'s: the queue is finished, nothing will ever
     * render this row, and a `pending` row no worker owns is one the frontend
     * polls forever ({@see TailoredDocumentStatus::isTerminal()}). It is closed to
     * `failed` here, and only here. Rows that already reached `rendered` keep
     * their status and their usable PDF.
     *
     * Two things this deliberately does *not* do, both following from the letter
     * being companion work:
     *
     *  - **It never sets the listing to `failed`.** {@see TailorResume::failed()}
     *    does, correctly, because without a resume there is no application. A dead
     *    cover-letter worker says nothing about the resume, and marking the listing
     *    `failed` from here would discard a perfectly good tailored resume — and
     *    could overwrite a stage its sibling job legitimately set.
     *  - **It does not re-detect.** {@see handle()} owns the gate, but its verdict
     *    died with the attempt, and re-running the detector on a failure path could
     *    spend a model call to decorate a log line. {@see $requirement} — the
     *    dispatcher's verdict, carried for exactly this moment — is used instead,
     *    and a mandatory letter is escalated to `needs_review` so an incomplete
     *    application is not auto-applied. When it is absent the failure is treated
     *    as non-blocking and logged: the letter was conditional work, and guessing
     *    "mandatory" would stall applications over an unknown.
     */
    public function failed(?Throwable $e): void
    {
        $blocking = $this->requirement?->isMandatory() === true;

        Log::error('Cover letter tailoring failed permanently for a job listing.', [
            'job_listing_id' => $this->jobListingId,
            'user_id' => $this->userId,
            'template_key' => $this->templateKey,
            'cover_letter_requirement' => $this->requirement?->value,
            'blocks_application' => $blocking,
            'reason' => $e?->getMessage(),
            'exception' => $e === null ? null : $e::class,
        ]);

        TailoredDocument::query()
            ->where('user_id', $this->userId)
            ->where('job_listing_id', $this->jobListingId)
            ->ofType(TailoredDocumentType::CoverLetter)
            ->where('status', TailoredDocumentStatus::Pending)
            ->update(['status' => TailoredDocumentStatus::Failed->value]);

        if (! $blocking) {
            // The letter was optional and the chain runs through this job
            // (Requirement 11.3), so a dead worker must not strand the tailored
            // resume: the application goes out without the letter, subject to the
            // same gate every other path is subject to.
            $listing = JobListing::find($this->jobListingId);

            if ($listing !== null) {
                app(AutoApplyDispatcher::class)->dispatchIfReady($listing, $this->userId, [
                    'dispatched_from' => 'tailor_cover_letter_failed',
                ]);
            }

            return;
        }

        JobListing::where('id', $this->jobListingId)
            ->whereNotIn('pipeline_stage', [
                PipelineStage::Applied->value,
                PipelineStage::Applying->value,
            ])
            ->update(['pipeline_stage' => PipelineStage::NeedsReview->value]);
    }
}
