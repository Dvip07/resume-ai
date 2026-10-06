<?php

namespace Tests\Unit\Services\JobSources\Providers;

use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\Providers\GreenhouseJobSourceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The behaviour AtsBoardJobSourceProvider shares between the Greenhouse and
 * Lever sources (task 8.4, Requirement 3.1): which boards get polled, how a
 * dead board is contained, and how the per-run ceiling and dedupe behave across
 * boards.
 *
 * Exercised through the Greenhouse subclass because the base class is abstract;
 * none of these assertions depend on Greenhouse's payload shape.
 */
class AtsBoardJobSourceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.greenhouse.base_url' => 'https://boards-api.greenhouse.io/v1/boards',
            'services.greenhouse.match_titles' => true,
            'services.greenhouse.filter_by_location' => false,
        ]);

        Http::preventStrayRequests();
    }

    private function provider(): GreenhouseJobSourceProvider
    {
        return $this->app->make(GreenhouseJobSourceProvider::class);
    }

    private function query(mixed $roles = 'Laravel Developer', ?int $limit = 10): JobSearchQuery
    {
        return new JobSearchQuery(roles: $roles, userId: 1, location: null, limit: $limit);
    }

    /**
     * A board with one usable posting under the given title.
     *
     * @return array<string, mixed>
     */
    private function board(int $id, string $title = 'Laravel Developer'): array
    {
        return [
            'jobs' => [[
                'id' => $id,
                'title' => $title,
                'absolute_url' => "https://boards.greenhouse.io/x/jobs/{$id}",
                'location' => ['name' => 'Toronto, ON'],
                'first_published' => '2026-02-01T12:00:00-05:00',
                'content' => '&lt;p&gt;A posting.&lt;/p&gt;',
            ]],
        ];
    }

    /**
     * A board belongs to one company, so nothing is polled until the user names
     * companies. That is what lets the source ship enabled.
     */
    public function test_no_configured_companies_means_no_requests(): void
    {
        config(['services.greenhouse.companies' => '']);

        $this->assertTrue($this->provider()->search($this->query())->isEmpty());

        Http::assertNothingSent();
    }

    public function test_it_polls_every_configured_board_in_order(): void
    {
        config(['services.greenhouse.companies' => 'acme,globex']);

        Http::fake([
            'https://boards-api.greenhouse.io/v1/boards/acme/jobs*' => Http::response($this->board(1)),
            'https://boards-api.greenhouse.io/v1/boards/globex/jobs*' => Http::response($this->board(2)),
        ]);

        $jobs = $this->provider()->search($this->query());

        Http::assertSentCount(2);
        $this->assertSame(['Acme', 'Globex'], $jobs->pluck('company')->all());
    }

    /**
     * A slug is not a company name: `globex-inc-1` would otherwise be written to
     * job_listings.company and shown to the user.
     */
    public function test_display_names_come_from_config_and_fall_back_to_the_slug(): void
    {
        config(['services.greenhouse.companies' => 'globex-inc-1:Globex Inc,acme-corp']);

        Http::fake([
            'https://boards-api.greenhouse.io/v1/boards/globex-inc-1/jobs*' => Http::response($this->board(1)),
            'https://boards-api.greenhouse.io/v1/boards/acme-corp/jobs*' => Http::response($this->board(2)),
        ]);

        $this->assertSame(
            ['Globex Inc', 'Acme Corp'],
            $this->provider()->search($this->query())->pluck('company')->all()
        );
    }

    /**
     * The comma-separated string is the .env-friendly form; config files can use
     * any of the array shapes instead.
     */
    public function test_company_config_accepts_array_shapes(): void
    {
        config(['services.greenhouse.companies' => [
            'acme',
            'globex' => 'Globex Inc',
            ['slug' => 'initech', 'company' => 'Initech LLC'],
        ]]);

        Http::fake([
            'https://boards-api.greenhouse.io/v1/boards/acme/jobs*' => Http::response($this->board(1)),
            'https://boards-api.greenhouse.io/v1/boards/globex/jobs*' => Http::response($this->board(2)),
            'https://boards-api.greenhouse.io/v1/boards/initech/jobs*' => Http::response($this->board(3)),
        ]);

        $this->assertSame(
            ['Acme', 'Globex Inc', 'Initech LLC'],
            $this->provider()->search($this->query())->pluck('company')->all()
        );
    }

    public function test_a_board_is_never_fetched_twice_in_one_run(): void
    {
        config(['services.greenhouse.companies' => 'acme, acme ,acme:Acme Corp']);

        Http::fake([
            'https://boards-api.greenhouse.io/v1/boards/acme/jobs*' => Http::response($this->board(1)),
        ]);

        $jobs = $this->provider()->search($this->query());

        Http::assertSentCount(1);
        $this->assertCount(1, $jobs);
        // Last display name for a slug wins.
        $this->assertSame('Acme Corp', $jobs->first()->company);
    }

    /** The slug lands in the URL path, so anything that could reshape it is refused. */
    public function test_unusable_slugs_are_dropped_rather_than_built_into_a_url(): void
    {
        config(['services.greenhouse.companies' => '../../etc,acme/jobs,,   ,acme']);

        Http::fake([
            'https://boards-api.greenhouse.io/v1/boards/acme/jobs*' => Http::response($this->board(1)),
        ]);

        $this->provider()->search($this->query());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_starts_with(
            $request->url(),
            'https://boards-api.greenhouse.io/v1/boards/acme/jobs'
        ));
    }

    /**
     * Companies churn ATS vendors, so a 404 board is an ordinary condition.
     * Every board is an independent target: one being unreadable must not cost
     * the run the others.
     */
    public function test_an_unreadable_board_is_skipped_without_failing_the_run(): void
    {
        config(['services.greenhouse.companies' => 'gone,broken,acme']);

        Http::fake([
            'https://boards-api.greenhouse.io/v1/boards/gone/jobs*' => Http::response('Not Found', 404),
            'https://boards-api.greenhouse.io/v1/boards/broken/jobs*' => Http::response('<html>nope</html>', 200),
            'https://boards-api.greenhouse.io/v1/boards/acme/jobs*' => Http::response($this->board(1)),
        ]);

        $jobs = $this->provider()->search($this->query());

        Http::assertSentCount(3);
        $this->assertCount(1, $jobs);
        $this->assertSame('Acme', $jobs->first()->company);
    }

    /** The ceiling bounds one fan-out's cost, so later boards go unrequested. */
    public function test_the_limit_stops_later_boards_from_being_requested(): void
    {
        config(['services.greenhouse.companies' => 'acme,globex']);

        Http::fake([
            'https://boards-api.greenhouse.io/v1/boards/acme/jobs*' => Http::response($this->board(1)),
            'https://boards-api.greenhouse.io/v1/boards/globex/jobs*' => Http::response($this->board(2)),
        ]);

        $this->assertCount(1, $this->provider()->search($this->query(limit: 1)));

        Http::assertSentCount(1);
    }

    /**
     * Two companies can post the same role, and board ids are only unique within
     * a board — so identical ids across boards must stay distinct jobs.
     */
    public function test_identical_ids_on_different_boards_are_kept_as_separate_jobs(): void
    {
        config(['services.greenhouse.companies' => 'acme,globex']);

        Http::fake([
            'https://boards-api.greenhouse.io/v1/boards/acme/jobs*' => Http::response($this->board(7)),
            'https://boards-api.greenhouse.io/v1/boards/globex/jobs*' => Http::response($this->board(7)),
        ]);

        $jobs = $this->provider()->search($this->query());

        $this->assertCount(2, $jobs);
        $this->assertSame(['Acme', 'Globex'], $jobs->pluck('company')->all());

        // Different employers, so the dedupe hash must differ too — the
        // orchestrator (task 8.7) would otherwise collapse them into one row.
        $this->assertNotSame($jobs[0]->dedupeHash(), $jobs[1]->dedupeHash());
    }

    /** Any one of the user's roles matching is enough. */
    public function test_a_posting_matches_when_any_role_matches(): void
    {
        config(['services.greenhouse.companies' => 'acme']);

        Http::fake([
            'https://boards-api.greenhouse.io/v1/boards/acme/jobs*' => Http::response([
                'jobs' => [
                    $this->board(1, 'Staff Backend Engineer, Payments')['jobs'][0],
                    $this->board(2, 'Senior Laravel Developer')['jobs'][0],
                    $this->board(3, 'Recruiting Coordinator')['jobs'][0],
                ],
            ]),
        ]);

        $titles = $this->provider()
            ->search($this->query(roles: ['Laravel Developer', 'Backend Engineer']))
            ->pluck('title')
            ->all();

        $this->assertSame(['Staff Backend Engineer, Payments', 'Senior Laravel Developer'], $titles);
    }
}
