<?php

namespace Tests\Unit\Services\JobSources\Providers;

use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\JobSourceRegistry;
use App\Services\JobSources\NormalizedJob;
use App\Services\JobSources\Providers\GreenhouseJobSourceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Normalization and query shaping for the Greenhouse board source (task 8.4,
 * Requirement 3.1). Every test fakes the HTTP layer against a recorded board
 * payload — no request reaches Greenhouse.
 */
class GreenhouseJobSourceProviderTest extends TestCase
{
    private const BOARD_URL = 'https://boards-api.greenhouse.io/v1/boards/acme/jobs*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.greenhouse.base_url' => 'https://boards-api.greenhouse.io/v1/boards',
            'services.greenhouse.companies' => 'acme:Acme Corp',
            'services.greenhouse.include_content' => true,
            'services.greenhouse.match_titles' => true,
            'services.greenhouse.filter_by_location' => false,
            'services.greenhouse.timeout' => 15,
        ]);

        Http::preventStrayRequests();
    }

    private function provider(): GreenhouseJobSourceProvider
    {
        return $this->app->make(GreenhouseJobSourceProvider::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/greenhouse/board_response.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    /**
     * The fixture's board with only the jobs named in `$ids` kept, so a test can
     * isolate one payload case without a second fixture file.
     *
     * @param list<int> $ids
     *
     * @return array<string, mixed>
     */
    private function fixtureWithOnly(array $ids): array
    {
        $payload = $this->fixture();

        $payload['jobs'] = array_values(array_filter(
            $payload['jobs'],
            static fn (array $job) => in_array($job['id'] ?? null, $ids, true)
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
        Http::fake([self::BOARD_URL => Http::response($this->fixtureWithOnly([4567890]))]);

        $jobs = $this->provider()->search($this->query());

        $this->assertCount(1, $jobs);

        /** @var NormalizedJob $job */
        $job = $jobs->first();

        $this->assertSame('greenhouse', $job->sourceKey);
        $this->assertSame('4567890', $job->externalId);
        $this->assertSame('Senior Laravel Developer, Platform', $job->title);
        // Not in the payload — a board's postings never name the employer, so it
        // comes from the configured display name.
        $this->assertSame('Acme Corp', $job->company);
        $this->assertSame('Toronto, ON, Canada', $job->location);
        $this->assertSame('https://boards.greenhouse.io/acme/jobs/4567890', $job->applicationUrl);
        // first_published wins over updated_at.
        $this->assertSame('2026-02-01 17:30:00', $job->postedAt->utc()->format('Y-m-d H:i:s'));

        // Boards expose no structured pay; omitting it is safer than guessing.
        $this->assertFalse($job->hasSalary());
        $this->assertNull($job->currency);
    }

    /**
     * Greenhouse serves `content` as escaped HTML, and scoring/tailoring read
     * the description as text — leaving markup or entities in it would waste
     * prompt tokens and read badly.
     */
    public function test_escaped_html_content_becomes_plain_text(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixtureWithOnly([4567890]))]);

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertStringNotContainsString('&lt;', $job->description);
        $this->assertStringNotContainsString('<p>', $job->description);
        $this->assertStringNotContainsString('&#39;', $job->description);
        $this->assertStringNotContainsString('&nbsp;', $job->description);

        $this->assertStringContainsString('senior Laravel developer', $job->description);
        $this->assertStringContainsString("What you'll do:", $job->description);
        $this->assertStringContainsString('- Design queue-backed workflows in Laravel 12', $job->description);
        $this->assertStringContainsString('Requirements: 5+ years of PHP', $job->description);

        // The full inline JD is why this source is worth polling: no enrichment
        // round-trip needed (Requirement 3.4).
        $this->assertFalse($job->needsEnrichment());
    }

    public function test_it_requests_the_configured_board_with_inline_content(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixtureWithOnly([4567890]))]);

        $this->provider()->search($this->query());

        Http::assertSent(function (Request $request) {
            $this->assertStringStartsWith(
                'https://boards-api.greenhouse.io/v1/boards/acme/jobs',
                $request->url()
            );
            $this->assertSame('true', (string) $request['content']);

            // Board APIs accept no search parameters at all.
            $this->assertFalse(array_key_exists('location', $request->data()));

            return true;
        });
    }

    public function test_location_falls_back_to_deduplicated_office_names(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixtureWithOnly([4567892]))]);

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertSame('Toronto, New York', $job->location);

        // Empty content, so this one does need enrichment.
        $this->assertNull($job->description);
        $this->assertTrue($job->needsEnrichment());

        // No first_published: updated_at carries the date.
        $this->assertSame('2026-01-28 16:15:00', $job->postedAt->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * A board returns every open role, so without title matching a search for
     * "Laravel Developer" would put the company's warehouse opening into the
     * pipeline and pay to score it.
     */
    public function test_titles_are_matched_against_the_requested_roles(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixture())]);

        $titles = $this->provider()->search($this->query())->pluck('title')->all();

        $this->assertSame([
            'Senior Laravel Developer, Platform',
            'Laravel Developer (Remote)',
            'Staff Laravel Developer',
        ], $titles);

        $this->assertNotContains('Warehouse Associate', $titles);
    }

    public function test_title_matching_can_be_turned_off(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixture())]);

        config(['services.greenhouse.match_titles' => false]);

        $titles = $this->provider()->search($this->query())->pluck('title')->all();

        $this->assertContains('Warehouse Associate', $titles);
    }

    /** Greenhouse has no remote flag, so remoteness is read out of the location. */
    public function test_remote_only_keeps_only_postings_the_board_calls_remote(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixture())]);

        $jobs = $this->provider()->search($this->query(remoteOnly: true));

        $this->assertCount(1, $jobs);
        $this->assertSame('Laravel Developer (Remote)', $jobs->first()->title);
        $this->assertSame('2026-02-09 14:00:00', $jobs->first()->postedAt->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * Off by default because board location strings are free text, but when it
     * is enabled a remote posting still survives — it's available from anywhere.
     */
    public function test_optional_location_filtering_exempts_remote_postings(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixture())]);

        config(['services.greenhouse.filter_by_location' => true]);

        $titles = $this->provider()
            ->search($this->query(location: 'Vancouver, British Columbia'))
            ->pluck('title')
            ->all();

        $this->assertSame(['Laravel Developer (Remote)'], $titles);
    }

    public function test_an_unusable_posting_is_skipped_without_failing_the_board(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixture())]);

        $ids = $this->provider()->search($this->query())->pluck('externalId')->all();

        // Job 4567894 matches on title but has no apply URL, so it can't be
        // enriched or applied to — dropped, without losing the rest.
        $this->assertNotContains('4567894', $ids);
        $this->assertCount(3, $ids);
    }

    public function test_it_respects_the_query_limit(): void
    {
        Http::fake([self::BOARD_URL => Http::response($this->fixture())]);

        $this->assertCount(2, $this->provider()->search($this->query(limit: 2)));
    }

    public function test_key_matches_the_config_registration(): void
    {
        $provider = $this->provider();

        $this->assertSame('greenhouse', $provider->key());

        // The registry refuses a provider whose key() disagrees with the key it
        // is registered under, so resolving it proves the two line up.
        $this->assertSame(
            $provider->key(),
            $this->app->make(JobSourceRegistry::class)->get('greenhouse')->key()
        );

        $this->assertSame(
            GreenhouseJobSourceProvider::class,
            config('job_sources.providers.greenhouse.class')
        );
    }

    /**
     * No API key is involved and an unconfigured board list makes no requests,
     * so unlike JSearch this source can join the fan-out out of the box.
     */
    public function test_the_source_ships_enabled(): void
    {
        $this->assertTrue($this->app->make(JobSourceRegistry::class)->isEnabled('greenhouse'));
    }
}
