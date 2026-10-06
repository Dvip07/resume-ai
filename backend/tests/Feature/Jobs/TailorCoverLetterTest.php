<?php

namespace Tests\Feature\Jobs;

use App\Enums\CoverLetterDetectionMethod;
use App\Enums\CoverLetterRequirement;
use App\Enums\PipelineStage;
use App\Enums\TailoredDocumentStatus;
use App\Enums\TailoredDocumentType;
use App\Exceptions\CoverLetterTailoringException;
use App\Exceptions\LatexEngineException;
use App\Jobs\TailorCoverLetter;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\TailoredDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Latex\LatexRenderService;
use App\Services\Latex\RenderedDocument;
use App\Services\Storage\TailoredDocumentStorage;
use App\Services\Tailoring\CoverLetterDetection;
use App\Services\Tailoring\CoverLetterRecipient;
use App\Services\Tailoring\CoverLetterRequirementDetector;
use App\Services\Tailoring\CoverLetterTailoringService;
use App\Services\Tailoring\TailoredCoverLetterContent;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The cover-letter half of the tailoring stage as a queued job (task 12.3):
 * whether the letter is written at all, what lands on S3, what it is linked to,
 * and — the part that is genuinely its own — whether a failed letter is allowed
 * to hold an application back.
 *
 * Modelled on {@see TailorResumeTest} and stubbed the same way: the three
 * collaborators that would cost money or need a TeX engine are *subclasses* of
 * the real classes ({@see StubCoverLetterDetector},
 * {@see StubCoverLetterTailoring}, {@see StubCoverLetterRenderer}) bound into
 * the container, so PHP enforces their signatures while nothing here calls a
 * model or a compiler. {@see TailoredDocumentStorage} is the real service over
 * `Storage::fake('s3')`, because the `cover-letter.pdf` / `cover-letter.tex`
 * keys are part of what this job promises.
 *
 * The stub renderer hands back a {@see RenderedDocument} whose `pdfPath` is a
 * real file under {@see $tempRoot}: the job uploads it and then deletes it, and
 * both halves are asserted.
 *
 * Validates: Requirements 7.1, 7.2, 7.3, 7.4, 8.1, 8.2, 8.3, 8.5
 */
class TailorCoverLetterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** Scratch root for the "compiled" PDFs handed to the job. */
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        // The letter job owns the apply dispatch now (task 15.10), and the test
        // queue is `sync`.
        Queue::fake();

        $this->user = User::factory()->create();

        $this->tempRoot = sys_get_temp_dir().'/tailor-cover-letter-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tempRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------
     | Requirement 7.2: the gate, re-checked by the spender
     | ----------------------------------------------------------------- */

    public function test_a_posting_that_does_not_ask_for_a_letter_costs_nothing(): void
    {
        $this->profile();
        $listing = $this->listing();

        $tailoring = new StubCoverLetterTailoring($this->letter());

        $this->runJob(
            $listing->id,
            detection: $this->detection(CoverLetterRequirement::NotRequested, CoverLetterDetectionMethod::Absent),
            tailoring: $tailoring,
        );

        $this->assertSame(0, $tailoring->calls, 'the tailoring service must not be reached');
        $this->assertSame(0, TailoredDocument::query()->count(), 'no row should be created for a skipped letter');
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $this->assertSame(0, Application::query()->count());
        $this->assertSame(PipelineStage::Scored, $listing->refresh()->pipeline_stage);
    }

    /**
     * The dispatcher's verdict is not the gate: {@see TailorCoverLetter::handle()}
     * re-detects and its own verdict wins.
     */
    public function test_the_jobs_own_detection_overrides_the_dispatchers_verdict(): void
    {
        $this->profile();
        $listing = $this->listing();

        $tailoring = new StubCoverLetterTailoring($this->letter());

        $this->runJob(
            $listing->id,
            detection: $this->detection(CoverLetterRequirement::NotRequested, CoverLetterDetectionMethod::Keyword),
            tailoring: $tailoring,
            // The dispatcher thought a letter was mandatory; the description has
            // since changed.
            requirement: CoverLetterRequirement::Required,
        );

        $this->assertSame(0, $tailoring->calls);
        $this->assertSame(0, TailoredDocument::query()->count());
    }

    /* ------------------------------------------------------------------
     | Success (Requirements 7.1, 7.3, 8.1, 8.2, 8.3)
     | ----------------------------------------------------------------- */

    public function test_a_successful_run_stores_both_artefacts_at_the_cover_letter_keys(): void
    {
        $profile = $this->profile();
        $listing = $this->listing();

        $pdfPath = $this->compiledPdf();
        $tailoring = new StubCoverLetterTailoring($this->letter());
        $recipient = new CoverLetterRecipient(name: 'Dana Reyes', title: 'Engineering Manager');

        $this->runJob(
            $listing->id,
            tailoring: $tailoring,
            renderer: new StubCoverLetterRenderer(RenderedDocument::compiled('\documentclass{letter}% tailored', $pdfPath)),
            templateKey: 'default',
            recipient: $recipient,
        );

        $document = $this->letterDocument();

        $this->assertNotNull($document);
        $this->assertSame(TailoredDocumentStatus::Rendered, $document->status);
        $this->assertSame(TailoredDocumentType::CoverLetter, $document->type);
        $this->assertSame('default', $document->template_key);
        $this->assertSame('openai/gpt-4o', $document->generation_model);
        $this->assertSame('premium', $document->generation_tier);
        $this->assertTrue($document->isUsable());

        // The keys TailoredDocumentStorage types off the document's own kind.
        $prefix = 'users/'.$this->user->id.'/tailored-documents/'.$document->getKey();
        $this->assertSame($prefix.'/cover-letter.pdf', $document->s3_path);
        $this->assertSame($prefix.'/cover-letter.tex', $document->tex_source_s3_path);

        Storage::disk('s3')->assertExists($document->s3_path);
        Storage::disk('s3')->assertExists($document->tex_source_s3_path);
        $this->assertSame('%PDF-1.4 compiled', Storage::disk('s3')->get($document->s3_path));
        $this->assertSame('\documentclass{letter}% tailored', Storage::disk('s3')->get($document->tex_source_s3_path));

        // Nothing else cleans the local PDF up.
        $this->assertFileDoesNotExist($pdfPath);

        $this->assertSame(1, $tailoring->calls);
        $this->assertSame($profile->id, $tailoring->profileSeen?->id);
        $this->assertSame($recipient, $tailoring->recipientSeen, 'the caller-supplied recipient must pass through');

        // Task 11.5 owns the cover-letter fabrication inspection; an unflagged
        // letter here means "not inspected", not "inspected clean".
        $this->assertNull($document->fabrication_flags);
    }

    /**
     * The one structural difference from {@see TailorResume}: this job never
     * writes `pipeline_stage` on success — `tailored` stays the resume job's word.
     */
    public function test_a_successful_run_never_advances_the_pipeline_stage(): void
    {
        $this->profile();
        $listing = $this->listing();

        $this->runJob($listing->id);

        $this->assertSame(TailoredDocumentStatus::Rendered, $this->letterDocument()?->status);
        $this->assertSame(PipelineStage::Scored, $listing->refresh()->pipeline_stage);
    }

    public function test_the_row_records_the_template_that_actually_rendered(): void
    {
        $this->profile();
        $listing = $this->listing();

        // No key passed: the tailoring service resolves the configured default
        // and that — not null — is what the row must claim.
        $this->runJob(
            $listing->id,
            tailoring: new StubCoverLetterTailoring($this->letter(), defaultTemplateKey: 'default'),
            templateKey: null,
        );

        $this->assertSame('default', $this->letterDocument()?->template_key);
    }

    /* ------------------------------------------------------------------
     | Requirement 7.3: linking, and the half the schema cannot hold yet
     | ----------------------------------------------------------------- */

    public function test_the_letter_is_linked_to_a_pending_application_row(): void
    {
        $this->profile();
        $listing = $this->listing();

        $this->runJob($listing->id);

        $document = $this->letterDocument();
        $application = Application::query()->sole();

        $this->assertSame('pending', $application->status);
        $this->assertSame($this->user->id, (int) $application->user_id);
        $this->assertSame($listing->id, (int) $application->job_listing_id);
        $this->assertSame($application->getKey(), $document->application_id);
    }

    public function test_the_letter_attaches_to_the_application_the_resume_already_created(): void
    {
        $this->profile();
        $listing = $this->listing();

        // The row TailorResume::link() would have created first.
        $existing = Application::create([
            'user_id' => $this->user->id,
            'job_listing_id' => $listing->id,
            'status' => 'pending',
        ]);

        $this->runJob($listing->id);

        $this->assertSame(1, Application::query()->count(), 'the letter must not open a second application');
        $this->assertSame($existing->getKey(), $this->letterDocument()?->application_id);
    }

    /**
     * The reverse link, now that task 14.1's migration has added
     * `applications.tailored_cover_letter_id`: the job's schema guard falls
     * through and both directions are written, with no notice logged.
     */
    public function test_both_link_directions_are_written_once_the_column_exists(): void
    {
        $this->assertTrue(
            Schema::hasColumn('applications', 'tailored_cover_letter_id'),
            'task 14.1 added this column; the guarded reverse write depends on it.'
        );

        $this->profile();
        $listing = $this->listing();

        Log::spy();

        $this->runJob($listing->id);

        $document = $this->letterDocument();
        $application = Application::query()->sole();

        $this->assertSame($application->getKey(), $document->application_id);
        $this->assertSame($document->getKey(), $application->tailored_cover_letter_id);
        $this->assertSame($document->getKey(), $application->tailoredCoverLetter->getKey());
        $this->assertSame(TailoredDocumentStatus::Rendered, $document->status);

        Log::shouldNotHaveReceived('notice');
    }

    /* ------------------------------------------------------------------
     | Requirement 7.4: the fallback asymmetry
     | ----------------------------------------------------------------- */

    public function test_a_failed_required_letter_flags_the_listing_for_review(): void
    {
        $this->profile();
        $listing = $this->listing();

        $this->runJob(
            $listing->id,
            detection: $this->detection(CoverLetterRequirement::Required),
            tailoring: new StubCoverLetterTailoring(CoverLetterTailoringException::promptFailed(
                'cover_letter_tailor',
                [['attempt' => 2, 'parse_failure' => true, 'reason' => 'Returned one paragraph.']],
            )),
        );

        $document = $this->letterDocument();

        $this->assertSame(TailoredDocumentStatus::Failed, $document->status);
        $this->assertSame('', $document->s3_path);
        $this->assertFalse($document->isUsable());
        $this->assertSame([], Storage::disk('s3')->allFiles());

        // The application is incomplete, so it must not be auto-applied.
        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);
    }

    public function test_a_failed_optional_letter_does_not_block_the_application(): void
    {
        $this->profile();
        $listing = $this->listing();

        $this->runJob(
            $listing->id,
            detection: $this->detection(CoverLetterRequirement::Optional),
            tailoring: new StubCoverLetterTailoring(CoverLetterTailoringException::promptFailed(
                'cover_letter_tailor',
                [['attempt' => 2, 'parse_failure' => true, 'reason' => 'Returned one paragraph.']],
            )),
        );

        // The row records the attempt either way — the difference is only whether
        // the listing leaves the auto-apply path.
        $this->assertSame(TailoredDocumentStatus::Failed, $this->letterDocument()?->status);
        $this->assertSame(PipelineStage::Scored, $listing->refresh()->pipeline_stage);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_a_required_letter_that_will_not_typeset_flags_the_listing_for_review(): void
    {
        config(['pipeline.latex_retry_max' => 2]);

        $this->profile();
        $listing = $this->listing();

        $tailoring = new StubCoverLetterTailoring($this->letter());

        $this->runJob(
            $listing->id,
            detection: $this->detection(CoverLetterRequirement::Required),
            tailoring: $tailoring,
            renderer: new StubCoverLetterRenderer(RenderedDocument::failed(
                '\documentclass{letter}',
                'Overfull \hbox',
                exitCode: 1,
                workingDirectory: '/tmp/render',
            )),
        );

        // The budget is `latex_retry_max + 1` renders, and each one re-generates.
        $this->assertSame(3, $tailoring->calls);

        $this->assertSame(TailoredDocumentStatus::Failed, $this->letterDocument()?->status);
        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_an_optional_letter_that_will_not_typeset_leaves_the_listing_alone(): void
    {
        config(['pipeline.latex_retry_max' => 0]);

        $this->profile();
        $listing = $this->listing();

        $tailoring = new StubCoverLetterTailoring($this->letter());

        $this->runJob(
            $listing->id,
            detection: $this->detection(CoverLetterRequirement::Optional),
            tailoring: $tailoring,
            renderer: new StubCoverLetterRenderer(RenderedDocument::failed('\documentclass{letter}', 'Missing $ inserted')),
        );

        // Zero retries still buys one render: "max N retries" excludes the first.
        $this->assertSame(1, $tailoring->calls);

        $this->assertSame(TailoredDocumentStatus::Failed, $this->letterDocument()?->status);
        $this->assertSame(PipelineStage::Scored, $listing->refresh()->pipeline_stage);
    }

    /* ------------------------------------------------------------------
     | Environment faults: propagated, row left in flight
     | ----------------------------------------------------------------- */

    public function test_a_missing_tex_engine_fails_the_job_rather_than_the_document(): void
    {
        $this->profile();
        $listing = $this->listing();

        try {
            $this->runJob(
                $listing->id,
                detection: $this->detection(CoverLetterRequirement::Required),
                renderer: new StubCoverLetterRenderer(LatexEngineException::notFound('tectonic')),
            );
            $this->fail('Expected the engine fault to fail the job rather than the document.');
        } catch (LatexEngineException $e) {
            $this->assertSame('tectonic', $e->engine);
        }

        // `pending` because a retry is still coming and will reuse this row, and
        // the listing is untouched even though the letter was mandatory: nothing
        // has been concluded about the document yet.
        $document = $this->letterDocument();
        $this->assertSame(TailoredDocumentStatus::Pending, $document->status);
        $this->assertSame('', $document->s3_path);
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $this->assertSame(PipelineStage::Scored, $listing->refresh()->pipeline_stage);
        $this->assertSame(0, Application::query()->count());
    }

    /** An unknown cover-letter template key is a deployment fault, not bad prose. */
    public function test_an_unknown_template_key_fails_the_job_and_leaves_the_row_pending(): void
    {
        $this->profile();
        $listing = $this->listing();

        try {
            $this->runJob(
                $listing->id,
                renderer: new StubCoverLetterRenderer(new \InvalidArgumentException('Unknown cover letter template [nope].')),
                templateKey: 'nope',
            );
            $this->fail('Expected the unknown template to fail the job.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('nope', $e->getMessage());
        }

        $this->assertSame(TailoredDocumentStatus::Pending, $this->letterDocument()?->status);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    /**
     * Requirement 8.5, the half that is about status: a failed upload must not
     * mark the step complete.
     *
     * The resume job has the same test ({@see TailorResumeTest}); the letter
     * needs its own because it is a *different* upload-then-promote sequence in
     * a different class, and the guarantee is only as good as the ordering in
     * whichever class is doing the writing.
     *
     * `times(3)` is the in-process retry cap
     * ({@see \App\Services\Storage\StorageWriteRetrier}): a write that fails
     * every attempt is retried in place and *then* allowed to fail the job, so
     * the queue retry — and the `pending` row it will reuse — still happens for
     * a genuine outage.
     */
    public function test_a_failed_upload_leaves_the_letter_pending_rather_than_rendered(): void
    {
        $this->profile();
        $listing = $this->listing();

        config(['filesystems.upload_retry.attempts' => 3]);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->times(3)->andReturn(false);
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

        try {
            $this->runJob($listing->id, detection: $this->detection(CoverLetterRequirement::Required));
            $this->fail('Expected the storage failure to fail the job.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to store', $e->getMessage());
        }

        $document = $this->letterDocument();
        $this->assertSame(TailoredDocumentStatus::Pending, $document->status);
        $this->assertSame('', $document->s3_path);
        $this->assertNull($document->tex_source_s3_path);
        $this->assertFalse($document->isUsable());
    }

    /** A dead attempt leaves a `pending` row for the queue retry to reuse. */
    public function test_a_retry_reuses_the_pending_row_from_the_dead_attempt(): void
    {
        $this->profile();
        $listing = $this->listing();

        try {
            $this->runJob(
                $listing->id,
                renderer: new StubCoverLetterRenderer(LatexEngineException::notFound('tectonic')),
            );
        } catch (LatexEngineException) {
            // The attempt the queue will retry.
        }

        $pending = $this->letterDocument();
        $this->assertSame(TailoredDocumentStatus::Pending, $pending->status);

        $this->runJob($listing->id);

        $this->assertSame(1, TailoredDocument::query()->count(), 'a retry must not leave a second pending row');
        $this->assertSame($pending->getKey(), $this->letterDocument()->getKey());
        $this->assertSame(TailoredDocumentStatus::Rendered, $this->letterDocument()->status);
    }

    /* ------------------------------------------------------------------
     | No-ops
     | ----------------------------------------------------------------- */

    public function test_a_deleted_listing_is_a_no_op(): void
    {
        $this->profile();

        $detector = new StubCoverLetterDetector($this->detection(CoverLetterRequirement::Required));

        $this->runJob(999999, detector: $detector);

        $this->assertSame(0, $detector->calls, 'a listing that is gone is not worth detecting against');
        $this->assertSame(0, TailoredDocument::query()->count());
    }

    public function test_a_user_without_a_parsed_profile_is_a_no_op(): void
    {
        $listing = $this->listing();

        $tailoring = new StubCoverLetterTailoring($this->letter());

        $this->runJob($listing->id, tailoring: $tailoring);

        $this->assertSame(0, $tailoring->calls);
        $this->assertSame(0, TailoredDocument::query()->count());
        $this->assertSame(PipelineStage::Scored, $listing->refresh()->pipeline_stage);
    }

    public function test_a_submitted_application_is_never_given_a_late_letter(): void
    {
        $this->profile();
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Applied]);

        $tailoring = new StubCoverLetterTailoring($this->letter());

        $this->runJob($listing->id, tailoring: $tailoring);

        $this->assertSame(0, $tailoring->calls);
        $this->assertSame(PipelineStage::Applied, $listing->refresh()->pipeline_stage);
        $this->assertSame(0, TailoredDocument::query()->count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    /**
     * `tailored` is deliberately *not* past: the resume job sets that stage and a
     * companion letter legitimately finishes after it.
     */
    public function test_a_tailored_listing_is_still_open_to_its_companion_letter(): void
    {
        $this->profile();
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailored]);

        $tailoring = new StubCoverLetterTailoring($this->letter());

        $this->runJob($listing->id, tailoring: $tailoring);

        $this->assertSame(1, $tailoring->calls);
        $this->assertSame(TailoredDocumentStatus::Rendered, $this->letterDocument()?->status);
        $this->assertSame(PipelineStage::Tailored, $listing->refresh()->pipeline_stage);
    }

    public function test_a_letter_that_has_already_been_rendered_is_not_written_again(): void
    {
        $this->profile();
        $listing = $this->listing();

        $this->document($listing, TailoredDocumentStatus::Rendered, [
            's3_path' => 'users/'.$this->user->id.'/tailored-documents/1/cover-letter.pdf',
        ]);

        $tailoring = new StubCoverLetterTailoring($this->letter());

        $this->runJob($listing->id, tailoring: $tailoring);

        $this->assertSame(0, $tailoring->calls);
        $this->assertSame(1, TailoredDocument::query()->count());
    }

    /* ------------------------------------------------------------------
     | Permanent failure
     | ----------------------------------------------------------------- */

    public function test_a_permanently_failed_required_letter_closes_the_row_and_flags_the_listing(): void
    {
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailoring]);
        $document = $this->document($listing, TailoredDocumentStatus::Pending);

        $this->failJob($listing->id, CoverLetterRequirement::Required);

        // A pending row no worker owns is one the frontend polls forever.
        $this->assertSame(TailoredDocumentStatus::Failed, $document->refresh()->status);

        // `needs_review`, never `failed`: a dead cover-letter worker says nothing
        // about the resume.
        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);
    }

    public function test_a_permanently_failed_optional_letter_leaves_the_listing_alone(): void
    {
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailored]);
        $document = $this->document($listing, TailoredDocumentStatus::Pending);

        $this->failJob($listing->id, CoverLetterRequirement::Optional);

        $this->assertSame(TailoredDocumentStatus::Failed, $document->refresh()->status);
        $this->assertSame(PipelineStage::Tailored, $listing->refresh()->pipeline_stage);
    }

    /** No verdict carried: treated as non-blocking rather than guessed at. */
    public function test_a_permanent_failure_without_a_known_requirement_is_non_blocking(): void
    {
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailoring]);
        $document = $this->document($listing, TailoredDocumentStatus::Pending);

        $this->failJob($listing->id, null);

        $this->assertSame(TailoredDocumentStatus::Failed, $document->refresh()->status);
        $this->assertSame(PipelineStage::Tailoring, $listing->refresh()->pipeline_stage);
    }

    public function test_a_permanent_failure_leaves_a_rendered_letter_and_a_submitted_listing_alone(): void
    {
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Applied]);
        $document = $this->document($listing, TailoredDocumentStatus::Rendered, [
            's3_path' => 'users/'.$this->user->id.'/tailored-documents/1/cover-letter.pdf',
        ]);

        $this->failJob($listing->id, CoverLetterRequirement::Required);

        $this->assertSame(TailoredDocumentStatus::Rendered, $document->refresh()->status);
        $this->assertTrue($document->isUsable());
        $this->assertSame(PipelineStage::Applied, $listing->refresh()->pipeline_stage);
    }

    public function test_a_permanent_failure_does_not_touch_a_tailored_resume_row(): void
    {
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailoring]);

        $resume = TailoredDocument::create([
            'user_id' => $this->user->id,
            'job_listing_id' => $listing->id,
            'type' => TailoredDocumentType::Resume,
            'status' => TailoredDocumentStatus::Pending,
        ]);

        $this->failJob($listing->id, CoverLetterRequirement::Required);

        $this->assertSame(TailoredDocumentStatus::Pending, $resume->refresh()->status);
    }

    /* ------------------------------------------------------------------
     | Fixtures
     | ----------------------------------------------------------------- */

    private function runJob(
        int $listingId,
        ?CoverLetterDetection $detection = null,
        ?StubCoverLetterTailoring $tailoring = null,
        ?StubCoverLetterRenderer $renderer = null,
        ?string $templateKey = 'default',
        ?CoverLetterRecipient $recipient = null,
        ?CoverLetterRequirement $requirement = null,
        ?StubCoverLetterDetector $detector = null,
    ): void {
        $detector ??= new StubCoverLetterDetector(
            $detection ?? $this->detection(CoverLetterRequirement::Required)
        );

        $this->app->instance(CoverLetterRequirementDetector::class, $detector);
        $this->app->instance(
            CoverLetterTailoringService::class,
            $tailoring ?? new StubCoverLetterTailoring($this->letter())
        );
        $this->app->instance(
            LatexRenderService::class,
            $renderer ?? new StubCoverLetterRenderer(
                RenderedDocument::compiled('\documentclass{letter}', $this->compiledPdf())
            )
        );

        $job = new TailorCoverLetter($listingId, $this->user->id, $templateKey, $recipient, $requirement);

        $this->app->call([$job, 'handle']);
    }

    private function failJob(int $listingId, ?CoverLetterRequirement $requirement): void
    {
        (new TailorCoverLetter($listingId, $this->user->id, 'default', null, $requirement))
            ->failed(new RuntimeException('Worker killed.'));
    }

    private function detection(
        CoverLetterRequirement $requirement,
        CoverLetterDetectionMethod $method = CoverLetterDetectionMethod::Keyword,
    ): CoverLetterDetection {
        return new CoverLetterDetection(
            requirement: $requirement,
            method: $method,
            evidence: ['a cover letter is '.$requirement->value],
            confidence: 0.9,
        );
    }

    private function profile(): UserProfile
    {
        return UserProfile::create([
            'user_id' => $this->user->id,
            'skills' => ['primary' => ['PHP', 'Laravel'], 'secondary' => ['Vue']],
            'location' => ['city' => 'Toronto', 'state' => 'Ontario', 'country' => 'Canada'],
            'linkedin_url' => '',
            'github_url' => '',
            'portfolio_url' => '',
            'suggested_roles' => ['Backend Engineer'],
            'experience' => [['company' => 'Acme', 'position' => 'Engineer', 'duration' => '2021 - Present']],
            'education' => [],
            'parsed_keywords' => 'php laravel queues',
            'resume_text' => 'Backend engineer with three years of Laravel experience.',
        ]);
    }

    private function listing(array $overrides = []): JobListing
    {
        return JobListing::create(array_merge([
            'api_id' => '123',
            'title' => 'Senior Laravel Engineer',
            'company' => 'Acme',
            'location' => 'Toronto, Ontario, Canada',
            'description' => str_repeat('We need a Laravel engineer who cares about correctness. ', 12)
                .'Please include a cover letter with your application.',
            'api_source' => 'adzuna',
            'source_key' => 'adzuna',
            'is_active' => true,
            'parsed_skills' => [],
            'application_url' => 'https://jobs.example.com/careers/123',
            'pipeline_stage' => PipelineStage::Scored,
        ], $overrides));
    }

    /** A row the job did not create — for the `failed()` paths. */
    private function document(
        JobListing $listing,
        TailoredDocumentStatus $status,
        array $overrides = [],
    ): TailoredDocument {
        return TailoredDocument::create(array_merge([
            'user_id' => $this->user->id,
            'job_listing_id' => $listing->id,
            'type' => TailoredDocumentType::CoverLetter,
            'status' => $status,
        ], $overrides));
    }

    private function letterDocument(): ?TailoredDocument
    {
        return TailoredDocument::query()
            ->where('user_id', $this->user->id)
            ->ofType(TailoredDocumentType::CoverLetter)
            ->latest('id')
            ->first();
    }

    /** A stand-in for what the renderer leaves behind: a real local file. */
    private function compiledPdf(string $name = 'cover-letter.pdf'): string
    {
        $path = $this->tempRoot.'/'.$name;
        File::put($path, '%PDF-1.4 compiled');

        return $path;
    }

    private function letter(): TailoredCoverLetterContent
    {
        return new TailoredCoverLetterContent(
            content: [
                'name' => 'Dev Patel',
                'company' => 'Acme',
                'job_title' => 'Senior Laravel Engineer',
                'date' => 'March 3, 2026',
                'recipient_name' => null,
                'paragraphs' => [
                    'Your posting describes the queue-backed work I have spent three years on.',
                    'At Acme I shipped a pipeline that handled retries and backoff end to end.',
                    'I would welcome the chance to discuss the role.',
                ],
                'closing' => 'Sincerely,',
            ],
            facts: ['company' => 'Acme', 'jobTitle' => 'Senior Laravel Engineer', 'date' => 'March 3, 2026'],
            rawOutput: '{"paragraphs":["..."]}',
            modelUsed: 'openai/gpt-4o',
            tierUsed: 'premium',
        );
    }
}

