<?php

namespace Tests\Unit\Services\JobSources\Providers;

use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\JobSourceRegistry;
use App\Services\JobSources\NormalizedJob;
use App\Services\JobSources\Providers\AdzunaJobSourceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Normalization and call shaping for the Adzuna source (task 8.2,
 * Requirement 3.1). Every test fakes the HTTP layer against a recorded payload
 * fixture — no request reaches the live API.
 */
class AdzunaJobSourceProviderTest extends TestCase
{
    private const URL = 'https://api.adzuna.com/v1/api/jobs/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.adzuna.app_id' => 'test-app-id',
            'services.adzuna.app_key' => 'test-app-key',
            'services.adzuna.base_url' => 'https://api.adzuna.com/v1/api/jobs',
            'services.adzuna.country' => 'ca',
            'services.adzuna.results_per_page' => 5,
            'services.adzuna.max_days_old' => 30,
        ]);

        Http::preventStrayRequests();
    }

    private function provider(): AdzunaJobSourceProvider
    {
        return $this->app->make(AdzunaJobSourceProvider::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/adzuna/search_response.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    /**
     * The fixture's results with only the ones named in `$ids` kept, so a test
     * can isolate a single payload case without a second fixture file.
     *
     * @param list<string> $ids
     *
     * @return array<string, mixed>
     */
    private function fixtureWithOnly(array $ids): array
    {
        $payload = $this->fixture();

        $payload['results'] = array_values(array_filter(
            $payload['results'],
            static fn (array $job) => in_array($job['id'] ?? null, $ids, true)
        ));

        return $payload;
    }

    private function query(mixed $roles = 'Laravel Developer', ?int $limit = 10, ?string $location = 'Toronto, Ontario'): JobSearchQuery
    {
        return new JobSearchQuery(roles: $roles, userId: 1, location: $location, limit: $limit);
    }

    public function test_it_normalizes_a_complete_posting(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['4915032871']))]);

        $jobs = $this->provider()->search($this->query());

        $this->assertCount(1, $jobs);

        /** @var NormalizedJob $job */
        $job = $jobs->first();

        $this->assertSame('adzuna', $job->sourceKey);
        $this->assertSame('4915032871', $job->externalId);
        $this->assertSame('Senior Laravel Developer', $job->title);
        $this->assertSame('Northwind Digital', $job->company);
        $this->assertSame('Toronto, Ontario', $job->location);
        $this->assertStringContainsString('senior Laravel developer', $job->description);
        $this->assertSame(
            'https://www.adzuna.ca/land/ad/4915032871?se=fixture&utm_medium=api',
            $job->applicationUrl
        );
        $this->assertSame('2026-02-11 09:14:22', $job->postedAt->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(105000, $job->salaryMin);
        $this->assertSame(135001, $job->salaryMax); // 135000.5 rounded to whole units
        $this->assertSame('CAD', $job->currency);
    }

    public function test_it_sends_the_legacy_query_shape_to_the_configured_country(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['4915032871']))]);

        config(['services.adzuna.country' => 'gb']);

        $this->provider()->search($this->query());

        Http::assertSent(function (Request $request) {
            $this->assertStringStartsWith(
                'https://api.adzuna.com/v1/api/jobs/gb/search/1',
                $request->url()
            );

            $this->assertSame('test-app-id', $request['app_id']);
            $this->assertSame('test-app-key', $request['app_key']);
            $this->assertSame('Laravel Developer', $request['what']);
            $this->assertSame('Toronto, Ontario', $request['location0']);
            $this->assertSame('30', (string) $request['max_days_old']);

            return true;
        });
    }

    public function test_location0_is_omitted_when_the_query_has_no_location(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['4915032871']))]);

        $this->provider()->search($this->query(location: null));

        Http::assertSent(fn (Request $request) => ! array_key_exists('location0', $request->data()));
    }

    public function test_the_currency_follows_the_configured_country(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['4915032871']))]);

        config(['services.adzuna.country' => 'us']);
        $this->assertSame('USD', $this->provider()->search($this->query())->first()->currency);

        config(['services.adzuna.country' => 'GB']); // case-insensitive
        $this->assertSame('GBP', $this->provider()->search($this->query())->first()->currency);

        config(['services.adzuna.country' => 'de']);
        $this->assertSame('EUR', $this->provider()->search($this->query())->first()->currency);
    }

    public function test_salary_is_dropped_when_the_country_has_no_mapped_currency(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['4915032871']))]);

        // An unmapped vertical: mislabelling the figure would silently skew the
        // salary-based model tier thresholds, so the salary goes rather than the job.
        config(['services.adzuna.country' => 'jp']);

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertSame('Senior Laravel Developer', $job->title);
        $this->assertFalse($job->hasSalary());
        $this->assertNull($job->salaryMin);
        $this->assertNull($job->salaryMax);
        $this->assertNull($job->currency);
    }

    public function test_it_handles_partial_payloads(): void
    {
        Http::fake([self::URL => Http::response(
            $this->fixtureWithOnly(['4915044190', '4915051007', '4915066512'])
        )]);

        $jobs = $this->provider()->search($this->query())->keyBy->externalId;

        // No salary and no location object at all: both simply absent, and the
        // stray currency is dropped along with the missing salary.
        $noSalary = $jobs['4915044190'];
        $this->assertSame('', $noSalary->location);
        $this->assertFalse($noSalary->hasSalary());
        $this->assertNull($noSalary->currency);
        $this->assertTrue($noSalary->needsEnrichment()); // teaser description

        // Bounds reported inverted are swapped rather than rejected.
        $inverted = $jobs['4915051007'];
        $this->assertSame(90000, $inverted->salaryMin);
        $this->assertSame(120000, $inverted->salaryMax);

        // salary_min of 0 means "unknown", not "unpaid"; an unparseable
        // `created` leaves postedAt null instead of failing the posting.
        $oneBound = $jobs['4915066512'];
        $this->assertNull($oneBound->salaryMin);
        $this->assertSame(88000, $oneBound->salaryMax);
        $this->assertSame('CAD', $oneBound->currency);
        $this->assertNull($oneBound->postedAt);
    }

    public function test_an_unusable_posting_is_skipped_without_failing_the_run(): void
    {
        Http::fake([self::URL => Http::response($this->fixture())]);

        $jobs = $this->provider()->search($this->query());

        // Five results in, four out: the one with no title/redirect_url is dropped.
        $this->assertCount(4, $jobs);
        $this->assertNotContains('4915077333', $jobs->pluck('externalId')->all());
    }

    public function test_it_respects_the_query_limit_across_roles(): void
    {
        Http::fake([self::URL => Http::response($this->fixture())]);

        $jobs = $this->provider()->search(
            $this->query(roles: ['Laravel Developer', 'Backend Engineer', 'Full Stack'], limit: 3)
        );

        $this->assertCount(3, $jobs);

        // Once the ceiling is reached the remaining roles are never requested.
        Http::assertSentCount(1);
    }

    public function test_results_per_page_never_exceeds_what_is_still_needed(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['4915032871']))]);

        $this->provider()->search($this->query(limit: 2));

        Http::assertSent(fn (Request $request) => (string) $request['results_per_page'] === '2');
    }

    public function test_the_same_posting_is_not_returned_twice_across_roles(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['4915032871']))]);

        $jobs = $this->provider()->search(
            $this->query(roles: ['Laravel Developer', 'PHP Developer'], limit: 10)
        );

        Http::assertSentCount(2);
        $this->assertCount(1, $jobs);
    }

    public function test_an_error_body_returned_with_a_200_yields_no_jobs(): void
    {
        Http::fake([self::URL => Http::response(['exception' => 'AUTH_FAIL', 'doc' => 'Bad app_id'])]);

        $this->assertTrue($this->provider()->search($this->query())->isEmpty());
    }

    public function test_transport_failure_throws_rather_than_looking_like_no_jobs(): void
    {
        Http::fake([self::URL => Http::response('Unauthorized', 401)]);

        $this->expectException(RequestException::class);

        $this->provider()->search($this->query());
    }

    public function test_key_matches_the_config_registration(): void
    {
        $provider = $this->provider();

        $this->assertSame('adzuna', $provider->key());

        // The registry refuses a provider whose key() disagrees with the key it
        // is registered under, so resolving it proves the two line up.
        $this->assertSame(
            $provider->key(),
            $this->app->make(JobSourceRegistry::class)->get('adzuna')->key()
        );

        $this->assertSame(
            AdzunaJobSourceProvider::class,
            config('job_sources.providers.adzuna.class')
        );
    }
}
