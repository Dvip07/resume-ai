<?php

namespace Tests\Unit\Services\JobSources\Providers;

use App\Exceptions\ModelRouterException;
use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\JobSourceRegistry;
use App\Services\JobSources\NormalizedJob;
use App\Services\JobSources\Providers\LlmWebSearchJobSourceProvider;
use App\Services\ModelCompletionResult;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Normalization, prompt/routing shape and lead-hygiene rules for the LLM
 * web-search source (task 8.5, Requirement 3.1).
 *
 * ModelRouterService is replaced by a recording double, so no request reaches
 * OpenRouter and no key is needed: the router's own transport is already covered
 * by the task 7 tests, and what matters here is what this provider asks for and
 * what it does with the answer.
 */
class LlmWebSearchJobSourceProviderTest extends TestCase
{
    private FakeModelRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.llm_job_search.tier' => 'web_search',
            'services.llm_job_search.task_type' => 'job_search',
            'services.llm_job_search.max_results' => 15,
            'services.llm_job_search.max_posted_age_days' => 30,
            'services.llm_job_search.trust_dates' => true,
        ]);

        // The fixture's dates are written against this instant.
        Carbon::setTestNow('2026-02-12 12:00:00');

        $this->router = new FakeModelRouter();
        $this->app->instance(ModelRouterService::class, $this->router);

        // Nothing in this source may reach the network directly: it only ever
        // talks to the router.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function provider(): LlmWebSearchJobSourceProvider
    {
        return $this->app->make(LlmWebSearchJobSourceProvider::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/llm_search/search_response.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    /**
     * The fixture's results narrowed to the postings at `$titles`, so a test can
     * isolate one payload case without another fixture file.
     *
     * @param list<string> $titles
     *
     * @return array<string, mixed>
     */
    private function fixtureWithOnly(array $titles): array
    {
        $payload = $this->fixture();

        $payload['jobs'] = array_values(array_filter(
            $payload['jobs'],
            static fn (array $job) => in_array($job['title'] ?? null, $titles, true)
        ));

        return $payload;
    }

    private function query(
        mixed $roles = 'Laravel Developer',
        ?int $limit = 20,
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

    public function test_it_normalizes_a_complete_lead(): void
    {
        $this->router->willReturn($this->fixtureWithOnly(['Senior Laravel Developer']));

        $jobs = $this->provider()->search($this->query());

        $this->assertCount(1, $jobs);

        /** @var NormalizedJob $job */
        $job = $jobs->first();

        $this->assertSame('llm_search', $job->sourceKey);
        $this->assertSame('Senior Laravel Developer', $job->title);
        $this->assertSame('Northwind Digital', $job->company);
        $this->assertSame('Toronto, ON', $job->location);
        $this->assertSame(
            'https://careers.northwinddigital.com/jobs/senior-laravel-developer',
            $job->applicationUrl
        );
        $this->assertSame('2026-02-10', $job->postedAt->format('Y-m-d'));
        $this->assertSame(105000, $job->salaryMin);
        $this->assertSame(135001, $job->salaryMax); // 135000.5 rounded to whole units
        $this->assertSame('CAD', $job->currency);   // lowercase in the payload
    }

    /**
     * The defining property of this source: a search hit is a lead, so the model
     * never gets to supply the text that will be scored and tailored against.
     * The null description is what routes the job through enrichment
     * (Requirement 3.4) before it is scored.
     */
    public function test_a_model_authored_description_is_discarded_and_the_lead_needs_enrichment(): void
    {
        $payload = $this->fixtureWithOnly(['Senior Laravel Developer']);

        // The fixture already carries one; make the intent explicit and long
        // enough that it would otherwise pass the enrichment threshold.
        $payload['jobs'][0]['description'] = str_repeat('Plausible invented job description. ', 40);

        $this->router->willReturn($payload);

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertNull($job->description);
        $this->assertTrue($job->needsEnrichment());
    }

    /**
     * A search hit has no source-side id, so dedupe has to fall to the
     * title+company+location hash (Requirement 3.3). Inventing an id here would
     * make every re-run look like a brand-new posting.
     */
    public function test_leads_carry_no_external_id(): void
    {
        $this->router->willReturn($this->fixture());

        $externalIds = $this->provider()->search($this->query())->pluck('externalId')->unique();

        $this->assertSame([null], $externalIds->all());
    }

    public function test_it_pins_the_web_search_tier_and_asks_for_structured_output(): void
    {
        $this->router->willReturn($this->fixtureWithOnly(['Senior Laravel Developer']));

        $this->provider()->search($this->query());

        // One call per run covers every role: web search is billed per request.
        $this->assertCount(1, $this->router->calls);

        $call = $this->router->calls[0];

        $this->assertSame('job_search', $call['taskType']);
        $this->assertSame('web_search', $call['tierOverride']);

        // Routing here is a capability decision, so no salary/complexity signal
        // is fabricated to reach the tier.
        $this->assertFalse($call['context']->hasSalary());
        $this->assertSame(0, $call['context']->complexityScore);

        $schema = $call['jsonSchema'];
        $item = $schema['schema']['properties']['jobs']['items'];

        $this->assertSame(['jobs'], $schema['schema']['required']);
        $this->assertTrue($schema['strict']);
        $this->assertFalse($item['additionalProperties']);
        // Strict mode requires every property to be listed as required, with
        // optionality expressed as a nullable type.
        $this->assertSame(array_keys($item['properties']), $item['required']);
        $this->assertSame(['number', 'null'], $item['properties']['salary_min']['type']);

        // No description field is requested at all.
        $this->assertArrayNotHasKey('description', $item['properties']);
    }

    public function test_the_prompt_carries_the_roles_location_and_result_ceiling(): void
    {
        $this->router->willReturn($this->fixtureWithOnly(['Senior Laravel Developer']));

        $this->provider()->search(
            $this->query(roles: ['Laravel Developer', 'Backend Engineer'], limit: 6)
        );

        $prompt = $this->router->lastUserMessage();

        $this->assertStringContainsString('Laravel Developer, Backend Engineer', $prompt);
        $this->assertStringContainsString('Toronto, Ontario', $prompt);
        $this->assertStringContainsString('at most 6 postings', $prompt);
        $this->assertStringContainsString('last 30 days', $prompt);
        $this->assertStringNotContainsString('Remote positions only', $prompt);
    }

    public function test_a_missing_location_is_stated_as_anywhere(): void
    {
        $this->router->willReturn($this->fixtureWithOnly(['Senior Laravel Developer']));

        $this->provider()->search($this->query(location: null));

        $this->assertStringContainsString('Location: anywhere', $this->router->lastUserMessage());
    }

    public function test_remote_only_is_passed_through_as_a_constraint(): void
    {
        $this->router->willReturn($this->fixtureWithOnly(['Senior Laravel Developer']));

        $this->provider()->search($this->query(remoteOnly: true));

        $this->assertStringContainsString('Remote positions only', $this->router->lastUserMessage());
    }

    /**
     * The configured ceiling bounds what one call asks for; the query's own
     * limit bounds it further when it is smaller. Whichever wins is enforced on
     * the way out too, since a model asked for 3 may well return 10.
     */
    public function test_the_result_ceiling_is_the_lower_of_the_query_limit_and_the_config(): void
    {
        $this->router->willReturn($this->fixture());

        $jobs = $this->provider()->search($this->query(limit: 3));

        $this->assertCount(3, $jobs);
        $this->assertStringContainsString('at most 3 postings', $this->router->lastUserMessage());

        config(['services.llm_job_search.max_results' => 2]);

        $jobs = $this->provider()->search($this->query(limit: 25));

        $this->assertCount(2, $jobs);
        $this->assertStringContainsString('at most 2 postings', $this->router->lastUserMessage());
    }

    /**
     * A date the model can't have read off a live posting (in the future, or
     * older than the window the prompt asked for) is dropped rather than stored:
     * null already means "the source didn't say".
     */
    public function test_implausible_posted_dates_are_dropped(): void
    {
        $this->router->willReturn($this->fixtureWithOnly([
            'Platform Engineer',      // posted_at in the future
            'Full Stack Developer',   // posted_at older than the 30-day window
        ]));

        $jobs = $this->provider()->search($this->query())->keyBy->title;

        $this->assertCount(2, $jobs);
        $this->assertNull($jobs['Platform Engineer']->postedAt);
        $this->assertNull($jobs['Full Stack Developer']->postedAt);

        // The rest of the posting survives — only the unreliable field goes.
        $this->assertSame(96000, $jobs['Platform Engineer']->salaryMin);
    }

    public function test_dates_can_be_distrusted_entirely_by_config(): void
    {
        config(['services.llm_job_search.trust_dates' => false]);

        $this->router->willReturn($this->fixtureWithOnly(['Senior Laravel Developer']));

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertNull($job->postedAt);
        $this->assertSame('Senior Laravel Developer', $job->title);
    }

    public function test_inverted_salary_bounds_are_swapped_rather_than_rejected(): void
    {
        $this->router->willReturn($this->fixtureWithOnly(['Laravel Engineer']));

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertSame(64000, $job->salaryMin);
        $this->assertSame(82000, $job->salaryMax);
        $this->assertSame('GBP', $job->currency);
    }

    /**
     * The tier thresholds do no FX conversion, so an unlabelled figure would be
     * read as though it were already in the threshold currency
     * (Requirement 4.3). A model is more likely than an API to omit the code.
     */
    public function test_salary_is_dropped_when_no_currency_is_reported(): void
    {
        $this->router->willReturn($this->fixtureWithOnly(['API Developer']));

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertSame('API Developer', $job->title);
        $this->assertFalse($job->hasSalary());
        $this->assertNull($job->currency);
    }

    /**
     * The prompt asks for annual figures. A two-digit "salary" is an hourly rate
     * the model didn't convert; kept as-is it would read as a near-zero salary
     * and actively suppress tier escalation.
     */
    public function test_salary_is_dropped_when_the_figure_is_not_an_annual_amount(): void
    {
        $this->router->willReturn($this->fixtureWithOnly(['Contract PHP Developer']));

        /** @var NormalizedJob $job */
        $job = $this->provider()->search($this->query())->first();

        $this->assertSame('Contract PHP Developer', $job->title);
        $this->assertFalse($job->hasSalary());
    }

    public function test_unusable_leads_are_skipped_without_failing_the_run(): void
    {
        $this->router->willReturn($this->fixture());

        $jobs = $this->provider()->search($this->query());
        $titles = $jobs->pluck('title')->all();

        // Ten results in, seven out.
        $this->assertCount(7, $jobs);

        // Not a URL at all, so enrichment and the apply adapters would have
        // nothing to dereference.
        $this->assertNotContains('Software Engineer', $titles);

        // No employer named: the company feeds the dedupe hash and is shown to
        // the user, so a blank one isn't a usable lead.
        $this->assertNotContains('Staff Engineer', $titles);
    }

    /** Same posting, different casing and a trailing slash. */
    public function test_the_same_url_is_not_returned_twice(): void
    {
        $this->router->willReturn($this->fixtureWithOnly([
            'Senior Laravel Developer',
            'Senior Laravel Developer — Remote',
        ]));

        $jobs = $this->provider()->search($this->query());

        $this->assertCount(1, $jobs);
        $this->assertSame('Senior Laravel Developer', $jobs->first()->title);
    }

    public function test_an_empty_result_list_is_not_an_error(): void
    {
        $this->router->willReturn(['jobs' => []]);

        $this->assertTrue($this->provider()->search($this->query())->isEmpty());
    }

    public function test_a_malformed_payload_yields_no_jobs(): void
    {
        $this->router->willReturn(['jobs' => 'none today']);

        $this->assertTrue($this->provider()->search($this->query())->isEmpty());
    }

    /**
     * A missing API key or a dead endpoint must not read as "the web has no
     * matching jobs today" — the orchestrator decides to log and skip the
     * source (JobSourceProvider contract).
     */
    public function test_a_router_failure_propagates_rather_than_looking_like_no_jobs(): void
    {
        $this->router->willThrow(ModelRouterException::missingApiKey('job_search'));

        $this->expectException(ModelRouterException::class);

        $this->provider()->search($this->query());
    }

    public function test_key_matches_the_config_registration(): void
    {
        $provider = $this->provider();

        $this->assertSame('llm_search', $provider->key());

        // The registry refuses a provider whose key() disagrees with the key it
        // is registered under, so resolving it proves the two line up.
        $this->assertSame(
            $provider->key(),
            $this->app->make(JobSourceRegistry::class)->get('llm_search')->key()
        );

        $this->assertSame(
            LlmWebSearchJobSourceProvider::class,
            config('job_sources.providers.llm_search.class')
        );
    }

    public function test_the_source_joins_the_fan_out_by_default(): void
    {
        $this->assertTrue($this->app->make(JobSourceRegistry::class)->isEnabled('llm_search'));
    }

    /**
     * The pinned tier's models must actually be able to search the live web,
     * otherwise the source answers from training data — the one failure mode
     * that looks like success.
     */
    public function test_the_pinned_tier_holds_only_search_augmented_models(): void
    {
        $models = config('services.openrouter.tiers.'.config('services.llm_job_search.tier'));

        $this->assertIsArray($models);
        $this->assertNotEmpty($models);

        foreach ($models as $model) {
            $this->assertStringEndsWith(':online', $model, "{$model} has no web-search capability.");
        }

        // More than one, so a failure has a same-tier fallback (Requirement 4.5).
        $this->assertGreaterThan(1, count($models));
    }
}

/**
 * Records what the provider asked the router for and replays a canned answer.
 *
 * A hand-written double rather than a mock so the named arguments the provider
 * uses are checked against the real signature by PHP itself: a renamed or
 * reordered parameter on ModelRouterService::complete() breaks this file loudly
 * instead of silently passing a null through.
 */
class FakeModelRouter extends ModelRouterService
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var array<mixed> */
    private array $parsedJson = ['jobs' => []];

    private ?\Throwable $failure = null;

    /**
     * @param array<mixed> $parsedJson
     */
    public function willReturn(array $parsedJson): void
    {
        $this->parsedJson = $parsedJson;
        $this->failure = null;
    }

    public function willThrow(\Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function complete(
        string $taskType,
        array $messages,
        ModelTierContext $context,
        ?array $jsonSchema = null,
        ?Model $relatedTo = null,
        ?string $tierOverride = null,
    ): ModelCompletionResult {
        $this->calls[] = [
            'taskType' => $taskType,
            'messages' => $messages,
            'context' => $context,
            'jsonSchema' => $jsonSchema,
            'relatedTo' => $relatedTo,
            'tierOverride' => $tierOverride,
        ];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new ModelCompletionResult(
            content: json_encode($this->parsedJson),
            parsedJson: $this->parsedJson,
            modelUsed: 'google/gemini-2.5-flash:online',
            tierUsed: $tierOverride ?? 'web_search',
            inputTokens: 480,
            outputTokens: 620,
            estimatedCostUsd: 0.0042,
        );
    }

    public function lastUserMessage(): string
    {
        $messages = end($this->calls)['messages'];

        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'user') {
                return (string) $message['content'];
            }
        }

        return '';
    }
}
