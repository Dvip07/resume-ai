<?php

namespace Tests\Feature\Api;

use App\Jobs\DiscoverJobsForUser;
use App\Models\JobListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for the job listing read API
 * (App\Http\Controllers\Api\JobListingController / routes/api.php).
 *
 * Validates: Requirements 10.1, 10.2
 */
class JobListingTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        $token = $user->createToken('test-token')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    private function makeJobListing(array $overrides = []): JobListing
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
            'parsed_skills' => ['php', 'laravel'],
            'application_url' => 'https://example.com/apply',
        ], $overrides));
    }

    public function test_index_without_authorization_header_returns_401(): void
    {
        $this->getJson('/api/jobs')->assertStatus(401);
    }

    public function test_show_without_authorization_header_returns_401(): void
    {
        $job = $this->makeJobListing();

        $this->getJson("/api/jobs/{$job->id}")->assertStatus(401);
    }

    public function test_index_returns_only_active_job_listings_paginated(): void
    {
        $user = User::factory()->create();

        $this->makeJobListing(['title' => 'Active Job', 'is_active' => true]);
        $this->makeJobListing(['title' => 'Inactive Job', 'is_active' => false]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/jobs');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'per_page'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Active Job');
    }

    public function test_show_returns_an_existing_job_listing(): void
    {
        $user = User::factory()->create();
        $job = $this->makeJobListing(['title' => 'Backend Developer']);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/jobs/{$job->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $job->id)
            ->assertJsonPath('title', 'Backend Developer');
    }

    public function test_show_returns_404_for_a_nonexistent_job_listing(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/jobs/999999');

        $response->assertStatus(404);
    }

    public function test_discover_without_authorization_header_returns_401(): void
    {
        $this->postJson('/api/jobs/discover')->assertStatus(401);
    }

    /**
     * The manual trigger queues the run and answers immediately — no request
     * waits on a provider (Requirement 11.1).
     */
    public function test_discover_queues_a_discovery_job_for_the_authenticated_user(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/jobs/discover')
            ->assertStatus(202)
            ->assertJsonPath('message', 'Job discovery has been queued.');

        Queue::assertPushed(
            DiscoverJobsForUser::class,
            fn (DiscoverJobsForUser $job) => $job->userId === $user->id
                && $job->roles === null
                && $job->location === null
        );
    }

    public function test_discover_passes_optional_overrides_to_the_queued_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/jobs/discover', [
                'roles' => ['Site Reliability Engineer'],
                'location' => 'Remote',
                'limit' => 10,
            ])
            ->assertStatus(202);

        Queue::assertPushed(function (DiscoverJobsForUser $job) {
            return $job->roles === ['Site Reliability Engineer']
                && $job->location === 'Remote'
                && $job->limit === 10;
        });
    }

    public function test_discover_rejects_an_invalid_limit(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/jobs/discover', ['limit' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');

        Queue::assertNothingPushed();
    }

    /**
     * `discover` must not be swallowed by the `/api/jobs/{jobListing}` wildcard.
     */
    public function test_the_discover_route_is_not_read_as_a_job_id(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/jobs/discover')
            ->assertStatus(404);
    }
}
