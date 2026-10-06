<?php

namespace Tests\Feature\Services;

use App\Enums\PipelineStage;
use App\Models\JobListing;
use App\Models\User;
use App\Services\JobSources\JobDiscoveryOrchestrator;
use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\JobSourceProvider;
use App\Services\JobSources\NormalizedJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

/**
 * Fan-out, dedupe and upsert behaviour of JobDiscoveryOrchestrator.
 *
 * Every source here is a fake registered through `config/job_sources.php`, which
 * is the point: the orchestrator resolves providers from config and names none
 * of the real ones (Requirement 3.2). No HTTP is involved.
 *
 * Validates: Requirements 3.2, 3.3
 */
class JobDiscoveryOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private function orchestrator(): JobDiscoveryOrchestrator
    {
        return $this->app->make(JobDiscoveryOrchestrator::class);
    }

    private function query(array|string $roles = 'backend engineer'): JobSearchQuery
    {
        return JobSearchQuery::forUser(User::factory()->create(), $roles, 'Toronto, ON');
    }

    /**
     * Register fake providers under the keys they report, replacing whatever
     * config ships, so no real source is ever called.
     *
     * @param array<string, JobSourceProvider> $providers keyed by source key
     */
    private function useProviders(array $providers): void
    {
        $config = [];

        foreach ($providers as $key => $provider) {
            $class = $provider::class;

            // The registry keys providers by class, so two fakes in one
            // fan-out must be distinct classes (FakeJobSource / …Alt).
            $this->assertArrayNotHasKey(
                $class,
                array_flip(array_column($config, 'class')),
                "Two fakes share the class {$class}; use FakeJobSourceAlt for the second."
            );

            // Bind the instance so the fake's canned results survive resolution.
            $this->app->instance($class, $provider);
            $config[$key] = ['class' => $class];
        }

        config(['job_sources.providers' => $config]);
    }

    private function job(string $sourceKey, array $overrides = []): NormalizedJob
    {
        return new NormalizedJob(...array_merge([
            'sourceKey' => $sourceKey,
            'title' => 'Senior Backend Engineer',
            'company' => 'Acme Inc.',
            'applicationUrl' => 'https://jobs.example.com/acme/backend',
            'location' => 'Toronto, ON',
            'externalId' => null,
            'description' => null,
        ], $overrides));
    }

    public function test_it_fans_out_to_every_enabled_provider_and_inserts_at_discovered(): void
    {
        $this->useProviders([
            'fake_one' => new FakeJobSource('fake_one', [
                $this->job('fake_one', ['title' => 'Backend Engineer', 'externalId' => 'one-1']),
            ]),
            'fake_two' => new FakeJobSourceAlt('fake_two', [
                $this->job('fake_two', ['title' => 'Platform Engineer', 'company' => 'Globex']),
            ]),
        ]);

        $result = $this->orchestrator()->discover($this->query());

        $this->assertSame(2, $result->created());
        $this->assertSame(0, $result->updated());
        $this->assertSame(['fake_one', 'fake_two'], $result->providerKeys());
        $this->assertFalse($result->hasFailures());
        $this->assertSame(2, JobListing::count());

        foreach (JobListing::all() as $row) {
            $this->assertSame(PipelineStage::Discovered, $row->pipeline_stage);
        }

        $backend = JobListing::where('title', 'Backend Engineer')->sole();
        $this->assertSame('fake_one', $backend->source_key);
        $this->assertSame('fake_one', $backend->api_source);
        $this->assertSame('one-1', $backend->api_id);
        $this->assertSame('https://jobs.example.com/acme/backend', $backend->application_url);
        $this->assertNotNull($backend->dedupe_hash);
        $this->assertSame('', $backend->description);
        $this->assertSame([], $backend->parsed_skills);
        $this->assertTrue($backend->is_active);
    }

    /** Providers turned off in config are not called at all. */
    public function test_disabled_providers_are_not_called(): void
    {
        $off = new FakeJobSource('fake_two', [$this->job('fake_two', ['company' => 'Globex'])]);
        $on = new FakeJobSourceAlt('fake_one', [$this->job('fake_one')]);

        $this->app->instance(FakeJobSource::class, $off);
        $this->app->instance(FakeJobSourceAlt::class, $on);
        config(['job_sources.providers' => [
            'fake_one' => ['class' => FakeJobSourceAlt::class],
            'fake_two' => ['class' => FakeJobSource::class, 'enabled' => false],
        ]]);

        $result = $this->orchestrator()->discover($this->query());

        $this->assertSame(['fake_one'], $result->providerKeys());
        $this->assertSame(0, $off->calls);
        $this->assertSame(1, JobListing::count());
    }

    /** Requirement 3.3: the source's own external id is the exact dedupe key. */
    public function test_it_dedupes_against_an_existing_row_by_source_and_external_id(): void
    {
        $this->useProviders([
            'fake_one' => new FakeJobSource('fake_one', [
                // Same id, title edited at the source: the hash has changed, so
                // only the external-id path can match this.
                $this->job('fake_one', ['title' => 'Staff Backend Engineer', 'externalId' => 'one-1']),
            ]),
        ]);

        $original = JobListing::create([
            'api_id' => 'one-1',
            'title' => 'Senior Backend Engineer',
            'company' => 'Acme Inc.',
            'location' => 'Toronto, ON',
            'description' => 'full description already on file',
            'api_source' => 'fake_one',
            'source_key' => 'fake_one',
            'dedupe_hash' => (new \App\Services\JobSources\JobDedupeHasher())
                ->hash('Senior Backend Engineer', 'Acme Inc.', 'Toronto, ON'),
            'is_active' => true,
            'parsed_skills' => [],
            'application_url' => 'https://jobs.example.com/acme/backend',
            'pipeline_stage' => PipelineStage::Scored,
        ]);

        $result = $this->orchestrator()->discover($this->query());

        $this->assertSame(0, $result->created());
        $this->assertSame(1, $result->updated());
        $this->assertSame([$original->id], $result->updatedIds());
        $this->assertSame(1, JobListing::count());

        $original->refresh();
        // The hash is refreshed to the new title so cross-source matching keeps working.
        $this->assertSame(
            (new \App\Services\JobSources\JobDedupeHasher())
                ->hash('Staff Backend Engineer', 'Acme Inc.', 'Toronto, ON'),
            $original->dedupe_hash
        );
        // Progress is never rolled back to `discovered`.
        $this->assertSame(PipelineStage::Scored, $original->pipeline_stage);
        // Nor is an already-enriched description clobbered by a shorter one.
        $this->assertSame('full description already on file', $original->description);
    }

    /**
     * The gap Requirement 3.3 exists for: the same posting from two sources has
     * unrelated ids, so only the composite hash catches it.
     */
    public function test_the_same_posting_from_two_sources_collapses_into_one_row(): void
    {
        $this->useProviders([
            'fake_one' => new FakeJobSource('fake_one', [
                $this->job('fake_one', ['externalId' => 'one-1']),
            ]),
            'fake_two' => new FakeJobSourceAlt('fake_two', [
                // Different punctuation, casing and legal suffix — the hasher
                // normalizes all three away.
                $this->job('fake_two', [
                    'title' => 'senior backend  engineer',
                    'company' => 'ACME, LLC',
                    'externalId' => 'two-99',
                    'description' => str_repeat('a real description. ', 30),
                    'salaryMin' => 140000,
                    'salaryMax' => 180000,
                    'currency' => 'cad',
                    'postedAt' => Carbon::parse('2026-01-05 10:00:00'),
                ]),
            ]),
        ]);

        $result = $this->orchestrator()->discover($this->query());

        $this->assertSame(1, $result->created());
        $this->assertSame(1, $result->updated());
        $this->assertSame(1, JobListing::count());

        $row = JobListing::sole();
        // First provider in config order owns the row's identity.
        $this->assertSame('fake_one', $row->source_key);
        $this->assertSame('one-1', $row->api_id);
        // The second source's better data is folded in.
        $this->assertSame(str_repeat('a real description. ', 30), $row->description);
        $this->assertSame(140000, $row->salary_min);
        $this->assertSame(180000, $row->salary_max);
        $this->assertSame('CAD', $row->currency);
        $this->assertSame('2026-01-05 10:00:00', $row->posted_at->toDateTimeString());
        $this->assertSame(PipelineStage::Discovered, $row->pipeline_stage);
    }

    /** One provider returning the same posting twice must not create two rows. */
    public function test_duplicates_within_a_single_provider_response_collapse(): void
    {
        $this->useProviders([
            'fake_one' => new FakeJobSource('fake_one', [
                $this->job('fake_one', ['externalId' => 'one-1']),
                $this->job('fake_one', ['externalId' => 'one-1']),
            ]),
        ]);

        $result = $this->orchestrator()->discover($this->query());

        $this->assertSame(1, $result->created());
        $this->assertSame(1, $result->updated());
        $this->assertSame(1, JobListing::count());
    }

    /** A dead source is logged and skipped; the rest of the fan-out still runs. */
    public function test_a_failing_provider_does_not_fail_the_run(): void
    {
        $this->useProviders([
            'fake_one' => new FakeJobSource('fake_one', new RuntimeException('adzuna 401')),
            'fake_two' => new FakeJobSourceAlt('fake_two', [
                $this->job('fake_two', ['company' => 'Globex']),
            ]),
        ]);

        $result = $this->orchestrator()->discover($this->query());

        $this->assertSame(1, $result->created());
        $this->assertTrue($result->hasFailures());
        $this->assertSame(['fake_one'], $result->failedProviderKeys());
        $this->assertStringContainsString('adzuna 401', $result->failures()['fake_one']);
        $this->assertFalse($result->allProvidersFailed());
        $this->assertSame(1, JobListing::count());
    }

    public function test_a_run_where_every_provider_fails_is_distinguishable_from_an_empty_run(): void
    {
        $this->useProviders([
            'fake_one' => new FakeJobSource('fake_one', new RuntimeException('down')),
        ]);
        $allFailed = $this->orchestrator()->discover($this->query());

        $this->useProviders([
            'fake_one' => new FakeJobSource('fake_one', []),
        ]);
        $empty = $this->orchestrator()->discover($this->query());

        $this->assertTrue($allFailed->allProvidersFailed());
        $this->assertFalse($empty->allProvidersFailed());
        $this->assertSame(0, $empty->seen());
        $this->assertSame(['fake_one'], $empty->providerKeys());
        $this->assertSame(0, JobListing::count());
    }

    /** A provider handing back the wrong type is skipped, not fatal. */
    public function test_non_normalized_results_are_skipped(): void
    {
        $this->useProviders([
            'fake_one' => new FakeJobSource('fake_one', [
                ['title' => 'raw payload, not a DTO'],
                $this->job('fake_one'),
            ]),
        ]);

        $result = $this->orchestrator()->discover($this->query());

        $this->assertSame(1, $result->created());
        $this->assertSame(1, $result->skipped());
        $this->assertSame(2, $result->seen());
        $this->assertSame(1, JobListing::count());
    }

    /**
     * Requirement 3.6: a source out of daily budget is not called, and the run
     * carries on with the sources that still have allowance.
     */
    public function test_a_provider_out_of_daily_budget_is_skipped_not_called(): void
    {
        $exhausted = new FakeJobSource('fake_one', [$this->job('fake_one')]);
        $healthy = new FakeJobSourceAlt('fake_two', [$this->job('fake_two', ['company' => 'Globex'])]);

        $this->app->instance(FakeJobSource::class, $exhausted);
        $this->app->instance(FakeJobSourceAlt::class, $healthy);
        config([
            'job_sources.providers' => [
                'fake_one' => ['class' => FakeJobSource::class],
                'fake_two' => ['class' => FakeJobSourceAlt::class],
            ],
            'services.fake_one.daily_limit' => 1,
            'services.fake_two.daily_limit' => 5,
        ]);

        $query = $this->query();

        // First run spends fake_one's single call for the day.
        $this->orchestrator()->discover($query);
        $second = $this->orchestrator()->discover($query);

        $this->assertSame(1, $exhausted->calls);
        $this->assertSame(2, $healthy->calls);

        // Skipped, not failed: being done for the day is a normal outcome.
        $this->assertTrue($second->hasRateLimited());
        $this->assertSame(['fake_one'], $second->rateLimitedProviderKeys());
        $this->assertSame(1, $second->rateLimited()['fake_one']);
        $this->assertFalse($second->hasFailures());
        $this->assertFalse($second->allProvidersFailed());
        // Still reported as part of the fan-out.
        $this->assertSame(['fake_one', 'fake_two'], $second->providerKeys());
    }

    /** Each provider call is counted, so the budget is spent by real usage. */
    public function test_each_provider_call_is_counted_against_the_daily_budget(): void
    {
        $this->useProviders([
            'fake_one' => new FakeJobSource('fake_one', [$this->job('fake_one')]),
        ]);
        config(['services.fake_one.daily_limit' => 3]);

        $query = $this->query();
        $this->orchestrator()->discover($query);
        $this->orchestrator()->discover($query);

        $limiter = new \App\Services\JobSources\ProviderDailyLimiter();
        $this->assertSame(2, $limiter->usedToday('fake_one', $query->userId));
        $this->assertSame(1, $limiter->remaining('fake_one', $query->userId));
    }

    /**
     * A provider that throws still spent an API call as far as the quota is
     * concerned, so failures count too — otherwise a source failing in a loop
     * would be unbounded.
     */
    public function test_a_failing_provider_still_consumes_its_budget(): void
    {
        $this->useProviders([
            'fake_one' => new FakeJobSource('fake_one', new RuntimeException('boom')),
        ]);
        config(['services.fake_one.daily_limit' => 1]);

        $query = $this->query();
        $first = $this->orchestrator()->discover($query);
        $second = $this->orchestrator()->discover($query);

        $this->assertSame(['fake_one'], $first->failedProviderKeys());
        $this->assertSame([], $second->failedProviderKeys());
        $this->assertSame(['fake_one'], $second->rateLimitedProviderKeys());
    }

    /** Sources with no configured budget (the free ATS boards) are unaffected. */
    public function test_a_provider_without_a_configured_limit_is_never_rate_limited(): void
    {
        $provider = new FakeJobSource('fake_one', [$this->job('fake_one')]);
        $this->useProviders(['fake_one' => $provider]);
        config(['services.fake_one.daily_limit' => null]);

        $query = $this->query();

        for ($i = 0; $i < 5; $i++) {
            $result = $this->orchestrator()->discover($query);
            $this->assertFalse($result->hasRateLimited());
        }

        $this->assertSame(5, $provider->calls);
        $this->assertSame(0, \App\Models\ProviderUsage::count());
    }

    /**
     * Legacy rows predate `source_key`/`dedupe_hash` (they were backfilled to
     * `store_only`); rediscovery should claim and label them, not duplicate
     * them or restart their pipeline.
     */
    public function test_a_legacy_row_is_claimed_rather_than_duplicated(): void
    {
        $legacy = JobListing::create([
            'api_id' => null,
            'title' => 'Senior Backend Engineer',
            'company' => 'Acme Inc.',
            'location' => '',
            'description' => '',
            'api_source' => 'Adzuna',
            'source_key' => null,
            'dedupe_hash' => (new \App\Services\JobSources\JobDedupeHasher())
                ->hash('Senior Backend Engineer', 'Acme Inc.', ''),
            'is_active' => true,
            'parsed_skills' => [],
            'application_url' => '',
            'pipeline_stage' => PipelineStage::StoreOnly,
        ]);

        // Location is part of the hash, so match the legacy row's blank one.
        $this->useProviders([
            'fake_one' => new FakeJobSource('fake_one', [
                $this->job('fake_one', ['location' => '', 'externalId' => 'one-1']),
            ]),
        ]);

        $result = $this->orchestrator()->discover($this->query());

        $this->assertSame(1, $result->updated());
        $this->assertSame(1, JobListing::count());

        $legacy->refresh();
        $this->assertSame('fake_one', $legacy->source_key);
        $this->assertSame('one-1', $legacy->api_id);
        $this->assertSame('https://jobs.example.com/acme/backend', $legacy->application_url);
        // Still terminal: rediscovery is not a reason to re-run the pipeline.
        $this->assertSame(PipelineStage::StoreOnly, $legacy->pipeline_stage);
    }
}

/**
 * A source that returns canned NormalizedJobs, or throws. Two identical classes
 * exist because the registry keys providers by class, so a fan-out test needs
 * two distinct ones.
 */
class FakeJobSource implements JobSourceProvider
{
    public int $calls = 0;

    public function __construct(
        private readonly string $key,
        private readonly array|\Throwable $results,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function search(JobSearchQuery $query): Collection
    {
        $this->calls++;

        if ($this->results instanceof \Throwable) {
            throw $this->results;
        }

        return collect($this->results);
    }
}

class FakeJobSourceAlt extends FakeJobSource {}
