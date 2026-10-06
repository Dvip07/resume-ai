<?php

namespace Tests\Feature\Pipeline;

use App\Enums\ModelUsageOutcome;
use App\Enums\PipelineStage;
use App\Enums\RecommendedAction;
use App\Jobs\DiscoverJobsForUser;
use App\Jobs\TailorResume;
use App\Models\JobListing;
use App\Models\JobScore;
use App\Models\ModelUsageLog;
use App\Models\User;
use App\Models\UserAutomationSetting;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `Discover → Enrich → Score` as one run (task 10.6, Requirement 11.3).
 *
 * Everything the stages talk to is faked at the *transport* boundary and
 * nowhere else: the job source's API, the posting's career page, its robots.txt
 * and OpenRouter are all `Http::fake`d, and no collaborator is stubbed. The
 * queue is `sync` under phpunit.xml, so `DiscoverJobsForUser` really dispatches
 * `EnrichJobDescription` and that job really runs — the link between the stages
 * is the thing under test, which is exactly what the per-stage suites cannot
 * cover:
 *
 *  - JobDiscoveryOrchestratorTest — fan-out, dedupe and upsert, with fake providers
 *  - DiscoverJobsForUserTest — query construction and which rows get an enrichment dispatch
 *  - EnrichJobDescriptionTest / JobEnrichmentServiceTest — fetch, extraction and dead ends
 *  - JobScoringServiceTest — both prompts, JSON parse failure and the stricter retry
 *  - ScoreJobListingTest — persistence, versioning and the `pipeline_stage` branch
 *
 * Each of those replaces the stage above or below it with a double. Here the
 * real chain runs end to end, so the assertions are about what only the whole
 * chain can get wrong: that the description enrichment fetched is the text
 * scoring is prompted with, and that a row reaches the stage the rating and the
 * user's settings imply.
 *
 * Since task 15.10 the hand-offs are production behaviour all the way through:
 * discovery dispatches enrichment, enrichment dispatches scoring, and scoring
 * dispatches tailoring. Only the last of those is held at the queue boundary —
 * `Queue::fake([TailorResume::class])` records it instead of running it, so this
 * file keeps its subject (three stages and the two joins between them) without
 * pulling a LaTeX engine and two more prompts into every assertion. The
 * `Tailor → Apply` half of the chain is {@see \Tests\Feature\Jobs\PipelineChainTest}'s.
 *
 * Validates: Requirements 5.1, 5.2, 5.3, 5.4, 5.5, 11.3
 */
class DiscoverEnrichScoreChainTest extends TestCase
{
    use RefreshDatabase;

    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

    private const ADZUNA_ID = '55501';

    /** Where the posting's full description lives, on its own host. */
    private const APPLICATION_URL = 'https://careers.example.com/jobs/55501';

    /**
     * A phrase that appears only in the enriched page, never in the aggregator's
     * teaser — so a prompt containing it proves the two stages are connected.
     */
    private const ENRICHED_MARKER = 'You will own the deployment pipeline end to end';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // One source, so the fan-out is a single faked endpoint and no other
        // provider (ATS boards, the LLM web search) can reach for the network.
        config([
            'job_sources.providers' => [
                'adzuna' => ['class' => \App\Services\JobSources\Providers\AdzunaJobSourceProvider::class],
            ],
            'job_sources.min_description_length' => 400,
            'services.adzuna.app_id' => 'test-app-id',
            'services.adzuna.app_key' => 'test-app-key',
            'services.adzuna.base_url' => 'https://api.adzuna.com/v1/api/jobs',
            'services.adzuna.country' => 'ca',
            'services.adzuna.currencies' => ['ca' => 'CAD'],
            'services.adzuna.results_per_page' => 5,
            'services.adzuna.daily_limit' => null,

            'enrichment.min_description_length' => 400,
            'enrichment.robots.enabled' => true,

            // Deterministic routing and credentials, per the convention in
            // ModelRouterServiceTest: the same fake key, and one model per tier
            // with a single attempt each, so the number of faked completions a
            // test queues is the number of prompts it expects.
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.referer' => 'https://resume-ai.test',
            'services.openrouter.title' => 'Resume AI',
            'services.openrouter.default_tier' => 'cheap',
            'services.openrouter.tiers' => [
                'cheap' => ['vendor/cheap-1'],
                'standard' => ['vendor/standard-1'],
                'premium' => ['vendor/premium-1'],
            ],
            'services.openrouter.max_attempts_per_model' => 1,
            'services.openrouter.retry_delay_ms' => 0,

            'scoring.max_attempts' => 2,
            'scoring.auto_apply_star_threshold' => 4,
        ]);

