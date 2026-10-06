<?php

namespace Tests\Unit\Services\JobSources\Providers;

use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\JobSourceRegistry;
use App\Services\JobSources\NormalizedJob;
use App\Services\JobSources\Providers\LeverJobSourceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Normalization and query shaping for the Lever board source (task 8.4,
 * Requirement 3.1). Every test fakes the HTTP layer against a recorded board
 * payload — no request reaches Lever.
 */
class LeverJobSourceProviderTest extends TestCase
{
    private const BOARD_URL = 'https://api.lever.co/v0/postings/globex*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.lever.base_url' => 'https://api.lever.co/v0/postings',
            'services.lever.companies' => 'globex:Globex Inc',
            'services.lever.match_titles' => true,
            'services.lever.filter_by_location' => false,
            'services.lever.timeout' => 15,
        ]);

        Http::preventStrayRequests();
    }

    private function provider(): LeverJobSourceProvider
    {
        return $this->app->make(LeverJobSourceProvider::class);
    }

    /**
     * Lever answers with a bare JSON array.
     *
     * @return array<int, mixed>
     */
    private function fixture(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/lever/board_response.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return array<int, mixed>
     */
    private function fixtureWithOnly(array $ids): array
    {
        return array_values(array_filter(
            $this->fixture(),
            static fn (array $posting) => in_array($posting['id'] ?? null, $ids, true)
        ));
    }

    private function posting(string $suffix): string
    {
        return 'a1b2c3d4-0000-4000-8000-00000000000'.$suffix;
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
        Http::fake([self::BOARD_URL => Http::response($this->fixtureWithOnly([$this->posting('1')]))]);

        $jobs = $this->provider()->search($this->query());

        $this->assertCount(1, $jobs);

        /** @var NormalizedJob $job */
        $job = $jobs->first();

        $this->assertSame('lever', $job->sourceKey);
        $this->assertSame($this->posting('1'), $job->externalId);
        // Lever's title field is `text`, not `title`.
        $this->assertSame('Senior Laravel Developer', $job->title);
        $this->assertSame('Globex Inc', $job->company);
        $this->assertSame('Toronto, ON', $job->location);
        // hostedUrl (the posting page) is preferred over applyUrl.
        $this->assertSame('https://jobs.lever.co/globex/'.$this->posting('1'), $job->applicationUrl);
        // createdAt is a millisecond epoch.
        $this->assertSame('2026-02-11 09:14:22', $job->postedAt->utc()->format('Y-m-d H:i:s'));
        $this->assertFalse($job->hasSalary());
    }

    /**
     * Lever splits the JD across `descriptionPlain`, the titled `lists` blocks
     * and `additionalPlain`. Reading only the first would drop the requirements
     * section, which is the part scoring depends on most.
     */
    public function test_the_description_is_reassembled_from_every_section(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixtureWithOnly([$this->posting('1')]))]);

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertStringContainsString('lead our internal tooling group', $job->description);
        $this->assertStringContainsString("What we're looking for", $job->description);
        $this->assertStringContainsString('- 5+ years of PHP, ideally on Laravel', $job->description);
        $this->assertStringContainsString('- An untitled list block still contributes', $job->description);
        $this->assertStringContainsString('equal opportunity employer', $job->description);

        $this->assertStringNotContainsString('<ul>', $job->description);
        $this->assertStringNotContainsString('<li>', $job->description);

        // Full JD inline, so no enrichment round-trip needed.
        $this->assertFalse($job->needsEnrichment());
    }

    public function test_it_requests_the_configured_board_in_json_mode(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixtureWithOnly([$this->posting('1')]))]);

        $this->provider()->search($this->query());

        Http::assertSent(function (Request $request) {
            $this->assertStringStartsWith('https://api.lever.co/v0/postings/globex', $request->url());
            // Without mode=json the endpoint can answer with rendered HTML.
            $this->assertSame('json', $request['mode']);

            return true;
        });
    }

    public function test_multi_location_postings_report_every_location(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixtureWithOnly([$this->posting('2')]))]);

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertSame('Remote — Americas, Remote — EMEA', $job->location);
        $this->assertTrue($job->needsEnrichment()); // teaser body only
    }

    /** Unlike Greenhouse, Lever states remoteness explicitly in `workplaceType`. */
    public function test_remote_only_uses_the_workplace_type_flag(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixture())]);

        $jobs = $this->provider()->search($this->query(remoteOnly: true));

        $this->assertCount(1, $jobs);
        $this->assertSame('Laravel Developer, Payments', $jobs->first()->title);
    }

    public function test_titles_are_matched_against_the_requested_roles(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixture())]);

        $titles = $this->provider()->search($this->query())->pluck('title')->all();

        $this->assertSame([
            'Senior Laravel Developer',
            'Laravel Developer, Payments',
            'Contract Laravel Developer',
        ], $titles);

        $this->assertNotContains('Account Executive, Mid-Market', $titles);
    }

    public function test_apply_url_is_the_fallback_when_there_is_no_hosted_url(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixtureWithOnly([$this->posting('4')]))]);

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertSame(
            'https://jobs.lever.co/globex/'.$this->posting('4').'/apply',
            $job->applicationUrl
        );

        // createdAt of 0 is Lever saying "unknown", not the epoch.
        $this->assertNull($job->postedAt);

        // No workplaceType and a physical location: not remote.
        $this->assertSame('Vancouver, BC', $job->location);
    }

    public function test_an_unusable_posting_is_skipped_without_failing_the_board(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixture())]);

        $ids = $this->provider()->search($this->query())->pluck('externalId')->all();

        // Posting 5 matches on title but carries no URL at all.
        $this->assertNotContains($this->posting('5'), $ids);
        $this->assertCount(3, $ids);
    }

    /** Some boards nest the list instead of returning it bare. */
    public function test_it_reads_a_postings_wrapped_payload(): void
    {
        Http::fake([self::BOARD_URL => Http::response([
            'postings' => $this->fixtureWithOnly([$this->posting('1')]),
        ])]);

        $this->assertCount(1, $this->provider()->search($this->query()));
    }

    public function test_it_respects_the_query_limit(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixture())]);

        $this->assertCount(2, $this->provider()->search($this->query(limit: 2)));
    }

    public function test_key_matches_the_config_registration(): void
    {
        $provider = $this->provider();

        $this->assertSame('lever', $provider->key());

        $this->assertSame(
            $provider->key(),
            $this->app->make(JobSourceRegistry::class)->get('lever')->key()
        );

        $this->assertSame(
            LeverJobSourceProvider::class,
            config('job_sources.providers.lever.class')
        );
    }

    public function test_the_source_ships_enabled(): void
    {
        $this->assertTrue($this->app->make(JobSourceRegistry::class)->isEnabled('lever'));
    }
}
