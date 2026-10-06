<?php

namespace Tests\Feature\Console;

use App\Jobs\DiscoverJobsForUser;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The scheduled `jobs:discover` trigger: it queues work and does nothing else.
 *
 * Validates: Requirements 11.1
 */
class DiscoverJobsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRoles(array $roles = ['Backend Engineer']): User
    {
        $user = User::factory()->create();

        UserProfile::create([
            'user_id' => $user->id,
            'skills' => ['primary' => ['php'], 'secondary' => []],
            'suggested_roles' => $roles,
            'location' => ['country' => 'Canada'],
            // NOT NULL in the schema with no defaults, irrelevant to discovery.
            'linkedin_url' => '',
            'github_url' => '',
            'portfolio_url' => '',
            'experience' => [],
            'education' => [],
            'parsed_keywords' => '',
            'resume_text' => '',
        ]);

        return $user;
    }

    public function test_it_queues_one_job_per_user_with_searchable_roles(): void
    {
        Queue::fake();

        $withRoles = $this->userWithRoles();
        $withoutRoles = $this->userWithRoles([]);
        $withoutProfile = User::factory()->create();

        $this->artisan('jobs:discover')
            ->expectsOutputToContain('Queued job discovery for 1 user.')
            ->assertSuccessful();

        Queue::assertPushed(DiscoverJobsForUser::class, 1);
        Queue::assertPushed(
            DiscoverJobsForUser::class,
            fn (DiscoverJobsForUser $job) => $job->userId === $withRoles->id
        );

        foreach ([$withoutRoles, $withoutProfile] as $skipped) {
            Queue::assertNotPushed(
                DiscoverJobsForUser::class,
                fn (DiscoverJobsForUser $job) => $job->userId === $skipped->id
            );
        }
    }

    public function test_it_never_runs_discovery_inline(): void
    {
        Queue::fake();

        $this->userWithRoles();

        // Requirement 11.1: the command dispatches; the fan-out happens on a
        // worker. Nothing is written to job_listings by the command itself.
        $this->artisan('jobs:discover')->assertSuccessful();

        $this->assertDatabaseCount('job_listings', 0);
    }

    public function test_user_option_restricts_the_run_and_is_honoured_as_given(): void
    {
        Queue::fake();

        $first = $this->userWithRoles();
        $this->userWithRoles();
        // No profile: an explicitly named user is still dispatched for, and the
        // job logs why it did nothing, rather than being silently dropped here.
        $noProfile = User::factory()->create();

        $this->artisan('jobs:discover', ['--user' => [$first->id, $noProfile->id]])
            ->assertSuccessful();

        Queue::assertPushed(DiscoverJobsForUser::class, 2);
        Queue::assertPushed(
            DiscoverJobsForUser::class,
            fn (DiscoverJobsForUser $job) => $job->userId === $noProfile->id
        );
    }

    public function test_role_and_location_options_are_passed_to_the_job(): void
    {
        Queue::fake();

        $user = $this->userWithRoles();

        $this->artisan('jobs:discover', [
            '--user' => [$user->id],
            '--role' => ['Site Reliability Engineer', ' '],
            '--location' => 'Remote',
            '--limit' => '7',
        ])->assertSuccessful();

        Queue::assertPushed(function (DiscoverJobsForUser $job) {
            return $job->roles === ['Site Reliability Engineer']
                && $job->location === 'Remote'
                && $job->limit === 7;
        });
    }

    public function test_roles_supplied_on_the_command_line_reach_users_without_suggested_roles(): void
    {
        Queue::fake();

        $withoutRoles = $this->userWithRoles([]);

        $this->artisan('jobs:discover', ['--role' => ['Backend Engineer']])
            ->assertSuccessful();

        Queue::assertPushed(
            DiscoverJobsForUser::class,
            fn (DiscoverJobsForUser $job) => $job->userId === $withoutRoles->id
        );
    }

    public function test_it_succeeds_quietly_when_there_is_nobody_to_discover_for(): void
    {
        Queue::fake();

        $this->artisan('jobs:discover')
            ->expectsOutputToContain('No users to discover jobs for.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_non_positive_limit_is_rejected(): void
    {
        Queue::fake();

        $this->userWithRoles();

        $this->artisan('jobs:discover', ['--limit' => '0'])->assertFailed();

        Queue::assertNothingPushed();
    }
}