        Http::preventStrayRequests();

        // Everything up to and including scoring runs for real on the sync
        // queue; tailoring is recorded rather than run. See the class docblock.
        Queue::fake([TailorResume::class]);

        $this->user = User::factory()->create();
        $this->profile();
    }

    /* ------------------------------------------------------------------
     | The chain
     | ----------------------------------------------------------------- */

    public function test_a_strong_match_travels_from_discovery_through_enrichment_into_tailoring(): void
    {
        $this->settings(enabled: true, threshold: 4);
        $this->fakeTransport([
            $this->fitAnalysisJson(),
            $this->ratingJson(['stars' => 5, 'recommendedAction' => 'auto_apply']),
        ]);

        $this->discover();

        // Discovery inserted the row; enrichment and scoring both ran inline,
        // each dispatched by the stage before it (Requirement 11.3).
        $listing = JobListing::sole();
        $this->assertSame('adzuna', $listing->source_key);
        $this->assertSame(self::ADZUNA_ID, $listing->api_id);
        $this->assertStringContainsString(self::ENRICHED_MARKER, $listing->description);
        $this->assertGreaterThanOrEqual(400, mb_strlen($listing->description));

        // Requirement 5.2: the rating clears the user's threshold and the user
        // has automation on, so the row is queued for tailoring — and the job
        // really was dispatched, which is the last link this file covers.
        $this->assertSame(PipelineStage::Tailoring, $listing->refresh()->pipeline_stage);
        Queue::assertPushed(
            TailorResume::class,
            fn (TailorResume $job) => $job->jobListingId === $listing->id
                && $job->userId === $this->user->id,
        );

        // Requirement 5.4: one audit row carrying both prompts' output.
        $score = JobScore::latestAttempt($listing->id, $this->user->id);
        $this->assertNotNull($score);
        $this->assertSame(1, $score->attempt_number);
        $this->assertSame(5, $score->stars);
        $this->assertSame(RecommendedAction::AutoApply, $score->recommended_action);
        $this->assertSame(['Kubernetes'], $score->fit_analysis['missingSkills']);
        $this->assertStringContainsString('requiredSkills', $score->raw_prompt_1_output);
        $this->assertStringContainsString('stars', $score->raw_prompt_2_output);

        // Both prompts were real calls, attributed to the listing (Req 4.6).
        $usage = ModelUsageLog::query()->where('related_id', $listing->id)->get();
        $this->assertCount(2, $usage);
        $this->assertSame(
            ['jd_fit_analysis', 'jd_rating'],
            $usage->pluck('task_type')->sort()->values()->all()
        );
        $this->assertTrue($usage->every(fn (ModelUsageLog $log) => $log->outcome === ModelUsageOutcome::Success));

        // Three hosts, one run: the aggregator, the posting's page, the model.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.adzuna.com'));
        Http::assertSent(fn (Request $r) => $r->url() === self::APPLICATION_URL);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::OPENROUTER_URL));
    }

    /**
     * The join the per-stage suites cannot see: what enrichment stored is what
     * prompt 1 is asked about. If the stages were wired to different columns, or
     * scoring ran before enrichment, this is where it shows.
     */
    public function test_scoring_is_prompted_with_the_enriched_description_not_the_aggregators_teaser(): void
    {
        $this->settings(enabled: true, threshold: 4);
        $this->fakeTransport([$this->fitAnalysisJson(), $this->ratingJson()]);

        $this->discover();

        Http::assertSent(function (Request $r) {
            if (! str_starts_with($r->url(), self::OPENROUTER_URL)) {
                return false;
            }

            $prompt = $r->data()['messages'][1]['content'];

            return str_contains($prompt, self::ENRICHED_MARKER)
                && ! str_contains($prompt, 'Apply on our site for the full description');
        });
    }

    public function test_a_rating_below_the_users_threshold_is_kept_without_tailoring(): void
    {
        $this->settings(enabled: true, threshold: 4);
        $this->fakeTransport([
            $this->fitAnalysisJson(),
            $this->ratingJson(['stars' => 2, 'recommendedAction' => 'store_only']),
        ]);

        $this->discover();
        $listing = JobListing::sole();

        // Requirement 5.3: scored, kept, and nothing applied for automatically.
        $this->assertSame(PipelineStage::StoreOnly, $listing->refresh()->pipeline_stage);
        $this->assertSame(2, JobScore::latestAttempt($listing->id, $this->user->id)?->stars);
        Queue::assertNotPushed(TailorResume::class);
    }

    /**
     * Requirement 5.5 through the whole chain: a model that will not return
     * usable JSON, even when asked again with the stricter instruction, leaves an
     * enriched listing flagged for a human rather than scored or dropped.
     */
    public function test_a_listing_the_model_cannot_score_ends_at_needs_review(): void
    {
        $this->settings(enabled: true, threshold: 4);

        // Two responses because `scoring.max_attempts` is 2 and each model gets
        // one attempt: the normal prompt, then the stricter retry. Neither is
        // parseable, so prompt 2 is never reached.
        $this->fakeTransport([
            'I am afraid I cannot analyse this posting.',
            'Still not JSON, sorry.',
        ]);

        $this->discover();
        $listing = JobListing::sole();

        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);
        $this->assertNull(JobScore::latestAttempt($listing->id, $this->user->id));

        // The enriched description survives, so a re-score costs no fetch.
        $this->assertStringContainsString(self::ENRICHED_MARKER, $listing->description);

        // Both failed attempts were recorded, and only the first prompt ran.
        $usage = ModelUsageLog::query()->where('related_id', $listing->id)->get();
        $this->assertCount(2, $usage);
        $this->assertSame(['jd_fit_analysis'], $usage->pluck('task_type')->unique()->all());
        $this->assertTrue($usage->every(fn (ModelUsageLog $log) => $log->outcome === ModelUsageOutcome::Failure));
    }

    /* ------------------------------------------------------------------
     | Driving the stages
     | ----------------------------------------------------------------- */

    /**
     * Discovery — which, on the sync queue, pulls the rest of the chain in
     * behind it: enrichment runs, dispatches scoring, and scoring runs.
     * Tailoring is the one hop that is recorded instead of run.
     */
    private function discover(): void
    {
        DiscoverJobsForUser::dispatch($this->user->id);
    }

    /* ------------------------------------------------------------------
     | Faked transport
     | ----------------------------------------------------------------- */

    /**
     * Every outbound request the chain makes, and nothing else.
     *
     * Stubs are matched in insertion order, so the posting's `robots.txt` is
     * registered before the wildcard for its host.
     *
     * @param list<string> $modelContents completion bodies, in the order the
     *                                    scoring prompts will consume them
     */
    private function fakeTransport(array $modelContents): void
    {
        $completions = Http::sequence();

        foreach ($modelContents as $content) {
            $completions->push($this->completionBody($content));
        }

        Http::fake([
            'api.adzuna.com/*' => Http::response($this->adzunaPayload()),
            // No published policy: an explicit allow under the standard, and the
            // common case for a company careers subdomain.
            'careers.example.com/robots.txt' => Http::response('', 404),
            'careers.example.com/*' => Http::response($this->postingHtml(), 200, ['Content-Type' => 'text/html']),
            self::OPENROUTER_URL => $completions,
        ]);
    }

    /**
     * One aggregator result: a real posting with a salary range and the
     * two-sentence teaser that makes it a candidate for enrichment.
     *
     * @return array<string, mixed>
     */
    private function adzunaPayload(): array
    {
        return [
            'count' => 1,
            'results' => [
                [
                    'id' => self::ADZUNA_ID,
                    'title' => 'Senior Laravel Engineer',
                    'company' => ['display_name' => 'Northwind Digital'],
                    'location' => ['display_name' => 'Toronto, Ontario'],
                    'description' => 'Backend engineer wanted. Apply on our site for the full description.',
                    'redirect_url' => self::APPLICATION_URL,
                    'created' => '2026-02-11T09:14:22Z',
                    'salary_min' => 105000.0,
                    'salary_max' => 135000.0,
                ],
            ],
        ];
    }

    /**
     * The career page enrichment fetches: a `.job-description` container (one of
     * the configured selectors) holding text well past the length floor, plus
     * navigation the strip list should remove.
     */
    private function postingHtml(): string
    {
        $body = self::ENRICHED_MARKER.'. '
            .str_repeat(
                'We are looking for a senior Laravel engineer to own our API platform, '
                .'working across queues, Eloquent and PostgreSQL. ',
                6
            )
            .'Required: PHP 8, Laravel, PostgreSQL, Kubernetes.';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><title>Senior Laravel Engineer — Northwind Digital</title></head>
        <body>
        <nav>Home Careers Contact</nav>
        <div class="job-description"><p>{$body}</p></div>
        <footer>© Northwind Digital</footer>
        </body>
        </html>
        HTML;
    }

    /**
     * An OpenRouter chat-completion response, shaped as in ModelRouterServiceTest.
     *
     * @return array<string, mixed>
     */
    private function completionBody(string $content): array
    {
        return [
            'id' => 'gen-'.md5($content),
            'model' => 'vendor/standard-1',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120, 'cost' => 0.0021],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function fitAnalysisJson(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'requiredSkills' => ['PHP', 'Laravel', 'PostgreSQL', 'Kubernetes'],
            'seniority' => 'senior',
            'matchedSkills' => ['PHP', 'Laravel', 'PostgreSQL'],
            'missingSkills' => ['Kubernetes'],
            'gapSummary' => 'Strong Laravel and PostgreSQL background; no container orchestration evidence.',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function ratingJson(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'stars' => 5,
            'rationale' => 'Matches the core stack; only Kubernetes is missing.',
            'recommendedAction' => 'auto_apply',
        ], $overrides));
    }

    /* ------------------------------------------------------------------
     | Fixtures
     | ----------------------------------------------------------------- */

    private function profile(): UserProfile
    {
        return UserProfile::create([
            'user_id' => $this->user->id,
            'skills' => ['primary' => ['PHP', 'Laravel', 'PostgreSQL'], 'secondary' => ['Redis']],
            'suggested_roles' => ['Senior Laravel Engineer'],
            'location' => ['city' => 'Toronto', 'state' => 'Ontario', 'country' => 'Canada'],
            'linkedin_url' => '',
            'github_url' => '',
            'portfolio_url' => '',
            'experience' => [['company' => 'Globex', 'position' => 'Backend Engineer', 'duration' => '4 years']],
            'education' => [],
            'parsed_keywords' => 'php laravel postgresql queues',
            'resume_text' => 'Backend engineer with four years of Laravel and PostgreSQL experience.',
        ]);
    }

    private function settings(bool $enabled, int $threshold): void
    {
        UserAutomationSetting::create([
            'auto_apply_enabled' => $enabled,
            'auto_apply_star_threshold' => $threshold,
        ] + UserAutomationSetting::defaultsFor($this->user->id));
    }
}
