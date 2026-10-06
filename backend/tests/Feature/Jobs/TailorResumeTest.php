<?php

namespace Tests\Feature\Jobs;

use App\Enums\PipelineStage;
use App\Enums\TailoredDocumentStatus;
use App\Enums\TailoredDocumentType;
use App\Exceptions\LatexEngineException;
use App\Exceptions\ResumeCompilationException;
use App\Exceptions\ResumeTailoringException;
use App\Jobs\TailorResume;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\TailoredDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Latex\RenderedDocument;
use App\Services\Storage\TailoredDocumentStorage;
use App\Services\Tailoring\CompiledResume;
use App\Services\Tailoring\FabricationFinding;
use App\Services\Tailoring\FabricationGuard;
use App\Services\Tailoring\FabricationReport;
use App\Services\Tailoring\ResumeTailoringPipeline;
use App\Services\Tailoring\TailoredResumeContent;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The tailoring stage as a queued job (task 11.7): which row exists when, what
 * lands on S3, what gets linked to what, and which failures are the document's
 * fault rather than the host's.
 *
 * Both collaborators that cost money or need a TeX engine are replaced by
 * subclasses of the real classes — {@see StubTailoringPipeline} and
 * {@see StubGuard} — so PHP enforces their signatures while no model call, no
 * engine and no network happen here. {@see TailoredDocumentStorage} is the real
 * service over `Storage::fake('s3')`, because the keys it writes are part of
 * what this job promises (Requirements 8.1, 8.2, 8.3) and faking them away would
 * leave that promise untested.
 *
 * The stub pipeline hands back a {@see CompiledResume} whose `pdfPath()` is a
 * real file under {@see $tempRoot}: the job uploads it and then deletes it, and
 * both halves of that are asserted.
 *
 * Validates: Requirements 6.5, 6.6, 8.1, 8.2, 8.3, 8.5
 */
class TailorResumeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** Scratch root for the "compiled" PDFs handed to the job. */
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        // A clean run now hands the chain on (task 15.10), and the test queue is
        // `sync`: without this the cover-letter and apply stages would run inside
        // every tailoring assertion.
        Queue::fake();

        $this->user = User::factory()->create();

        $this->tempRoot = sys_get_temp_dir().'/tailor-resume-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tempRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------
     | Success
     | ----------------------------------------------------------------- */

    public function test_a_successful_run_stores_both_artefacts_and_records_their_provenance(): void
    {
        $profile = $this->profile();
        $listing = $this->listing();

        $pdfPath = $this->compiledPdf();
        $compiled = $this->compiled($pdfPath, texSource: '\documentclass{article}% tailored');

        $pipeline = new StubTailoringPipeline($compiled);
        $this->runJob($listing->id, $pipeline);

        $document = $this->resumeDocument();

        $this->assertNotNull($document);
        $this->assertSame(TailoredDocumentStatus::Rendered, $document->status);
        $this->assertSame(TailoredDocumentType::Resume, $document->type);
        $this->assertSame('modern', $document->template_key);
        $this->assertSame('openai/gpt-4o', $document->generation_model);
        $this->assertSame('premium', $document->generation_tier);

        // Requirements 8.2, 8.3: the keys the storage service builds, persisted
        // on the owning row as keys rather than public URLs.
        $prefix = 'users/'.$this->user->id.'/tailored-documents/'.$document->getKey();
        $this->assertSame($prefix.'/resume.pdf', $document->s3_path);
        $this->assertSame($prefix.'/resume.tex', $document->tex_source_s3_path);

        Storage::disk('s3')->assertExists($document->s3_path);
        Storage::disk('s3')->assertExists($document->tex_source_s3_path);
        $this->assertSame('%PDF-1.4 compiled', Storage::disk('s3')->get($document->s3_path));
        $this->assertSame('\documentclass{article}% tailored', Storage::disk('s3')->get($document->tex_source_s3_path));

        $this->assertSame(PipelineStage::Tailored, $listing->refresh()->pipeline_stage);
        $this->assertTrue($document->isUsable());

        // The renderer leaves the PDF with no owner once it is uploaded.
        $this->assertFileDoesNotExist($pdfPath);

        $this->assertSame(1, $pipeline->calls);
        $this->assertSame($profile->id, $pipeline->profileSeen?->id);
    }

    public function test_the_row_records_the_template_that_actually_rendered(): void
    {
        $this->profile();
        $listing = $this->listing();

        // The job is told nothing; the pipeline resolves the default and reports
        // back which template it used (Requirement 6.3).
        $compiled = $this->compiled($this->compiledPdf(), templateKey: 'compact');

        $this->runJob($listing->id, new StubTailoringPipeline($compiled), templateKey: null);

        $this->assertSame('compact', $this->resumeDocument()?->template_key);
    }

    /* ------------------------------------------------------------------
     | Linking (Requirement 6.5)
     | ----------------------------------------------------------------- */

    public function test_the_document_and_a_pending_application_are_linked_both_ways(): void
    {
        $this->profile();
        $listing = $this->listing();

        $this->runJob($listing->id, new StubTailoringPipeline($this->compiled($this->compiledPdf())));

        $document = $this->resumeDocument();
        $application = Application::query()->sole();

        $this->assertSame('pending', $application->status);
        $this->assertSame($this->user->id, (int) $application->user_id);
        $this->assertSame($listing->id, (int) $application->job_listing_id);
        $this->assertSame($document->getKey(), (int) $application->tailored_resume_id);
        $this->assertSame($application->getKey(), $document->application_id);
        $this->assertSame($document->getKey(), $application->tailoredResume->getKey());
    }

    public function test_a_second_run_repoints_the_same_application_instead_of_creating_another(): void
    {
        $this->profile();
        $listing = $this->listing();

        $this->runJob($listing->id, new StubTailoringPipeline($this->compiled($this->compiledPdf('first.pdf'))));

        $first = $this->resumeDocument();
        $application = Application::query()->sole();

        // A reviewer resolving a flag, or an operator re-triggering tailoring
        // (Requirement 9.8): `needs_review` is deliberately still open.
        $listing->update(['pipeline_stage' => PipelineStage::NeedsReview]);

        $this->runJob($listing->id, new StubTailoringPipeline($this->compiled($this->compiledPdf('second.pdf'))));

        $second = $this->resumeDocument();

        $this->assertNotSame($first->getKey(), $second->getKey(), 'a rendered row must not be overwritten');
        $this->assertSame(1, Application::query()->count(), 'the application must not be double-counted');
        $this->assertSame($second->getKey(), (int) $application->refresh()->tailored_resume_id);
        $this->assertSame($application->getKey(), $second->application_id);
    }

    /* ------------------------------------------------------------------
     | Fabrication (Requirements 6.6, 9.4)
     | ----------------------------------------------------------------- */

    public function test_a_fabrication_finding_flags_the_listing_but_still_stores_the_document(): void
    {
        $this->profile();
        $listing = $this->listing();

        $report = new FabricationReport(
            findings: [FabricationFinding::unknownEmployer(
                'Globex Inc',
                'bullet 1 of role 1 (Acme)',
                'The bullet names "Globex Inc", which appears nowhere in the profile.',
            )],
            datesVerifiable: true,
            texScanned: true,
        );

        $this->runJob(
            $listing->id,
            new StubTailoringPipeline($this->compiled($this->compiledPdf())),
            guard: new StubGuard($report),
        );

        $document = $this->resumeDocument();

        // The PDF exists and the row is finished — only the listing changes, so
        // nothing dispatches a submission for it.
        $this->assertSame(TailoredDocumentStatus::Rendered, $document->status);
        Storage::disk('s3')->assertExists($document->s3_path);
        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);

        // The structured findings are persisted verbatim, metadata included.
        $this->assertSame($report->flags(), $document->fabrication_flags);
    }

    public function test_a_clean_report_is_still_recorded_on_the_row(): void
    {
        $this->profile();
        $listing = $this->listing();

        $report = new FabricationReport(datesVerifiable: false, texScanned: true);

        $this->runJob(
            $listing->id,
            new StubTailoringPipeline($this->compiled($this->compiledPdf())),
            guard: new StubGuard($report),
        );

        $this->assertSame(PipelineStage::Tailored, $listing->refresh()->pipeline_stage);
        $this->assertSame($report->flags(), $this->resumeDocument()?->fabrication_flags);
    }

    /* ------------------------------------------------------------------
     | Content failures: swallowed, converted to a review item
     | ----------------------------------------------------------------- */

    public function test_a_spent_tailoring_budget_fails_the_document_and_flags_the_listing(): void
    {
        $this->profile();
        $listing = $this->listing();

        $pipeline = new StubTailoringPipeline(ResumeTailoringException::promptFailed(
            'resume_tailoring',
            [['attempt' => 2, 'parse_failure' => true, 'reason' => 'Returned prose, not JSON.']],
        ));

        $this->runJob($listing->id, $pipeline);

        $document = $this->resumeDocument();

        $this->assertSame(TailoredDocumentStatus::Failed, $document->status);
        $this->assertSame('', $document->s3_path);
        $this->assertFalse($document->isUsable());
        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $this->assertSame(0, Application::query()->count());
    }

    public function test_a_document_that_will_not_typeset_fails_the_document_and_flags_the_listing(): void
    {
        $this->profile();
        $listing = $this->listing();

        $pipeline = new StubTailoringPipeline(ResumeCompilationException::retriesExhausted('modern', [
            ['attempt' => 1, 'compile_error' => 'Overfull \hbox', 'exit_code' => 1, 'working_directory' => '/tmp/a'],
            ['attempt' => 2, 'compile_error' => 'Overfull \hbox', 'exit_code' => 1, 'working_directory' => '/tmp/b'],
        ]));

        $this->runJob($listing->id, $pipeline);

        $this->assertSame(TailoredDocumentStatus::Failed, $this->resumeDocument()?->status);
        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    /* ------------------------------------------------------------------
     | Environment faults: propagated, row left in flight
     | ----------------------------------------------------------------- */

    public function test_a_missing_tex_engine_fails_the_job_and_leaves_the_row_pending(): void
    {
        $this->profile();
        $listing = $this->listing();

        $pipeline = new StubTailoringPipeline(LatexEngineException::notFound('tectonic'));

        try {
            $this->runJob($listing->id, $pipeline);
            $this->fail('Expected the engine fault to fail the job rather than the document.');
        } catch (LatexEngineException $e) {
            $this->assertSame('tectonic', $e->engine);
        }

        // `pending` because a retry is still coming and will reuse this row.
        $document = $this->resumeDocument();
        $this->assertSame(TailoredDocumentStatus::Pending, $document->status);
        $this->assertSame('', $document->s3_path);
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $this->assertSame(PipelineStage::Tailoring, $listing->refresh()->pipeline_stage);
    }

    /** Requirement 8.5: a failed upload must not mark the step complete. */
    public function test_a_failed_upload_leaves_the_row_pending_rather_than_rendered(): void
    {
        $this->profile();
        $listing = $this->listing();

        // A working fake disk cannot refuse a write, so the disk is swapped for
        // one that reports the `false` the s3 driver returns on failure.
        //
        // `times(3)` rather than `once()`: the write is retried in place first
        // (App\Services\Storage\StorageWriteRetrier, Req 8.5), so a disk that
        // refuses every attempt is asked three times before the job is allowed
        // to fail. The count is the configured cap, and pinning it here is the
        // proof that the in-process retry happens *below* the queue retry
        // instead of replacing it — the job still ends up throwing, and the
        // assertions below are still about the row never reaching `rendered`.
        config(['filesystems.upload_retry.attempts' => 3]);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->times(3)->andReturn(false);
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

        try {
            $this->runJob($listing->id, new StubTailoringPipeline($this->compiled($this->compiledPdf())));
            $this->fail('Expected the storage failure to fail the job.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to store', $e->getMessage());
        }

        $document = $this->resumeDocument();
        $this->assertSame(TailoredDocumentStatus::Pending, $document->status);
        $this->assertSame('', $document->s3_path);
        $this->assertNull($document->tex_source_s3_path);
        $this->assertSame(0, Application::query()->count());
    }

    /* ------------------------------------------------------------------
     | No-ops
     | ----------------------------------------------------------------- */

    public function test_a_deleted_listing_is_a_no_op(): void
    {
        $this->profile();

        $pipeline = new StubTailoringPipeline($this->compiled($this->compiledPdf()));
        $this->runJob(999999, $pipeline);

        $this->assertSame(0, $pipeline->calls);
        $this->assertSame(0, TailoredDocument::query()->count());
    }

    public function test_a_user_without_a_parsed_profile_is_a_no_op(): void
    {
        $listing = $this->listing();

        $pipeline = new StubTailoringPipeline($this->compiled($this->compiledPdf()));
        $this->runJob($listing->id, $pipeline);

        $this->assertSame(0, $pipeline->calls);
        $this->assertSame(0, TailoredDocument::query()->count());

        // The profile is checked before the stage is moved, so a listing with
        // nothing to tailor from is left exactly where it was.
        $this->assertSame(PipelineStage::Scored, $listing->refresh()->pipeline_stage);
    }

    public function test_a_submitted_application_is_never_dragged_back_to_tailoring(): void
    {
        $this->profile();
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Applied]);

        $pipeline = new StubTailoringPipeline($this->compiled($this->compiledPdf()));
        $this->runJob($listing->id, $pipeline);

        $this->assertSame(0, $pipeline->calls);
        $this->assertSame(PipelineStage::Applied, $listing->refresh()->pipeline_stage);
        $this->assertSame(0, TailoredDocument::query()->count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_a_listing_that_already_has_its_documents_is_not_tailored_again(): void
    {
        $this->profile();
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailored]);

        $pipeline = new StubTailoringPipeline($this->compiled($this->compiledPdf()));
        $this->runJob($listing->id, $pipeline);

        $this->assertSame(0, $pipeline->calls);
        $this->assertSame(PipelineStage::Tailored, $listing->refresh()->pipeline_stage);
    }

    /* ------------------------------------------------------------------
     | Retries and permanent failure
     | ----------------------------------------------------------------- */

    public function test_a_retry_reuses_the_pending_row_from_the_dead_attempt(): void
    {
        $this->profile();
        $listing = $this->listing();

        try {
            $this->runJob($listing->id, new StubTailoringPipeline(LatexEngineException::notFound('tectonic')));
        } catch (LatexEngineException) {
            // The attempt the queue will retry.
        }

        $pending = $this->resumeDocument();
        $this->assertSame(TailoredDocumentStatus::Pending, $pending->status);

        $this->runJob($listing->id, new StubTailoringPipeline($this->compiled($this->compiledPdf())));

        $this->assertSame(1, TailoredDocument::query()->count(), 'a retry must not leave a second pending row');
        $this->assertSame($pending->getKey(), $this->resumeDocument()->getKey());
        $this->assertSame(TailoredDocumentStatus::Rendered, $this->resumeDocument()->status);
    }

    public function test_exhausted_retries_close_the_pending_row_and_fail_the_listing(): void
    {
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailoring]);
        $document = $this->document($listing, TailoredDocumentStatus::Pending);

        (new TailorResume($listing->id, $this->user->id))->failed(new RuntimeException('Worker killed.'));

        $this->assertSame(TailoredDocumentStatus::Failed, $document->refresh()->status);
        $this->assertSame(PipelineStage::Failed, $listing->refresh()->pipeline_stage);
    }

    public function test_a_permanent_failure_leaves_a_rendered_document_and_a_submitted_listing_alone(): void
    {
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Applied]);
        $document = $this->document($listing, TailoredDocumentStatus::Rendered, [
            's3_path' => 'users/'.$this->user->id.'/tailored-documents/1/resume.pdf',
        ]);

        (new TailorResume($listing->id, $this->user->id))->failed(new RuntimeException('Worker killed.'));

        $this->assertSame(TailoredDocumentStatus::Rendered, $document->refresh()->status);
        $this->assertTrue($document->isUsable());
        $this->assertSame(PipelineStage::Applied, $listing->refresh()->pipeline_stage);
    }

    /* ------------------------------------------------------------------
     | Fixtures
     | ----------------------------------------------------------------- */

    private function runJob(
        int $listingId,
        StubTailoringPipeline $pipeline,
        ?StubGuard $guard = null,
        ?string $templateKey = 'modern',
    ): void {
        $this->app->instance(ResumeTailoringPipeline::class, $pipeline);
        $this->app->instance(FabricationGuard::class, $guard ?? new StubGuard(new FabricationReport(texScanned: true)));

        $job = new TailorResume($listingId, $this->user->id, $templateKey);

        $this->app->call([$job, 'handle']);
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
            'description' => str_repeat('We need a Laravel engineer who cares about correctness. ', 12),
            'api_source' => 'adzuna',
            'source_key' => 'adzuna',
            'is_active' => true,
            'parsed_skills' => [],
            'application_url' => 'https://jobs.example.com/careers/123',
            'pipeline_stage' => PipelineStage::Scored,
        ], $overrides));
    }

    /** A row the job did not create — for the `failed()` paths. */
    private function document(JobListing $listing, TailoredDocumentStatus $status, array $overrides = []): TailoredDocument
    {
        return TailoredDocument::create(array_merge([
            'user_id' => $this->user->id,
            'job_listing_id' => $listing->id,
            'type' => TailoredDocumentType::Resume,
            'status' => $status,
        ], $overrides));
    }

    private function resumeDocument(): ?TailoredDocument
    {
        return TailoredDocument::query()
            ->where('user_id', $this->user->id)
            ->ofType(TailoredDocumentType::Resume)
            ->latest('id')
            ->first();
    }

    /** A stand-in for what the renderer leaves behind: a real local file. */
    private function compiledPdf(string $name = 'resume.pdf'): string
    {
        $path = $this->tempRoot.'/'.$name;
        File::put($path, '%PDF-1.4 compiled');

        return $path;
    }

    private function compiled(
        string $pdfPath,
        string $texSource = '\documentclass{article}',
        string $templateKey = 'modern',
    ): CompiledResume {
        return new CompiledResume(
            tailored: new TailoredResumeContent(
                content: [
                    'summary' => 'Backend engineer with three years of Laravel experience.',
                    'headline' => 'Senior Laravel Engineer',
                    'experience' => [[
                        'company' => 'Acme',
                        'position' => 'Engineer',
                        'dates' => '2021 - Present',
                        'bullets' => ['Shipped a queue-backed pipeline.'],
                    ]],
                ],
                facts: [['company' => 'Acme', 'position' => 'Engineer', 'dates' => '2021 - Present']],
                droppedSkills: [],
                rawOutput: '{"summary":"..."}',
                modelUsed: 'openai/gpt-4o',
                tierUsed: 'premium',
            ),
            document: RenderedDocument::compiled($texSource, $pdfPath),
            templateKey: $templateKey,
        );
    }
}

/**
 * Answers with one fixed {@see CompiledResume}, or throws. Subclasses the real
 * pipeline rather than mocking it, so a signature change breaks this loudly, and
 * skips the parent constructor so neither the model router nor the TeX engine is
 * reachable from here.
 */
class StubTailoringPipeline extends ResumeTailoringPipeline
{
    public int $calls = 0;

    public ?UserProfile $profileSeen = null;

    public ?string $templateKeySeen = null;

    public function __construct(private readonly CompiledResume|\Throwable $outcome) {}

    public function tailorAndCompile(
        JobListing $listing,
        UserProfile $profile,
        ?string $templateKey = null,
    ): CompiledResume {
        $this->calls++;
        $this->profileSeen = $profile;
        $this->templateKeySeen = $templateKey;

        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

/** Reports whatever the test decided, without scanning anything. */
class StubGuard extends FabricationGuard
{
    public int $calls = 0;

    public function __construct(private readonly FabricationReport $report) {}

    public function inspectCompiled(CompiledResume $compiled, ?UserProfile $profile = null): FabricationReport
    {
        $this->calls++;

        return $this->report;
    }
}