/** Answers with one fixed verdict, without reading a description or a model. */
class StubCoverLetterDetector extends CoverLetterRequirementDetector
{
    public int $calls = 0;

    public function __construct(private readonly CoverLetterDetection $detection) {}

    public function detect(JobListing $listing): CoverLetterDetection
    {
        $this->calls++;

        return $this->detection;
    }
}

/**
 * Answers with one fixed {@see TailoredCoverLetterContent}, or throws.
 * Subclasses the real service rather than mocking it, so a signature change
 * breaks this loudly, and skips the parent constructor so the model router is
 * unreachable from here.
 */
class StubCoverLetterTailoring extends CoverLetterTailoringService
{
    public int $calls = 0;

    public ?UserProfile $profileSeen = null;

    public ?CoverLetterRecipient $recipientSeen = null;

    public function __construct(
        private readonly TailoredCoverLetterContent|\Throwable $outcome,
        private readonly string $defaultTemplateKey = 'default',
    ) {}

    public function tailor(
        JobListing $listing,
        UserProfile $profile,
        ?CoverLetterRecipient $recipient = null,
    ): TailoredCoverLetterContent {
        $this->calls++;
        $this->profileSeen = $profile;
        $this->recipientSeen = $recipient;

        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }

    public function defaultTemplateKey(): string
    {
        return $this->defaultTemplateKey;
    }
}

/** Renders nothing: hands back a fixed outcome, or throws. */
class StubCoverLetterRenderer extends LatexRenderService
{
    public int $calls = 0;

    public ?string $templateKeySeen = null;

    public function __construct(private readonly RenderedDocument|\Throwable $outcome) {}

    public function renderCoverLetter(string $templateKey, array $content): RenderedDocument
    {
        $this->calls++;
        $this->templateKeySeen = $templateKey;

        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
