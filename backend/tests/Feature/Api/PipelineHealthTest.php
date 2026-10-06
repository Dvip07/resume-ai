<?php

namespace Tests\Feature\Api;

use App\Models\Application;
use App\Models\JobListing;
use App\Models\JobScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for the dashboard's pipeline-health read
 * (App\Http\Controllers\Api\PipelineHealthController).
 *
 * Validates: Requirements 11.2
 */
class PipelineHealthTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken];
    }

    private function makeJobListing(string $stage, array $overrides = []): JobListing
    {
        return JobListing::create(array_merge([
            'api_id' => 'job-'.uniqid(),
            'title' => 'Software Engineer',
            'company' => 'Acme Inc',
            'location' => 'Remote',
            'description' => 'Build things.',
            'api_source' => 'adzuna',
            'posted_at' => now(),
            'is_active' => true,
            'parsed_skills' => ['php'],
            'application_url' => 'https://example.com/apply',
            'pipeline_stage' => $stage,
        ], $overrides));
    }

    private function scoreFor(User $user, JobListing $listing): JobScore
    {
        return JobScore::create([
            'job_listing_id' => $listing->id,
            'user_id' => $user->id,
            'attempt_number' => 1,
            'fit_analysis' => ['summary' => 'ok'],
            'stars' => 4,
            'rationale' => 'Good match.',
            'recommended_action' => 'auto_apply',
            'raw_prompt_1_output' => '{}',
            'raw_prompt_2_output' => '{}',
        ]);
    }

    public function test_without_authorization_header_returns_401(): void
    {
        $this->getJson('/api/pipeline-health')->assertStatus(401);
    }

    /**
     * A healthy account answers with zeros rather than an error or a null —
     * the widget needs a shape it can always render.
     */
    public function test_returns_zero_counts_when_nothing_has_failed(): void
    {
        $user = User::factory()->create();
        $this->scoreFor($user, $this->makeJobListing('applied'));

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/pipeline-health')
            ->assertStatus(200)
            ->assertJsonPath('stages.failed', 0)
            ->assertJsonPath('stages.needs_review', 0)
            ->assertJsonPath('stages.total', 0)
            ->assertJsonPath('queue.failed_jobs', 0)
            ->assertJsonPath('queue.scope', 'platform')
            ->assertJsonPath('latest_failure', null);
    }

    public function test_counts_the_users_listings_per_failure_stage(): void
    {
        $user = User::factory()->create();

        $this->scoreFor($user, $this->makeJobListing('failed'));
        $this->scoreFor($user, $this->makeJobListing('failed'));
        $this->scoreFor($user, $this->makeJobListing('needs_review'));
        // Not a failure stage, so it must not be counted.
        $this->scoreFor($user, $this->makeJobListing('tailored'));

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/pipeline-health')
            ->assertStatus(200)
            ->assertJsonPath('stages.failed', 2)
            ->assertJsonPath('stages.needs_review', 1)
            ->assertJsonPath('stages.total', 3);
    }

    public function test_another_users_failures_are_not_counted(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->makeJobListing('failed');
        $this->scoreFor($user, $mine);

        $theirs = $this->makeJobListing('needs_review');
        $this->scoreFor($other, $theirs);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/pipeline-health')
            ->assertStatus(200)
            ->assertJsonPath('stages.failed', 1)
            ->assertJsonPath('stages.needs_review', 0)
            ->assertJsonPath('stages.total', 1);
    }

    /**
     * An application row is enough to make a listing "the user's", and the
     * newest failing one is reported with its recorded reason.
     */
    public function test_reports_the_latest_failure_for_the_user_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $listing = $this->makeJobListing('failed', ['title' => 'Platform Engineer']);
        Application::create([
            'user_id' => $user->id,
            'job_listing_id' => $listing->id,
            'status' => 'failed',
            'automation_log' => ['failure_reason' => 'Login wall hit.'],
        ]);

        $theirListing = $this->makeJobListing('failed', ['title' => 'Someone Else']);
        Application::create([
            'user_id' => $other->id,
            'job_listing_id' => $theirListing->id,
            'status' => 'failed',
            'automation_log' => ['failure_reason' => 'Not yours.'],
        ]);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/pipeline-health')
            ->assertStatus(200)
            ->assertJsonPath('stages.failed', 1)
            ->assertJsonPath('latest_failure.job_title', 'Platform Engineer')
            ->assertJsonPath('latest_failure.stage', 'failed')
            ->assertJsonPath('latest_failure.reason', 'Login wall hit.');
    }

    /** The dead-letter count is operational: global, not per user. */
    public function test_counts_failed_queue_jobs_globally(): void
    {
        $user = User::factory()->create();

        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Boom',
            'failed_at' => now(),
        ]);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/pipeline-health')
            ->assertStatus(200)
            ->assertJsonPath('queue.failed_jobs', 1)
            ->assertJsonPath('queue.scope', 'platform');
    }
}
