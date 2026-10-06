<?php

namespace Tests\Feature\Jobs;

use App\Enums\CoverLetterDetectionMethod;
use App\Enums\CoverLetterRequirement;
use App\Enums\PipelineStage;
use App\Enums\RecommendedAction;
use App\Enums\TailoredDocumentStatus;
use App\Enums\TailoredDocumentType;
use App\Jobs\DiscoverJobsForUser;
use App\Jobs\EnrichJobDescription;
use App\Jobs\ScoreJobListing;
use App\Jobs\SubmitApplication;
use App\Jobs\TailorCoverLetter;
use App\Jobs\TailorResume;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\JobScore;
use App\Models\TailoredDocument;
use App\Models\User;
use App\Models\UserAutomationSetting;
use App\Models\UserProfile;
use App\Services\Enrichment\JobEnrichmentService;
use App\Services\JobSources\JobDiscoveryOrchestrator;
use App\Services\JobSources\JobDiscoveryResult;
use App\Services\JobSources\JobSearchQuery;
use App\Services\Latex\LatexRenderService;
use App\Services\Latex\RenderedDocument;
use App\Services\Scoring\FitAnalysis;
use App\Services\Scoring\JobScoringService;
use App\Services\Scoring\RatingDecision;
use App\Services\Tailoring\CompiledResume;
use App\Services\Tailoring\CoverLetterDetection;
use App\Services\Tailoring\CoverLetterRecipient;
use App\Services\Tailoring\CoverLetterRequirementDetector;
use App\Services\Tailoring\CoverLetterTailoringService;
use App\Services\Tailoring\FabricationFinding;
use App\Services\Tailoring\FabricationGuard;
use App\Services\Tailoring\FabricationReport;
use App\Services\Tailoring\ResumeTailoringPipeline;
use App\Services\Tailoring\TailoredCoverLetterContent;
use App\Services\Tailoring\TailoredResumeContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The pipeline as a *chain* (task 15.10, Requirement 11.3), rather than as five
 * stages that each work in isolation.
 *
 * Every stage already has its own test; none of them could tell you that a
 * discovered posting ends up submitted, because none of them owns more than one
 * hop. This file asserts the hops themselves:
 *
 *     Discover → Enrich → Score → Tailor(resume [+ cover letter]) → Apply
 *
 * and the two things that are only true of the chain as a whole — that a
 * posting which wants a cover letter is never submitted before the letter
 * exists, and that a tailoring run which did *not* come through the auto-apply
 * gate never submits anything at all.
 *
 * ## How it is run
 *
 * `Queue::fake()`, then each dispatched job is pulled off the fake queue and
 * handed its own `handle()` — so the links are asserted by *following* them
 * rather than by trusting a chain declaration, and a missing dispatch shows up
 * as a missing hop instead of as a passing test. The money- and engine-bound
 * collaborators are stubbed exactly as the per-stage tests stub them
 * (subclasses of the real classes, so PHP enforces the signatures), and
 * `Http::fake()` plus `Storage::fake('s3')` mean no network and no bucket: a
 * stray real call here would fail rather than quietly cost something.
 *
 * Validates: Requirements 5.2, 7.1, 9.1, 9.8, 11.3
 */
class PipelineChainTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('s3');
        // Nothing in the chain should reach the network once the collaborators
        // are stubbed. An unexpected call is an error, not a recorded response.
        Http::preventStrayRequests();
        Http::fake();

        config(['scoring.auto_apply_star_threshold' => 4]);

        $this->user = User::factory()->create();

        $this->tempRoot = sys_get_temp_dir().'/pipeline-chain-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tempRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------
     | The whole chain, one hop at a time
     | ----------------------------------------------------------------- */

    public function test_a_high_scoring_posting_walks_from_discovery_to_a_queued_application(): void
    {
        $this->profile();
        $this->settings(enabled: true, threshold: 4);

        // A posting that already ships full text: enrichment has nothing to
        // fetch, so the first hop goes straight to scoring.
        $listing = $this->listing();

        $this->runDiscovery([$listing->id]);

        // Discover → Score
        $scoring = $this->queued(ScoreJobListing::class);
        $this->assertSame($listing->id, $scoring->jobListingId);
        $this->assertSame($this->user->id, $scoring->userId);

        $this->runScoring($scoring, stars: 5);

        // Score → Tailor
        $this->assertSame(PipelineStage::Tailoring, $listing->refresh()->pipeline_stage);
        $tailoring = $this->queued(TailorResume::class);
        $this->assertSame($listing->id, $tailoring->jobListingId);
        $this->assertSame($this->user->id, $tailoring->userId);

        $this->runTailorResume($tailoring, CoverLetterRequirement::NotRequested);

        // Tailor → Apply, with no cover letter in the way.
        $this->assertSame(PipelineStage::Tailored, $listing->refresh()->pipeline_stage);
        Queue::assertNotPushed(TailorCoverLetter::class);

        $submission = $this->queued(SubmitApplication::class);
        $this->assertSame($listing->id, $submission->jobListingId);
        $this->assertSame($this->user->id, $submission->userId);

        // The documents the apply stage will attach exist and are usable.
        $this->assertTrue($this->document(TailoredDocumentType::Resume)->isUsable());
    }

    public function test_a_short_description_routes_through_enrichment_and_carries_the_user_with_it(): void
    {
        $this->profile();

        $thin = $this->listing(['description' => 'We are hiring.']);

        $this->runDiscovery([$thin->id]);

        // Discover → Enrich, with the user id attached: `job_listings` rows are
        // shared, so without it enrichment could not make the next link.
        Queue::assertNotPushed(ScoreJobListing::class);

        $enrichment = $this->queued(EnrichJobDescription::class);
        $this->assertSame($thin->id, $enrichment->jobListingId);
        $this->assertSame($this->user->id, $enrichment->scoreForUser);

        // Enrich → Score. The fetch itself is another stage's test; what matters
        // here is that a scoreable description hands the row on.
        $thin->update(['description' => $this->fullDescription()]);
        $this->app->call([$enrichment, 'handle']);

        $scoring = $this->queued(ScoreJobListing::class);
        $this->assertSame($thin->id, $scoring->jobListingId);
        $this->assertSame($this->user->id, $scoring->userId);
    }

    public function test_enrichment_dispatched_without_a_user_scores_nothing(): void
    {
        $this->profile();

        $listing = $this->listing();

        // A backfill or an operator re-running a bad scrape: there is no
        // pipeline behind it, so there is nothing to hand the row to.
        $this->app->call([new EnrichJobDescription($listing->id), 'handle']);

        Queue::assertNotPushed(ScoreJobListing::class);
    }

    /* ------------------------------------------------------------------
     | Cover-letter ordering (Requirement 7.1)
     | ----------------------------------------------------------------- */

    public function test_a_posting_that_wants_a_cover_letter_is_only_submitted_once_both_documents_exist(): void
    {
        $this->profile();
        $this->settings(enabled: true, threshold: 4);

        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailoring]);
        $this->score(5);

        $this->runTailorResume(
            new TailorResume($listing->id, $this->user->id),
            CoverLetterRequirement::Required,
        );

        // The resume is stored and the listing is `tailored`, but the
        // application must NOT be queued yet: it would go out without the
        // letter the posting asked for.
        $this->assertSame(PipelineStage::Tailored, $listing->refresh()->pipeline_stage);
        $this->assertTrue($this->document(TailoredDocumentType::Resume)->isUsable());
        Queue::assertNotPushed(SubmitApplication::class);

        $letterJob = $this->queued(TailorCoverLetter::class);
        $this->assertSame($listing->id, $letterJob->jobListingId);
        $this->assertSame(CoverLetterRequirement::Required, $letterJob->requirement);

        $this->runTailorCoverLetter($letterJob, CoverLetterRequirement::Required);

        // Both documents are stored against one application row, and only now
        // is the submission queued — exactly once.
        $this->assertTrue($this->document(TailoredDocumentType::CoverLetter)->isUsable());
        $this->assertSame(1, Application::query()->count());
        Queue::assertPushed(SubmitApplication::class, 1);
    }

    public function test_a_failed_mandatory_letter_blocks_the_submission(): void
    {
        $this->profile();
        $this->settings(enabled: true, threshold: 4);

        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailored]);
        $this->score(5);
        $this->renderedResume($listing);

        $this->runTailorCoverLetter(
            new TailorCoverLetter($listing->id, $this->user->id, 'default', null, CoverLetterRequirement::Required),
            CoverLetterRequirement::Required,
            renderer: new ChainStubRenderer(RenderedDocument::failed('\documentclass{letter}', 'Undefined control sequence.')),
        );

        // The posting mandated a letter and there is none: a person has to
        // finish this, so the chain stops here.
        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);
        Queue::assertNotPushed(SubmitApplication::class);
    }

    public function test_a_failed_optional_letter_still_submits_the_resume_alone(): void
    {
        $this->profile();
        $this->settings(enabled: true, threshold: 4);

        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailored]);
        $this->score(5);
        $this->renderedResume($listing);

        $this->runTailorCoverLetter(
            new TailorCoverLetter($listing->id, $this->user->id, 'default', null, CoverLetterRequirement::Optional),
            CoverLetterRequirement::Optional,
            renderer: new ChainStubRenderer(RenderedDocument::failed('\documentclass{letter}', 'Undefined control sequence.')),
        );

        // Nothing mandated the letter, so the application is still complete.
        $this->assertSame(PipelineStage::Tailored, $listing->refresh()->pipeline_stage);
        Queue::assertPushed(SubmitApplication::class, 1);
    }

    public function test_a_flagged_resume_hands_nothing_on(): void
    {
        $this->profile();
        $this->settings(enabled: true, threshold: 4);

        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailoring]);
        $this->score(5);

        $report = new FabricationReport(
            findings: [FabricationFinding::unknownEmployer(
                'Globex Inc',
                'bullet 1 of role 1 (Acme)',
                'The bullet names "Globex Inc", which appears nowhere in the profile.',
            )],
            datesVerifiable: true,
            texScanned: true,
        );

        $this->runTailorResume(
            new TailorResume($listing->id, $this->user->id),
            CoverLetterRequirement::Required,
            report: $report,
        );

        // An unsupported claim is a review item, so neither a cover letter nor
        // an application is worth queueing for it.
        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);
        Queue::assertNotPushed(TailorCoverLetter::class);
        Queue::assertNotPushed(SubmitApplication::class);
    }

    /* ------------------------------------------------------------------
     | The auto-apply gate (Requirements 5.2, 9.8)
     | ----------------------------------------------------------------- */

    public function test_a_manual_tailor_of_a_store_only_posting_never_submits(): void
    {
        $this->profile();
        $this->settings(enabled: true, threshold: 4);

        // Requirement 9.8: the user asked for documents for a posting their own
        // threshold rejected. Tailoring runs; submitting must not.
        $listing = $this->listing(['pipeline_stage' => PipelineStage::StoreOnly]);
        $this->score(2);

        $this->runTailorResume(
            new TailorResume($listing->id, $this->user->id),
            CoverLetterRequirement::NotRequested,
        );

        $this->assertTrue($this->document(TailoredDocumentType::Resume)->isUsable());
        $this->assertSame(PipelineStage::Tailored, $listing->refresh()->pipeline_stage);
        Queue::assertNotPushed(SubmitApplication::class);
    }

    public function test_a_store_only_posting_does_not_submit_by_way_of_its_cover_letter_either(): void
    {
        $this->profile();
        $this->settings(enabled: true, threshold: 4);

        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailored]);
        $this->score(2);
        $this->renderedResume($listing);

        // The gate lives in one place, so the letter job's hand-off is refused
        // for the same reason the resume job's would have been.
        $this->runTailorCoverLetter(
            new TailorCoverLetter($listing->id, $this->user->id, 'default', null, CoverLetterRequirement::Required),
            CoverLetterRequirement::Required,
        );

        $this->assertTrue($this->document(TailoredDocumentType::CoverLetter)->isUsable());
        Queue::assertNotPushed(SubmitApplication::class);
    }

    public function test_auto_apply_switched_off_never_submits_even_a_perfect_fit(): void
    {
        $this->profile();
        $this->settings(enabled: false, threshold: 4);

        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailoring]);
        $this->score(5);

        $this->runTailorResume(
            new TailorResume($listing->id, $this->user->id),
            CoverLetterRequirement::NotRequested,
        );

        Queue::assertNotPushed(SubmitApplication::class);
    }

    public function test_a_hand_tailored_posting_with_no_score_never_submits(): void
    {
        $this->profile();
        $this->settings(enabled: true, threshold: 4);

        // No `job_scores` row at all: nothing ever judged this posting, so no
        // automated submission can be attributed to a verdict.
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailoring]);

        $this->runTailorResume(
            new TailorResume($listing->id, $this->user->id),
            CoverLetterRequirement::NotRequested,
        );

        $this->assertSame(PipelineStage::Tailored, $listing->refresh()->pipeline_stage);
        Queue::assertNotPushed(SubmitApplication::class);
    }

    public function test_scoring_below_the_threshold_hands_nothing_to_tailoring(): void
    {
        $this->profile();
        $this->settings(enabled: true, threshold: 4);

        $listing = $this->listing();

        $this->runScoring(new ScoreJobListing($listing->id, $this->user->id), stars: 3);

        $this->assertSame(PipelineStage::StoreOnly, $listing->refresh()->pipeline_stage);
        Queue::assertNotPushed(TailorResume::class);
        Queue::assertNotPushed(SubmitApplication::class);
    }

    /* ------------------------------------------------------------------
     | Fixtures
     | ----------------------------------------------------------------- */

    /** The newest job of `$class` on the fake queue. Fails the test if there is none. */
    private function queued(string $class): object
    {
        $job = Queue::pushed($class)->last();

        $this->assertNotNull($job, $class.' was never dispatched: the chain is broken at this hop.');

        return $job;
    }

    /** @param list<int> $touchedIds */
    private function runDiscovery(array $touchedIds): void
    {
        $orchestrator = new ChainStubOrchestrator();
        $orchestrator->createdIds = $touchedIds;

        $this->app->instance(JobDiscoveryOrchestrator::class, $orchestrator);

        $job = new DiscoverJobsForUser($this->user->id, roles: ['Backend Engineer'], location: 'Toronto');

        $this->app->call([$job, 'handle']);
    }

    private function runScoring(ScoreJobListing $job, int $stars): void
    {
        $this->app->instance(JobScoringService::class, new ChainStubScoring(
            new FitAnalysis(
                requiredSkills: ['PHP', 'Laravel'],
                seniority: 'senior',
                matchedSkills: ['PHP', 'Laravel'],
                missingSkills: [],
                gapSummary: 'Strong Laravel background.',
                rawOutput: '{"requiredSkills":["PHP","Laravel"]}',
                modelUsed: 'openai/gpt-4o-mini',
                tierUsed: 'standard',
            ),
            new RatingDecision(
                stars: $stars,
                rationale: 'Matches the core stack.',
                recommendedAction: $stars >= 4 ? RecommendedAction::AutoApply : RecommendedAction::StoreOnly,
                rawOutput: '{"stars":'.$stars.'}',
                modelUsed: 'openai/gpt-4o',
                tierUsed: 'premium',
            ),
        ));

        $this->app->call([$job, 'handle']);
    }

    private function runTailorResume(
        TailorResume $job,
        CoverLetterRequirement $requirement,
        ?FabricationReport $report = null,
    ): void {
        $this->app->instance(ResumeTailoringPipeline::class, new ChainStubPipeline($this->compiledResume()));
        $this->app->instance(FabricationGuard::class, new ChainStubGuard(
            $report ?? new FabricationReport(texScanned: true)
        ));
        $this->app->instance(
            CoverLetterRequirementDetector::class,
            new ChainStubDetector($this->detection($requirement)),
        );

        $this->app->call([$job, 'handle']);
    }

    private function runTailorCoverLetter(
        TailorCoverLetter $job,
        CoverLetterRequirement $requirement,
        ?ChainStubRenderer $renderer = null,
    ): void {
        $this->app->instance(
            CoverLetterRequirementDetector::class,
            new ChainStubDetector($this->detection($requirement)),
        );
        $this->app->instance(CoverLetterTailoringService::class, new ChainStubCoverLetterTailoring($this->letter()));
        $this->app->instance(LatexRenderService::class, $renderer ?? new ChainStubRenderer(
            RenderedDocument::compiled('\documentclass{letter}', $this->compiledPdf('cover-letter.pdf'))
        ));

        $this->app->call([$job, 'handle']);
    }

    private function detection(CoverLetterRequirement $requirement): CoverLetterDetection
    {
        return new CoverLetterDetection(
            requirement: $requirement,
            method: CoverLetterDetectionMethod::Keyword,
            evidence: ['a cover letter is '.$requirement->value],
            confidence: 0.9,
        );
    }

    private function settings(bool $enabled, int $threshold = 4): void
    {
        UserAutomationSetting::create([
            'auto_apply_enabled' => $enabled,
            'auto_apply_star_threshold' => $threshold,
        ] + UserAutomationSetting::defaultsFor($this->user->id));
    }

    private function score(int $stars): JobScore
    {
        return JobScore::createNextAttempt(
            (int) JobListing::query()->latest('id')->first()->id,
            $this->user->id,
            [
                'fit_analysis' => ['requiredSkills' => ['PHP']],
                'stars' => $stars,
                'rationale' => 'Fixture.',
                'recommended_action' => $stars >= 4 ? RecommendedAction::AutoApply : RecommendedAction::StoreOnly,
                'raw_prompt_1_output' => '{}',
                'raw_prompt_2_output' => '{"stars":'.$stars.'}',
            ],
        );
    }

    /** A resume the chain did not produce — for the letter-first paths. */
    private function renderedResume(JobListing $listing): TailoredDocument
    {
        $document = TailoredDocument::create([
            'user_id' => $this->user->id,
            'job_listing_id' => $listing->id,
            'type' => TailoredDocumentType::Resume,
            'status' => TailoredDocumentStatus::Rendered,
        ]);

        $document->update([
            's3_path' => 'users/'.$this->user->id.'/tailored-documents/'.$document->getKey().'/resume.pdf',
        ]);

        return $document;
    }

    private function document(TailoredDocumentType $type): TailoredDocument
    {
        $document = TailoredDocument::query()
            ->where('user_id', $this->user->id)
            ->ofType($type)
            ->latest('id')
            ->first();

        $this->assertNotNull($document, 'no '.$type->value.' document was stored');

        return $document;
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
            'description' => $this->fullDescription(),
            'api_source' => 'adzuna',
            'source_key' => 'adzuna',
            'is_active' => true,
            'parsed_skills' => [],
            'application_url' => 'https://jobs.example.com/careers/123',
            'pipeline_stage' => PipelineStage::Discovered,
        ], $overrides));
    }

    private function fullDescription(): string
    {
        return str_repeat('We need a Laravel engineer who cares about correctness. ', 12);
    }

    private function compiledPdf(string $name): string
    {
        $path = $this->tempRoot.'/'.$name;
        File::put($path, '%PDF-1.4 compiled');

        return $path;
    }

    private function compiledResume(): CompiledResume
    {
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
            document: RenderedDocument::compiled('\documentclass{article}', $this->compiledPdf('resume.pdf')),
            templateKey: 'modern',
        );
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

