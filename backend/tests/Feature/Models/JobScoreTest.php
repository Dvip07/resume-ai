<?php

namespace Tests\Feature\Models;

use App\Enums\PipelineStage;
use App\Enums\RecommendedAction;
use App\Models\JobListing;
use App\Models\JobScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `job_scores` table and its model: that a scoring attempt round-trips with
 * its casts intact, and that re-scoring versions rather than overwrites.
 *
 * Validates: Requirements 5.4, 1C.1, 1C.7
 */
class JobScoreTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Dev Patel',
            'email' => 'dev'.uniqid().'@example.com',
            'password' => 'password',
        ]);
    }

    private function listing(): JobListing
    {
        return JobListing::create([
            'api_id' => '123',
            'title' => 'Senior Laravel Engineer',
            'company' => 'Acme',
            'location' => 'Toronto, Ontario, Canada',
            'description' => 'Long enough description for scoring.',
            'api_source' => 'adzuna',
            'source_key' => 'adzuna',
            'is_active' => true,
            'parsed_skills' => [],
            'application_url' => 'https://jobs.example.com/careers/123',
            'pipeline_stage' => PipelineStage::Scored,
        ]);
    }

    private function score(JobListing $listing, User $user, array $overrides = []): JobScore
    {
        return JobScore::create(array_merge([
            'job_listing_id' => $listing->id,
            'user_id' => $user->id,
            'attempt_number' => 1,
            'fit_analysis' => ['strengths' => ['Laravel'], 'gaps' => ['Kubernetes']],
            'stars' => 4,
            'rationale' => 'Strong backend overlap, thin on infrastructure.',
            'recommended_action' => RecommendedAction::AutoApply,
            'raw_prompt_1_output' => '{"strengths":["Laravel"]}',
            'raw_prompt_2_output' => '{"stars":4}',
        ], $overrides));
    }

    public function test_a_scoring_attempt_round_trips_with_its_casts(): void
    {
        $listing = $this->listing();
        $user = $this->user();

        $stored = $this->score($listing, $user)->fresh();

        $this->assertSame(['strengths' => ['Laravel'], 'gaps' => ['Kubernetes']], $stored->fit_analysis);
        $this->assertSame(4, $stored->stars);
        $this->assertSame(1, $stored->attempt_number);
        $this->assertSame(RecommendedAction::AutoApply, $stored->recommended_action);
        $this->assertSame('{"stars":4}', $stored->raw_prompt_2_output);
    }

    public function test_it_belongs_to_its_job_listing_and_user(): void
    {
        $listing = $this->listing();
        $user = $this->user();

        $score = $this->score($listing, $user);

        $this->assertSame($listing->id, $score->jobListing->id);
        $this->assertSame($user->id, $score->user->id);
    }

    public function test_re_scoring_adds_an_attempt_instead_of_overwriting(): void
    {
        $listing = $this->listing();
        $user = $this->user();

        $first = $this->score($listing, $user);

        $this->assertSame(2, JobScore::nextAttemptNumber($listing->id, $user->id));

        $second = $this->score($listing, $user, [
            'attempt_number' => JobScore::nextAttemptNumber($listing->id, $user->id),
            'stars' => 2,
            'recommended_action' => RecommendedAction::StoreOnly,
        ]);

        $this->assertSame(2, JobScore::query()->forPair($listing->id, $user->id)->count());
        $this->assertSame(4, $first->fresh()->stars, 'the earlier attempt must stay readable');
        $this->assertSame($second->id, JobScore::latestAttempt($listing->id, $user->id)->id);
    }

    public function test_next_attempt_number_starts_at_one_for_an_unscored_pair(): void
    {
        $listing = $this->listing();
        $user = $this->user();

        $this->assertSame(1, JobScore::nextAttemptNumber($listing->id, $user->id));
        $this->assertNull(JobScore::latestAttempt($listing->id, $user->id));
    }

    public function test_recommended_action_maps_to_the_pipeline_stage_it_implies(): void
    {
        $this->assertSame(PipelineStage::Tailoring, RecommendedAction::AutoApply->toPipelineStage());
        $this->assertSame(PipelineStage::StoreOnly, RecommendedAction::StoreOnly->toPipelineStage());
    }

    public function test_meets_threshold_compares_stars_against_the_auto_apply_bar(): void
    {
        $score = new JobScore(['stars' => 4]);

        $this->assertTrue($score->meetsThreshold(4));
        $this->assertFalse($score->meetsThreshold(5));
    }
}
