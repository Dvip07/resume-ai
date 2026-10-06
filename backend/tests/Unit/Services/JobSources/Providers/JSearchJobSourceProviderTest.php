<?php

namespace Tests\Unit\Services\JobSources\Providers;

use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\JobSourceRegistry;
use App\Services\JobSources\NormalizedJob;
use App\Services\JobSources\Providers\JSearchJobSourceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Normalization and call shaping for the JSearch source (task 8.3,
 * Requirement 3.1). Every test fakes the HTTP layer against a recorded payload
 * fixture — no request reaches RapidAPI, and no key is needed beyond the fake
 * one set below.
 */
class JSearchJobSourceProviderTest extends TestCase
{
    private const URL = 'https://jsearch.p.rapidapi.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jsearch.api_key' => 'test-rapidapi-key',
            'services.jsearch.host' => 'jsearch.p.rapidapi.com',
            'services.jsearch.base_url' => 'https://jsearch.p.rapidapi.com',
            'services.jsearch.date_posted' => 'week',
            'services.jsearch.num_pages' => 1,
            'services.jsearch.country' => '',
        ]);

        Http::preventStrayRequests();
    }

    private function provider(): JSearchJobSourceProvider
    {
        return $this->app->make(JSearchJobSourceProvider::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/jsearch/search_response.json')),
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

        $payload['data'] = array_values(array_filter(
            $payload['data'],
            static fn (array $job) => in_array($job['job_id'] ?? null, $ids, true)
        ));

        return $payload;
    }

    private function query(
        mixed $roles = 'Laravel Developer',
        ?int $limit = 10,
        ?string $location = 'Toronto, Ontario',
        bool $remoteOnly = false,
    ): JobSearchQuery {
        return new JobSearchQuery(
            roles: $roles,
            userId: 1,
            location: $location,
            remoteOnly: $remoteOnly,
            limit: $limit,
        );
    }

    public function test_it_normalizes_a_complete_posting(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['jsearch-9Xk1complete']))]);

        $jobs = $this->provider()->search($this->query());

        $this->assertCount(1, $jobs);

        /** @var NormalizedJob $job */
        $job = $jobs->first();

        $this->assertSame('jsearch', $job->sourceKey);
        $this->assertSame('jsearch-9Xk1complete', $job->externalId);
        $this->assertSame('Senior Laravel Developer', $job->title);
        $this->assertSame('Northwind Digital', $job->company);
        $this->assertSame('Toronto, Ontario, CA', $job->location);
        $this->assertStringContainsString('senior Laravel developer', $job->description);
        $this->assertSame('https://www.linkedin.com/jobs/view/9Xk1complete', $job->applicationUrl);
        $this->assertSame('2026-02-11 09:14:22', $job->postedAt->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(105000, $job->salaryMin);
        $this->assertSame(135001, $job->salaryMax); // 135000.5 rounded to whole units
        $this->assertSame('CAD', $job->currency);
    }

    public function test_it_sends_the_rapidapi_headers_and_free_text_query(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['jsearch-9Xk1complete']))]);

        $this->provider()->search($this->query());

        Http::assertSent(function (Request $request) {
            $this->assertStringStartsWith('https://jsearch.p.rapidapi.com/search', $request->url());
            $this->assertSame('test-rapidapi-key', $request->header('X-RapidAPI-Key')[0]);
            $this->assertSame('jsearch.p.rapidapi.com', $request->header('X-RapidAPI-Host')[0]);

            // JSearch takes one free-text query, so role and location are joined.
            $this->assertSame('Laravel Developer in Toronto, Ontario', $request['query']);
            $this->assertSame('week', $request['date_posted']);
            $this->assertSame('1', (string) $request['num_pages']);

            // Not requested, so not filtered on.
            $this->assertFalse(array_key_exists('remote_jobs_only', $request->data()));
            $this->assertFalse(array_key_exists('country', $request->data()));

            return true;
        });
    }

    public function test_the_query_is_the_bare_role_when_there_is_no_location(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['jsearch-9Xk1complete']))]);

        $this->provider()->search($this->query(location: null));

        Http::assertSent(fn (Request $request) => $request['query'] === 'Laravel Developer');
    }

    /** Unlike Adzuna, JSearch can actually filter on remote, so the hint is passed through. */
    public function test_remote_only_and_configured_country_are_passed_through(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['jsearch-9Xk1complete']))]);

        config(['services.jsearch.country' => 'CA']);

        $this->provider()->search($this->query(remoteOnly: true));

        Http::assertSent(function (Request $request) {
            $this->assertSame('true', (string) $request['remote_jobs_only']);
            $this->assertSame('ca', $request['country']);

            return true;
        });
    }

    public function test_hourly_and_monthly_pay_are_annualized(): void
    {
        Http::fake([self::URL => Http::response(
            $this->fixtureWithOnly(['jsearch-8Hp2hourly', 'jsearch-6Rn4monthly'])
        )]);

        $jobs = $this->provider()->search($this->query())->keyBy->externalId;

        // $60-75/hr over a 2080-hour year.
        $hourly = $jobs['jsearch-8Hp2hourly'];
        $this->assertSame(124800, $hourly->salaryMin);
        $this->assertSame(156000, $hourly->salaryMax);
        $this->assertSame('USD', $hourly->currency);

        // 5500-7000/month over twelve months, with the currency resolved from
        // job_country because the posting names none.
        $monthly = $jobs['jsearch-6Rn4monthly'];
        $this->assertSame(66000, $monthly->salaryMin);
        $this->assertSame(84000, $monthly->salaryMax);
        $this->assertSame('EUR', $monthly->currency);
    }

    public function test_inverted_bounds_are_swapped_rather_than_rejected(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['jsearch-7Qm3inverted']))]);

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertSame(64000, $job->salaryMin);
        $this->assertSame(82000, $job->salaryMax);
        $this->assertSame('GBP', $job->currency);
        $this->assertSame('Manchester, GB', $job->location);
    }

    /**
     * Reading an unrecognized period as annual would understate a rate by up to
     * ~2000x and skew the salary-based model tier, so the salary goes instead.
     */
    public function test_salary_is_dropped_when_the_period_is_unrecognized(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['jsearch-5St5oddperiod']))]);

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertSame('Laravel Engineer', $job->title);
        $this->assertFalse($job->hasSalary());
        $this->assertNull($job->currency);
    }

    public function test_salary_is_dropped_when_no_currency_can_be_resolved(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['jsearch-6Rn4monthly']))]);

        // An unmapped country and no reported currency: the figure would be read
        // as though it were already in the threshold currency.
        config(['services.jsearch.currencies' => []]);

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertSame('PHP Developer', $job->title);
        $this->assertFalse($job->hasSalary());
        $this->assertNull($job->currency);
    }

    public function test_it_handles_remote_and_salary_free_postings(): void
    {
        Http::fake([self::URL => Http::response(
            $this->fixtureWithOnly(['jsearch-8Hp2hourly', 'jsearch-4Tu6nosalary'])
        )]);

        $jobs = $this->provider()->search($this->query())->keyBy->externalId;

        // No city or state at all, so the flag carries the location.
        $this->assertSame('Remote — US', $jobs['jsearch-8Hp2hourly']->location);
        $this->assertTrue($jobs['jsearch-8Hp2hourly']->needsEnrichment()); // teaser description

        // No salary: the reported currency goes with it, and postedAt falls back
        // to the ISO datetime when the epoch timestamp is null.
        $noSalary = $jobs['jsearch-4Tu6nosalary'];
        $this->assertSame('Remote — Vancouver, British Columbia, CA', $noSalary->location);
        $this->assertFalse($noSalary->hasSalary());
        $this->assertNull($noSalary->currency);
        $this->assertSame('2026-02-06 08:30:00', $noSalary->postedAt->utc()->format('Y-m-d H:i:s'));
    }

    public function test_an_unusable_posting_is_skipped_without_failing_the_run(): void
    {
        Http::fake([self::URL => Http::response($this->fixture())]);

        $jobs = $this->provider()->search($this->query());

        // Seven results in, six out: the one with no title/apply link is dropped.
        $this->assertCount(6, $jobs);
        $this->assertNotContains('jsearch-3Uv7unusable', $jobs->pluck('externalId')->all());
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

    public function test_the_same_posting_is_not_returned_twice_across_roles(): void
    {
        Http::fake([self::URL => Http::response($this->fixtureWithOnly(['jsearch-9Xk1complete']))]);

        $jobs = $this->provider()->search(
            $this->query(roles: ['Laravel Developer', 'PHP Developer'], limit: 10)
        );

        Http::assertSentCount(2);
        $this->assertCount(1, $jobs);
    }

    public function test_a_non_ok_status_returned_with_a_200_yields_no_jobs(): void
    {
        Http::fake([self::URL => Http::response([
            'status' => 'ERROR',
            'error' => ['message' => 'Unsupported country'],
        ])]);

        $this->assertTrue($this->provider()->search($this->query())->isEmpty());
    }

    /**
     * An exhausted RapidAPI quota (429) or a bad key (403) must not read as
     * "no jobs today" — the orchestrator decides to log and skip the source.
     */
    public function test_transport_failure_throws_rather_than_looking_like_no_jobs(): void
    {
        Http::fake([self::URL => Http::response('Too Many Requests', 429)]);

        $this->expectException(RequestException::class);

        $this->provider()->search($this->query());
    }

    public function test_it_refuses_to_call_without_a_configured_key(): void
    {
        config(['services.jsearch.api_key' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSEARCH_RAPIDAPI_KEY');

        // No Http::fake at all: preventStrayRequests would also catch a call,
        // but the provider should never get that far.
        $this->provider()->search($this->query());
    }

    public function test_key_matches_the_config_registration(): void
    {
        $provider = $this->provider();

        $this->assertSame('jsearch', $provider->key());

        // The registry refuses a provider whose key() disagrees with the key it
        // is registered under, so resolving it proves the two line up.
        $this->assertSame(
            $provider->key(),
            $this->app->make(JobSourceRegistry::class)->get('jsearch')->key()
        );

        $this->assertSame(
            JSearchJobSourceProvider::class,
            config('job_sources.providers.jsearch.class')
        );
    }

    /**
     * Open decision #2 isn't confirmed and the API needs a paid key, so the
     * source must not join the fan-out until it's deliberately turned on.
     */
    public function test_the_source_ships_disabled(): void
    {
        $this->assertFalse($this->app->make(JobSourceRegistry::class)->isEnabled('jsearch'));
    }
}