/** Reports the rows the test says were touched, without calling a provider. */
class ChainStubOrchestrator extends JobDiscoveryOrchestrator
{
    /** @var list<int> */
    public array $createdIds = [];

    public function __construct()
    {
        // No provider, no rate-limit counter.
    }

    public function discover(JobSearchQuery $query): JobDiscoveryResult
    {
        $result = new JobDiscoveryResult();

        foreach ($this->createdIds as $id) {
            $result->recordCreated('adzuna', $id);
        }

        return $result;
    }
}

/** Answers both scoring prompts from fixtures. */
class ChainStubScoring extends JobScoringService
{
    public function __construct(
        private readonly FitAnalysis $analysis,
        private readonly RatingDecision $decision,
    ) {
        // The router must be unreachable from here.
    }

    public function promptFitAnalysis(JobListing $listing, UserProfile $profile): FitAnalysis
    {
        return $this->analysis;
    }

    public function promptRatingDecision(JobListing $listing, FitAnalysis $analysis): RatingDecision
    {
        return $this->decision;
    }
}

/** Hands back one fixed compiled resume. */
class ChainStubPipeline extends ResumeTailoringPipeline
{
    public function __construct(private readonly CompiledResume $compiled) {}

    public function tailorAndCompile(
        JobListing $listing,
        UserProfile $profile,
        ?string $templateKey = null,
    ): CompiledResume {
        return $this->compiled;
    }
}

