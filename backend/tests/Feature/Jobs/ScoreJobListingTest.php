<?php

namespace Tests\Feature\Jobs;

use App\Enums\PipelineStage;
use App\Enums\RecommendedAction;
use App\Exceptions\JobScoringException;
use App\Jobs\ScoreJobListing;
use App\Models\JobListing;
use App\Models\JobScore;
use App\Models\User;
use App\Models\UserAutomationSetting;
use App\Models\UserProfile;
use App\Services\Scoring\FitAnalysis;
use App\Services\Scoring\JobScoringService;
use App\Services\Scoring\RatingDecision;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The scoring stage as a queued job (task 10.3): what it persists, and how a
 * rating plus a user's automation settings decide `pipeline_stage`.
 *
 * The scoring service is replaced by a stub returning fixed value objects, so no
 * model calls, HTTP or prompt parsing happen here — those are covered by
 * JobScoringServiceTest. Under test is only what the job itself decides.
 *
 * Validates: Requirements 5.2, 5.3
 */
class ScoreJobListingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scoring.auto_apply_star_threshold' => 4]);

        // The stage now dispatches TailorResume (task 15.10). The queue is
        // `sync` under test, so without this the whole tailoring stage would run
        // inside every scoring assertion.
        Queue::fake();

        $this->user = User::factory()->create();
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
            'experience' => [['company' => 'Acme', 'position' => 'Engineer', 'duration' => '3 years']],
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
            'pipeline_stage' => PipelineStage::Enriching,
        ], $overrides));
    }

    private function settings(bool $enabled, int $threshold = 4): void
    {
        UserAutomationSetting::create([
            'auto_apply_enabled' => $enabled,
            'auto_apply_star_threshold' => $threshold,
        ] + UserAutomationSetting::defaultsFor($this->user->id));
    }

    private function analysis(): FitAnalysis
    {
        return new FitAnalysis(
            requiredSkills: ['PHP', 'Laravel', 'Kubernetes'],
            seniority: 'senior',
            matchedSkills: ['PHP', 'Laravel'],
            missingSkills: ['Kubernetes'],
            gapSummary: 'Strong Laravel background; no container orchestration experience.',
            rawOutput: '{"requiredSkills":["PHP","Laravel","Kubernetes"]}',
            modelUsed: 'openai/gpt-4o-mini',
            tierUsed: 'standard',
        );
    }

    private function decision(int $stars, RecommendedAction $action): RatingDecision
    {
        return new RatingDecision(
            stars: $stars,
            rationale: 'Matches the core stack, missing Kubernetes.',
            recommendedAction: $action,
            rawOutput: '{"stars":'.$stars.'}',
            modelUsed: 'openai/gpt-4o',
            tierUsed: 'premium',
        );
    }

    private function runJob(StubScoring $stub, ?int $listingId = null): void
    {
        $this->runScoring(new ScoreJobListing($listingId ?? 0, $this->user->id), $stub);
    }

    /** Run a specific job instance — used for the explicit re-score path. */
    private function runScoring(ScoreJobListing $job, StubScoring $stub): void
    {
        $this->app->instance(JobScoringService::class, $stub);

        $this->app->call([$job, 'handle']);
    }

    public function test_a_score_is_persisted_with_both_prompts_output(): void
    {
        $this->profile();
        $listing = $this->listing();
        $this->settings(enabled: false);

        $analysis = $this->analysis();
        $decision = $this->decision(5, RecommendedAction::AutoApply);

        $this->runJob(new StubScoring($analysis, $decision), $listing->id);

        $score = JobScore::latestAttempt($listing->id, $this->user->id);

        $this->assertNotNull($score);
        $this->assertSame(1, $score->attempt_number);
        $this->assertSame(5, $score->stars);
        $this->assertSame($decision->rationale, $score->rationale);
        $this->assertSame(RecommendedAction::AutoApply, $score->recommended_action);
        $this->assertSame($analysis->toArray(), $score->fit_analysis);
        $this->assertSame($analysis->rawOutput, $score->raw_prompt_1_output);
        $this->assertSame($decision->rawOutput, $score->raw_prompt_2_output);
    }

    public function test_a_rating_at_the_threshold_with_auto_apply_on_routes_to_tailoring(): void
    {
        $this->profile();
        $listing = $this->listing();
        $this->settings(enabled: true, threshold: 4);

        $this->runJob(new StubScoring($this->analysis(), $this->decision(4, RecommendedAction::AutoApply)), $listing->id);

        $this->assertSame(PipelineStage::Tailoring, $listing->refresh()->pipeline_stage);
    }

    public function test_a_rating_below_the_threshold_is_stored_only(): void
    {
        $this->profile();
        $listing = $this->listing();
        $this->settings(enabled: true, threshold: 4);

        $this->runJob(new StubScoring($this->analysis(), $this->decision(3, RecommendedAction::StoreOnly)), $listing->id);

        $this->assertSame(PipelineStage::StoreOnly, $listing->refresh()->pipeline_stage);
    }

    public function test_auto_apply_disabled_keeps_even_a_perfect_fit_stored_only(): void
    {
        $this->profile();
        $listing = $this->listing();
        $this->settings(enabled: false, threshold: 4);

        $this->runJob(new StubScoring($this->analysis(), $this->decision(5, RecommendedAction::AutoApply)), $listing->id);

        // Requirement 5.2: the rating clearing the bar is not enough on its own.
        $this->assertSame(PipelineStage::StoreOnly, $listing->refresh()->pipeline_stage);
    }

    public function test_a_user_with_no_settings_row_gets_the_conservative_branch(): void
    {
        $this->profile();
        $listing = $this->listing();

        $this->runJob(new StubScoring($this->analysis(), $this->decision(5, RecommendedAction::AutoApply)), $listing->id);

        $this->assertSame(PipelineStage::StoreOnly, $listing->refresh()->pipeline_stage);
    }

    public function test_the_users_own_threshold_overrides_the_platform_default(): void
    {
        $this->profile();
        $listing = $this->listing();
        $this->settings(enabled: true, threshold: 3);

        $this->runJob(new StubScoring($this->analysis(), $this->decision(3, RecommendedAction::StoreOnly)), $listing->id);

        // A 3-star fit clears this user's bar even though the model suggested
        // store_only and the platform default would have refused it.
        $this->assertSame(PipelineStage::Tailoring, $listing->refresh()->pipeline_stage);
    }

    public function test_the_models_recommendation_never_bypasses_the_threshold(): void
    {
        $this->profile();
        $listing = $this->listing();
        $this->settings(enabled: true, threshold: 4);

        $this->runJob(new StubScoring($this->analysis(), $this->decision(2, RecommendedAction::AutoApply)), $listing->id);

        $this->assertSame(PipelineStage::StoreOnly, $listing->refresh()->pipeline_stage);
    }

    public function test_a_scoring_failure_flags_the_listing_for_review_and_persists_no_score(): void
    {
        $this->profile();
        $listing = $this->listing();
        $this->settings(enabled: true);

        $stub = new StubScoring($this->analysis(), $this->decision(5, RecommendedAction::AutoApply));
        $stub->failure = JobScoringException::promptFailed(
            JobScoringException::PROMPT_RATING_DECISION,
            'jd_rating',
            [['attempt' => 2, 'parse_failure' => true, 'reason' => 'Returned prose, not JSON.']],
        );

        $this->runJob($stub, $listing->id);

        // Requirement 5.5: flagged for a human, never silently dropped.
        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);
        $this->assertNull(JobScore::latestAttempt($listing->id, $this->user->id));
    }

    public function test_re_scoring_versions_the_new_score_alongside_the_old_one(): void
    {
        $this->profile();
        $listing = $this->listing();
        $this->settings(enabled: false);

        $this->runJob(new StubScoring($this->analysis(), $this->decision(2, RecommendedAction::StoreOnly)), $listing->id);
        $this->runJob(new StubScoring($this->analysis(), $this->decision(4, RecommendedAction::AutoApply)), $listing->id);

        $scores = JobScore::query()->forPair($listing->id, $this->user->id)->get();

        $this->assertCount(2, $scores);
        $this->assertSame([2, 1], $scores->pluck('attempt_number')->all());
        $this->assertSame([4, 2], $scores->pluck('stars')->all());
    }

    public function test_a_submitted_application_is_never_dragged_back_to_scoring(): void
    {
        $this->profile();
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Applied]);

        $stub = new StubScoring($this->analysis(), $this->decision(5, RecommendedAction::AutoApply));
        $this->runJob($stub, $listing->id);

        $this->assertSame(0, $stub->calls);
        $this->assertSame(PipelineStage::Applied, $listing->refresh()->pipeline_stage);
        $this->assertNull(JobScore::latestAttempt($listing->id, $this->user->id));
    }

    public function test_a_user_without_a_parsed_profile_is_a_no_op(): void
    {
        $listing = $this->listing();

        $stub = new StubScoring($this->analysis(), $this->decision(5, RecommendedAction::AutoApply));
        $this->runJob($stub, $listing->id);

        $this->assertSame(0, $stub->calls);
        $this->assertSame(PipelineStage::Enriching, $listing->refresh()->pipeline_stage);
    }

    public function test_a_deleted_listing_is_a_no_op(): void
    {
        $this->profile();

        $stub = new StubScoring($this->analysis(), $this->decision(5, RecommendedAction::AutoApply));
        $this->runJob($stub, 999999);

        $this->assertSame(0, $stub->calls);
    }

    public function test_re_scoring_leaves_the_earlier_attempt_byte_for_byte_intact(): void
    {
        $this->profile();
        $listing = $this->listing();
        $this->settings(enabled: false);

        $this->runJob(new StubScoring($this->analysis(), $this->decision(2, RecommendedAction::StoreOnly)), $listing->id);

        $first = JobScore::latestAttempt($listing->id, $this->user->id);
        $before = $first->getAttributes();

        $this->runJob(new StubScoring($this->analysis(), $this->decision(5, RecommendedAction::AutoApply)), $listing->id);

        // Requirement 5.6: the earlier verdict is not touched, not just not
        // replaced — no column of it may move when a new attempt lands.
        $this->assertSame($before, $first->fresh()->getAttributes());
    }

    public function test_an_explicit_rescore_proceeds_on_a_listing_queued_for_tailoring(): void
    {
        $this->profile();
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailoring]);
        $this->settings(enabled: false);

        $stub = new StubScoring($this->analysis(), $this->decision(2, RecommendedAction::StoreOnly));
        $this->runScoring(ScoreJobListing::rescore($listing->id, $this->user->id), $stub);

        $this->assertSame(2, $stub->calls);
        $this->assertSame(1, JobScore::latestAttempt($listing->id, $this->user->id)?->attempt_number);
        $this->assertSame(PipelineStage::StoreOnly, $listing->refresh()->pipeline_stage);
    }

    public function test_an_automated_dispatch_still_skips_a_listing_queued_for_tailoring(): void
    {
        $this->profile();
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Tailoring]);

        $stub = new StubScoring($this->analysis(), $this->decision(5, RecommendedAction::AutoApply));
        $this->runJob($stub, $listing->id);

        // The default path is unchanged: only an explicit re-score reopens this.
        $this->assertSame(0, $stub->calls);
        $this->assertSame(PipelineStage::Tailoring, $listing->refresh()->pipeline_stage);
    }

    public function test_an_explicit_rescore_cannot_reopen_a_submitted_application(): void
    {
        $this->profile();
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Applied]);

        $stub = new StubScoring($this->analysis(), $this->decision(1, RecommendedAction::StoreOnly));
        $this->runScoring(ScoreJobListing::rescore($listing->id, $this->user->id), $stub);

        $this->assertSame(0, $stub->calls);
        $this->assertSame(PipelineStage::Applied, $listing->refresh()->pipeline_stage);
        $this->assertNull(JobScore::latestAttempt($listing->id, $this->user->id));
    }

    public function test_a_rescore_racing_a_concurrent_score_keeps_both_verdicts(): void
    {
        $this->profile();
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Scored]);
        $this->settings(enabled: false);

        // Stand in for the other worker: slip attempt 1 in underneath this run,
        // after it has already read `nextAttemptNumber()` as 1.
        $raced = false;

        JobScore::creating(function (JobScore $score) use (&$raced) {
            if ($raced) {
                return;
            }

            $raced = true;

            DB::table('job_scores')->insert([
                'job_listing_id' => $score->job_listing_id,
                'user_id' => $score->user_id,
                'attempt_number' => $score->attempt_number,
                'fit_analysis' => '{"raced":true}',
                'stars' => 1,
                'rationale' => 'Written by the competing worker.',
                'recommended_action' => RecommendedAction::StoreOnly->value,
                'raw_prompt_1_output' => '{}',
                'raw_prompt_2_output' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->runJob(new StubScoring($this->analysis(), $this->decision(4, RecommendedAction::AutoApply)), $listing->id);
        } finally {
            JobScore::flushEventListeners();
        }

        $scores = JobScore::query()->forPair($listing->id, $this->user->id)->get();

        $this->assertTrue($raced, 'the race never happened, so nothing was tested');
        $this->assertCount(2, $scores, 'the losing insert must retry, not vanish');
        $this->assertSame([2, 1], $scores->pluck('attempt_number')->all());
        $this->assertSame([4, 1], $scores->pluck('stars')->all());
    }

    public function test_two_rows_can_never_claim_the_same_attempt_number(): void
    {
        $this->profile();
        $listing = $this->listing();
        $this->settings(enabled: false);

        $this->runJob(new StubScoring($this->analysis(), $this->decision(3, RecommendedAction::StoreOnly)), $listing->id);

        $this->expectException(QueryException::class);

        // The database, not the job, is what forbids this.
        DB::table('job_scores')->insert([
            'job_listing_id' => $listing->id,
            'user_id' => $this->user->id,
            'attempt_number' => 1,
            'fit_analysis' => '{}',
            'stars' => 5,
            'rationale' => 'Duplicate attempt.',
            'recommended_action' => RecommendedAction::AutoApply->value,
            'raw_prompt_1_output' => '{}',
            'raw_prompt_2_output' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_exhausted_retries_mark_the_row_failed(): void
    {
        $listing = $this->listing();

        (new ScoreJobListing($listing->id, $this->user->id))->failed(new RuntimeException('Database went away.'));

        $this->assertSame(PipelineStage::Failed, $listing->refresh()->pipeline_stage);
    }
}

/**
 * Answers with fixed value objects, or throws on the rating prompt when
 * `$failure` is set. Subclasses the real service rather than mocking, so a
 * signature change to either prompt method breaks this test loudly.
 */
class StubScoring extends JobScoringService
{
    public int $calls = 0;

    public ?JobScoringException $failure = null;

    public function __construct(
        private readonly FitAnalysis $analysis,
        private readonly RatingDecision $decision,
    ) {
        // Deliberately does not call the parent constructor: the router it wires
        // up must never be reachable from here.
    }

    public function promptFitAnalysis(\App\Models\JobListing $listing, \App\Models\UserProfile $profile): FitAnalysis
    {
        $this->calls++;

        return $this->analysis;
    }

    public function promptRatingDecision(\App\Models\JobListing $listing, FitAnalysis $analysis): RatingDecision
    {
        $this->calls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->decision;
    }
}
