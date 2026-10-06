<?php

namespace Tests\Feature\Jobs;

use App\Enums\PipelineStage;
use App\Jobs\DiscoverJobsForUser;
use App\Jobs\EnrichJobDescription;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\JobSources\JobDiscoveryOrchestrator;
use App\Services\JobSources\JobDiscoveryResult;
use App\Services\JobSources\JobSearchQuery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The queued discovery job: what it asks the orchestrator for, and when it
 * declines to ask at all.
 *
 * The orchestrator is replaced by a recording double, so no provider, HTTP call
 * or rate-limit counter is involved — this covers the job's own decisions
 * (query construction from the profile, overrides, no-op cases) and nothing
 * that JobDiscoveryOrchestratorTest already covers.
 *
 * The chain into enrichment (task 9.4) is covered here too, with a faked queue:
 * which of the touched rows get an EnrichJobDescription dispatch, and which are
 * left alone.
 *
 * Validates: Requirements 11.1, 11.3
 */
class DiscoverJobsForUserTest extends TestCase
{
    use RefreshDatabase;

    private RecordingOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orchestrator = new RecordingOrchestrator();
        $this->app->instance(JobDiscoveryOrchestrator::class, $this->orchestrator);
    }

    private function profileFor(User $user, array $overrides = []): UserProfile
    {
        return UserProfile::create(array_merge([
            'user_id' => $user->id,
            'skills' => ['primary' => ['php'], 'secondary' => []],
            'suggested_roles' => ['Backend Engineer', 'Platform Engineer'],
            'location' => ['city' => 'Oshawa', 'state' => 'Ontario', 'country' => 'Canada'],
            // NOT NULL in the schema with no defaults, irrelevant to discovery.
            'linkedin_url' => '',
            'github_url' => '',
            'portfolio_url' => '',
            'experience' => [],
            'education' => [],
            'parsed_keywords' => '',
            'resume_text' => '',
        ], $overrides));
    }

    private function runJob(DiscoverJobsForUser $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    public function test_it_is_a_queued_job(): void
    {
        // Requirement 11.1: discovery must not run synchronously in a request.
        $this->assertInstanceOf(ShouldQueue::class, new DiscoverJobsForUser(1));
    }

    public function test_it_builds_the_query_from_the_users_profile(): void
    {
        $user = User::factory()->create();
        $this->profileFor($user);

        $this->runJob(new DiscoverJobsForUser($user->id));

        $query = $this->orchestrator->lastQuery;

        $this->assertNotNull($query);
        $this->assertSame(['Backend Engineer', 'Platform Engineer'], $query->roles);
        $this->assertSame('Oshawa, Ontario, Canada', $query->location);
        $this->assertSame($user->id, $query->userId);
    }

    public function test_explicit_roles_location_and_limit_override_the_profile(): void
    {
        $user = User::factory()->create();
        $this->profileFor($user);

        $this->runJob(new DiscoverJobsForUser(
            userId: $user->id,
            roles: ['Site Reliability Engineer'],
            location: 'Remote',
            limit: 5,
        ));

        $query = $this->orchestrator->lastQuery;

        $this->assertSame(['Site Reliability Engineer'], $query->roles);
        $this->assertSame('Remote', $query->location);
        $this->assertSame(5, $query->limit);
    }

    public function test_it_does_nothing_when_the_profile_has_no_roles(): void
    {
        $user = User::factory()->create();
        $this->profileFor($user, ['suggested_roles' => []]);

        $this->runJob(new DiscoverJobsForUser($user->id));

        // Nothing to search for is a no-op, not a failure: retrying would never
        // produce a different result.
        $this->assertSame(0, $this->orchestrator->calls);
    }

    public function test_it_does_nothing_when_the_user_has_no_profile_at_all(): void
    {
        $user = User::factory()->create();

        $this->runJob(new DiscoverJobsForUser($user->id));

        $this->assertSame(0, $this->orchestrator->calls);
    }

    public function test_it_does_nothing_when_the_user_was_deleted_after_dispatch(): void
    {
        $user = User::factory()->create();
        $this->profileFor($user);
        $userId = $user->id;
        $user->delete();

        $this->runJob(new DiscoverJobsForUser($userId));

        $this->assertSame(0, $this->orchestrator->calls);
    }

    public function test_a_partial_profile_location_is_used_as_far_as_it_goes(): void
    {
        $user = User::factory()->create();
        $this->profileFor($user, ['location' => ['country' => 'Canada', 'city' => '']]);

        $this->runJob(new DiscoverJobsForUser($user->id));

        $this->assertSame('Canada', $this->orchestrator->lastQuery->location);
    }

    public function test_a_missing_profile_location_searches_anywhere(): void
    {
        $user = User::factory()->create();
        $this->profileFor($user, ['location' => []]);

        $this->runJob(new DiscoverJobsForUser($user->id));

        $this->assertNull($this->orchestrator->lastQuery->location);
    }

    // ---------------------------------------------------------------------
    // Chain into enrichment (Requirement 11.3)
    // ---------------------------------------------------------------------

    /** Text long enough to clear `job_sources.min_description_length`. */
    private function fullDescription(): string
    {
        return str_repeat('We need a Laravel engineer who cares about correctness. ', 12);
    }

    private function listing(array $overrides = []): JobListing
    {
        return JobListing::create(array_merge([
            'api_id' => (string) fake()->unique()->numberBetween(1, 100000),
            'title' => 'Senior Laravel Engineer',
            'company' => 'Acme',
            'location' => 'Toronto, Ontario, Canada',
            'description' => 'Teaser only.',
            'api_source' => 'adzuna',
            'source_key' => 'adzuna',
            'is_active' => true,
            'parsed_skills' => [],
            'application_url' => 'https://jobs.example.com/careers/'.fake()->unique()->numberBetween(1, 100000),
            'pipeline_stage' => PipelineStage::Discovered,
        ], $overrides));
    }

    /** Run discovery with the double reporting the given rows as its outcome. */
    private function discoverTouching(array $createdIds, array $updatedIds = []): User
    {
        config(['job_sources.min_description_length' => 400]);

        $user = User::factory()->create();
        $this->profileFor($user);

        $this->orchestrator->createdIds = $createdIds;
        $this->orchestrator->updatedIds = $updatedIds;

        $this->runJob(new DiscoverJobsForUser($user->id));

        return $user;
    }

    public function test_it_queues_enrichment_for_rows_whose_description_is_too_short_to_score(): void
    {
        Queue::fake();

        $short = $this->listing();
        $empty = $this->listing(['description' => '']);

        $this->discoverTouching([$short->id, $empty->id]);

        foreach ([$short, $empty] as $listing) {
            Queue::assertPushed(
                EnrichJobDescription::class,
                fn (EnrichJobDescription $job) => $job->jobListingId === $listing->id && $job->force === false,
            );
        }

        Queue::assertPushed(EnrichJobDescription::class, 2);
    }

    public function test_a_rediscovered_row_is_enriched_as_well_as_a_new_one(): void
    {
        Queue::fake();

        $created = $this->listing();
        $updated = $this->listing();

        // Requirement 11.3 is about the rows the run *touched*, not only the
        // ones it inserted: an aggregator re-listing a job we never managed to
        // enrich is another chance to get its description.
        $this->discoverTouching([$created->id], [$updated->id]);

        Queue::assertPushed(EnrichJobDescription::class, 2);
    }

    public function test_a_row_that_already_has_a_usable_description_is_not_enriched(): void
    {
        Queue::fake();

        $full = $this->listing(['description' => $this->fullDescription()]);

        $this->discoverTouching([$full->id]);

        // The source shipped full text; there is nothing to fetch.
        Queue::assertNotPushed(EnrichJobDescription::class);
    }

    public function test_rows_that_moved_past_enrichment_are_left_alone(): void
    {
        Queue::fake();

        // Short descriptions on all of them: only the stage should keep them out
        // of the dispatch, per design.md Property 7 (stages move forward only).
        $ids = [];

        foreach ([
            PipelineStage::Scored,
            PipelineStage::Applying,
            PipelineStage::Applied,
            PipelineStage::NeedsReview,
            PipelineStage::Failed,
            PipelineStage::StoreOnly,
        ] as $stage) {
            $ids[] = $this->listing(['pipeline_stage' => $stage])->id;
        }

        $this->discoverTouching([], $ids);

        Queue::assertNotPushed(EnrichJobDescription::class);
    }

    public function test_a_row_still_marked_enriching_gets_another_attempt(): void
    {
        Queue::fake();

        // A worker that died mid-fetch leaves the row here. Re-dispatching is
        // the same stage, not a step back, and is the only way it ever recovers.
        $stuck = $this->listing(['pipeline_stage' => PipelineStage::Enriching]);

        $this->discoverTouching([], [$stuck->id]);

        Queue::assertPushed(
            EnrichJobDescription::class,
            fn (EnrichJobDescription $job) => $job->jobListingId === $stuck->id,
        );
    }

    public function test_a_run_that_touched_nothing_queues_nothing(): void
    {
        Queue::fake();

        $this->discoverTouching([]);

        Queue::assertNotPushed(EnrichJobDescription::class);
    }

    public function test_discovery_does_not_advance_the_pipeline_stage_itself(): void
    {
        Queue::fake();

        $listing = $this->listing();

        $this->discoverTouching([$listing->id]);

        // `enriching` is enrichment's transition and `scored` is the scoring
        // job's (task 10.3): discovery must not pre-announce either.
        $this->assertSame(PipelineStage::Discovered, $listing->refresh()->pipeline_stage);
    }
}

/**
 * Records the query it was asked to run instead of calling any provider.
 */
class RecordingOrchestrator extends JobDiscoveryOrchestrator
{
    public int $calls = 0;

    public ?JobSearchQuery $lastQuery = null;

    /** @var list<int> rows the next run should report as newly inserted */
    public array $createdIds = [];

    /** @var list<int> rows the next run should report as rediscovered */
    public array $updatedIds = [];

    public function __construct()
    {
        // Bypasses the real constructor's collaborators: nothing in this double
        // resolves a provider or a rate-limit counter.
    }

    public function discover(JobSearchQuery $query): JobDiscoveryResult
    {
        $this->calls++;
        $this->lastQuery = $query;

        $result = new JobDiscoveryResult();

        foreach ($this->createdIds as $id) {
            $result->recordCreated('adzuna', $id);
        }

        foreach ($this->updatedIds as $id) {
            $result->recordUpdated('adzuna', $id);
        }

        return $result;
    }
}