/** Reports whatever the test decided, without scanning anything. */
class ChainStubGuard extends FabricationGuard
{
    public function __construct(private readonly FabricationReport $report) {}

    public function inspectCompiled(CompiledResume $compiled, ?UserProfile $profile = null): FabricationReport
    {
        return $this->report;
    }
}

/** One fixed cover-letter verdict, with no description read and no model call. */
class ChainStubDetector extends CoverLetterRequirementDetector
{
    public function __construct(private readonly CoverLetterDetection $detection) {}

    public function detect(JobListing $listing): CoverLetterDetection
    {
        return $this->detection;
    }
}

/** One fixed letter. */
class ChainStubCoverLetterTailoring extends CoverLetterTailoringService
{
    public function __construct(private readonly TailoredCoverLetterContent $letter) {}

    public function tailor(
        JobListing $listing,
        UserProfile $profile,
        ?CoverLetterRecipient $recipient = null,
    ): TailoredCoverLetterContent {
        return $this->letter;
    }

    public function defaultTemplateKey(): string
    {
        return 'default';
    }
}

/** Renders nothing: hands back one fixed outcome. */
class ChainStubRenderer extends LatexRenderService
{
    public function __construct(private readonly RenderedDocument $outcome) {}

    public function renderCoverLetter(string $templateKey, array $content): RenderedDocument
    {
        return $this->outcome;
    }
}
