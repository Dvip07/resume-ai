<?php

namespace Tests\Unit\Services\Scoring;

use App\Enums\RecommendedAction;
use App\Exceptions\JobScoringException;
use App\Exceptions\ModelRouterException;
use App\Models\JobListing;
use App\Models\UserProfile;
use App\Services\ModelCompletionResult;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use App\Services\Scoring\FitAnalysis;
use App\Services\Scoring\JobScoringService;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;
use Throwable;

/**
 * The two-prompt scoring pipeline (Requirements 5.1, 5.5, task 10.2).
 *
 * The router is replaced by a recording fake rather than faked at the HTTP
 * layer: what matters here is the pipeline's own behaviour — which task type
 * and schema each prompt asks for, what routing signals it derives, and whether
 * a bad response earns exactly one stricter retry — none of which should depend
 * on OpenRouter's wire format, which ModelRouterServiceTest already covers.
 *
 * No model is persisted; the service never touches the database.
 */
class JobScoringServiceTest extends TestCase
{
    private FakeModelRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'scoring.max_attempts' => 2,
            'scoring.max_description_chars' => 20000,
            'scoring.max_profile_chars' => 12000,
            'scoring.max_resume_text_chars' => 6000,
            'scoring.stars.min' => 1,
            'scoring.stars.max' => 5,
            'scoring.auto_apply_star_threshold' => 4,
        ]);

        $this->router = new FakeModelRouter();
    }

    private function service(): JobScoringService
    {
        return new JobScoringService($this->router);
    }

    private function listing(array $overrides = []): JobListing
    {
        return new JobListing(array_merge([
            'title' => 'Senior Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote',
            'description' => str_repeat('We need Laravel, PostgreSQL and Kubernetes experience. ', 20),
            'application_url' => 'https://jobs.example.com/1',
            'salary_min' => 120000,
            'salary_max' => 160000,
            'currency' => 'USD',
        ], $overrides));
    }

    private function profile(array $overrides = []): UserProfile
    {
        return new UserProfile(array_merge([
            'user_id' => 7,
            'skills' => ['primary' => ['Laravel', 'PostgreSQL'], 'secondary' => ['Redis']],
            'location' => ['city' => 'Austin', 'country' => 'US'],
            'experience' => [['company' => 'Globex', 'position' => 'Backend Engineer', 'duration' => 48]],
            'education' => [['institution' => 'State University', 'degree' => 'BSc']],
            'parsed_keywords' => 'php, laravel, sql',
            'resume_text' => 'Backend engineer with four years of Laravel experience.',
        ], $overrides));
    }

    private function analysis(array $overrides = []): FitAnalysis
    {
        return new FitAnalysis(
            requiredSkills: $overrides['requiredSkills'] ?? ['Laravel', 'PostgreSQL', 'Kubernetes'],
            seniority: 'senior',
            matchedSkills: $overrides['matchedSkills'] ?? ['Laravel', 'PostgreSQL'],
            missingSkills: $overrides['missingSkills'] ?? ['Kubernetes'],
            gapSummary: 'Strong on the framework and database, no container orchestration evidence.',
            rawOutput: '{"seniority":"senior"}',
            modelUsed: 'vendor/standard-1',
            tierUsed: 'standard',
        );
    }

    private function fitJson(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'requiredSkills' => ['Laravel', 'PostgreSQL', 'Kubernetes'],
            'seniority' => 'senior',
            'matchedSkills' => ['Laravel', 'PostgreSQL'],
            'missingSkills' => ['Kubernetes'],
            'gapSummary' => 'Missing Kubernetes exposure.',
        ], $overrides));
    }

    private function ratingJson(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'stars' => 4,
            'rationale' => 'Matches the core stack; only container orchestration is missing.',
            'recommendedAction' => 'auto_apply',
        ], $overrides));
    }

    /* ------------------------------------------------------------------
     | Prompt 1
     | ----------------------------------------------------------------- */

    public function test_fit_analysis_returns_parsed_gap_analysis(): void
    {
        $this->router->willReturn($this->fitJson());

        $analysis = $this->service()->promptFitAnalysis($this->listing(), $this->profile());

        $this->assertSame(['Laravel', 'PostgreSQL', 'Kubernetes'], $analysis->requiredSkills);
        $this->assertSame('senior', $analysis->seniority);
        $this->assertSame(['Kubernetes'], $analysis->missingSkills);
        $this->assertSame('Missing Kubernetes exposure.', $analysis->gapSummary);
        $this->assertSame(1, $analysis->attempts);
        // Raw output is carried verbatim for `job_scores.raw_prompt_1_output`.
        $this->assertSame($this->fitJson(), $analysis->rawOutput);
        $this->assertSame(0.6667, $analysis->coverageRatio());
    }

    public function test_fit_analysis_asks_for_its_own_task_type_schema_and_listing_attribution(): void
    {
        $this->router->willReturn($this->fitJson());
        $listing = $this->listing();

        $this->service()->promptFitAnalysis($listing, $this->profile());

        $call = $this->router->calls[0];
        $this->assertSame(JobScoringService::TASK_FIT_ANALYSIS, $call['taskType']);
        $this->assertSame('jd_fit_analysis', $call['taskType']);
        $this->assertSame(
            ['requiredSkills', 'seniority', 'matchedSkills', 'missingSkills', 'gapSummary'],
            $call['jsonSchema']['required']
        );
        // Token spend is attributed to the listing being scored (Req 4.6).
        $this->assertSame($listing, $call['relatedTo']);
    }

    public function test_fit_analysis_prompt_carries_the_description_and_the_profile(): void
    {
        $this->router->willReturn($this->fitJson());

        $this->service()->promptFitAnalysis($this->listing(), $this->profile());

        $user = $this->router->calls[0]['messages'][1]['content'];
        $this->assertStringContainsString('Kubernetes experience', $user);
        $this->assertStringContainsString('Laravel', $user);
        $this->assertStringContainsString('Globex', $user);
        $this->assertStringContainsString('four years of Laravel', $user);
    }

    public function test_fit_analysis_routes_on_salary_when_the_listing_has_it(): void
    {
        $this->router->willReturn($this->fitJson());

        $this->service()->promptFitAnalysis($this->listing(), $this->profile());

        $context = $this->router->calls[0]['context'];
        $this->assertTrue($context->hasSalary());
        $this->assertSame(160000, $context->salaryBasis());
    }

    public function test_fit_analysis_ignores_non_string_entries_in_skill_lists(): void
    {
        $this->router->willReturn($this->fitJson([
            'requiredSkills' => ['Laravel', ['nested' => 'object'], '', 'Laravel', 'Go'],
        ]));

        $analysis = $this->service()->promptFitAnalysis($this->listing(), $this->profile());

        $this->assertSame(['Laravel', 'Go'], $analysis->requiredSkills);
    }

    public function test_a_listing_with_no_description_fails_without_calling_a_model(): void
    {
        try {
            $this->service()->promptFitAnalysis($this->listing(['description' => '   ']), $this->profile());
            $this->fail('Expected a JobScoringException for an unscoreable listing.');
        } catch (JobScoringException $e) {
            $this->assertSame(JobScoringException::PROMPT_FIT_ANALYSIS, $e->prompt);
            $this->assertStringContainsString('needs enrichment', $e->getMessage());
        }

        $this->assertSame([], $this->router->calls);
    }

    /* ------------------------------------------------------------------
     | Prompt 2
     | ----------------------------------------------------------------- */

    public function test_rating_decision_returns_stars_rationale_and_action(): void
    {
        $this->router->willReturn($this->ratingJson());

        $decision = $this->service()->promptRatingDecision($this->listing(), $this->analysis());

        $this->assertSame(4, $decision->stars);
        $this->assertSame(RecommendedAction::AutoApply, $decision->recommendedAction);
        $this->assertStringContainsString('core stack', $decision->rationale);
        $this->assertSame(JobScoringService::TASK_RATING_DECISION, $this->router->calls[0]['taskType']);
        $this->assertSame('jd_rating', $this->router->calls[0]['taskType']);
        $this->assertTrue($decision->meetsThreshold(4));
        $this->assertFalse($decision->meetsThreshold(5));
    }

    public function test_rating_decision_sees_the_analysis_and_not_the_job_description(): void
    {
        $this->router->willReturn($this->ratingJson());

        $this->service()->promptRatingDecision($this->listing(), $this->analysis());

        $user = $this->router->calls[0]['messages'][1]['content'];
        $this->assertStringContainsString('Kubernetes', $user);
        $this->assertStringContainsString('missingSkills', $user);
        $this->assertStringContainsString('Senior Backend Engineer', $user);
        // The raw posting text must not reappear: prompt 2 judges the analysis.
        $this->assertStringNotContainsString('We need Laravel, PostgreSQL and Kubernetes experience.', $user);
    }

    public function test_rating_decision_routes_with_the_fit_signals_prompt_one_discovered(): void
    {
        $this->router->willReturn($this->ratingJson());
        // No salary, so the complexity heuristic decides (Requirement 4.4).
        $listing = $this->listing(['salary_min' => null, 'salary_max' => null, 'currency' => null]);

        $this->service()->promptRatingDecision($listing, $this->analysis());

        $context = $this->router->calls[0]['context'];
        $this->assertFalse($context->hasSalary());
        $this->assertGreaterThan(0, $context->complexityScore);
    }

    public function test_a_rating_outside_the_allowed_range_is_treated_as_unusable(): void
    {
        $this->router->willReturn($this->ratingJson(['stars' => 9]));
        $this->router->willReturn($this->ratingJson(['stars' => 3, 'recommendedAction' => 'store_only']));

        $decision = $this->service()->promptRatingDecision($this->listing(), $this->analysis());

        $this->assertSame(3, $decision->stars);
        $this->assertSame(RecommendedAction::StoreOnly, $decision->recommendedAction);
        $this->assertSame(2, $decision->attempts);
    }

    public function test_an_unknown_recommended_action_is_treated_as_unusable(): void
    {
        $this->router->willReturn($this->ratingJson(['recommendedAction' => 'apply_maybe']));
        $this->router->willReturn($this->ratingJson());

        $decision = $this->service()->promptRatingDecision($this->listing(), $this->analysis());

        $this->assertSame(RecommendedAction::AutoApply, $decision->recommendedAction);
        $this->assertSame(2, $decision->attempts);
    }

    public function test_a_whole_number_float_rating_is_accepted(): void
    {
        $this->router->willReturn('{"stars":5.0,"rationale":"Complete match.","recommendedAction":"auto_apply"}');

        $decision = $this->service()->promptRatingDecision($this->listing(), $this->analysis());

        $this->assertSame(5, $decision->stars);
        $this->assertSame(1, $decision->attempts);
    }

    /* ------------------------------------------------------------------
     | Requirement 5.5: one stricter retry, then a typed failure
     | ----------------------------------------------------------------- */

    public function test_a_malformed_payload_earns_one_retry_with_a_stricter_instruction(): void
    {
        // Decodable, but says nothing usable: no gapSummary.
        $this->router->willReturn($this->fitJson(['gapSummary' => '']));
        $this->router->willReturn($this->fitJson());

        $analysis = $this->service()->promptFitAnalysis($this->listing(), $this->profile());

        $this->assertSame(2, $analysis->attempts);
        $this->assertCount(2, $this->router->calls);

        $first = $this->router->calls[0]['messages'][0]['content'];
        $retry = $this->router->calls[1]['messages'][0]['content'];
        $this->assertStringNotContainsString('ONLY valid JSON', $first);
        $this->assertStringContainsString('Return ONLY valid JSON matching this schema', $retry);
        // The retry restates the schema inline, for a model whose provider
        // dropped `response_format`.
        $this->assertStringContainsString('"requiredSkills"', $retry);
        $this->assertStringContainsString('gapSummary', $retry);
    }

    public function test_an_unparseable_router_response_earns_the_same_retry(): void
    {
        $this->router->willThrow(ModelRouterException::unparseableJson(
            'jd_rating',
            'standard',
            'vendor/standard-1',
            'Sure! Here is my rating: four stars.'
        ));
        $this->router->willReturn($this->ratingJson());

        $decision = $this->service()->promptRatingDecision($this->listing(), $this->analysis());

        $this->assertSame(4, $decision->stars);
        $this->assertSame(2, $decision->attempts);
    }

    public function test_two_failures_raise_a_typed_exception_carrying_the_attempt_history(): void
    {
        $this->router->willReturn($this->ratingJson(['stars' => 0]));
        $this->router->willReturn('{"stars":"four","rationale":"ok","recommendedAction":"auto_apply"}');

        try {
            $this->service()->promptRatingDecision($this->listing(), $this->analysis());
            $this->fail('Expected a JobScoringException once both attempts failed.');
        } catch (JobScoringException $e) {
            $this->assertSame(JobScoringException::PROMPT_RATING_DECISION, $e->prompt);
            $this->assertCount(2, $e->attempts);
            $this->assertSame('jd_rating', $e->context['task_type']);
            // Both failures were about content, which is what tells the caller
            // this is a Requirement 5.5 fallback rather than an outage.
            $this->assertTrue($e->failedOnParsing());
            $this->assertStringContainsString('outside the allowed 1-5 range', $e->attempts[0]['reason']);
            $this->assertStringContainsString('integer `stars`', $e->attempts[1]['reason']);
            $this->assertNotSame('', $e->reviewReason());
        }

        $this->assertCount(2, $this->router->calls);
    }

    public function test_the_retry_allowance_is_configurable_and_never_exceeded(): void
    {
        config(['scoring.max_attempts' => 1]);

        $this->router->willReturn($this->fitJson(['gapSummary' => '']));
        $this->router->willReturn($this->fitJson());

        $this->expectException(JobScoringException::class);

        try {
            $this->service()->promptFitAnalysis($this->listing(), $this->profile());
        } finally {
            // One attempt only: the second queued response was never asked for.
            $this->assertCount(1, $this->router->calls);
        }
    }

    public function test_a_transport_failure_is_also_retried_once_then_reported(): void
    {
        $failure = ModelRouterException::transportFailure(
            'jd_fit_analysis',
            'standard',
            'vendor/standard-1',
            new \RuntimeException('cURL error 28: timed out')
        );

        $this->router->willThrow($failure);
        $this->router->willThrow($failure);

        try {
            $this->service()->promptFitAnalysis($this->listing(), $this->profile());
            $this->fail('Expected a JobScoringException once both attempts failed.');
        } catch (JobScoringException $e) {
            $this->assertCount(2, $e->attempts);
            // Not a content problem — the caller can tell an outage apart from
            // a model that cannot follow a schema.
            $this->assertFalse($e->failedOnParsing());
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }
}

/**
 * Records what the scoring service asked for and returns queued responses.
 *
 * A subclass rather than a mock so the type contract of
 * {@see ModelRouterService::complete()} is enforced by PHP: if the real
 * signature changes, this fake stops compiling instead of silently drifting.
 */
class FakeModelRouter extends ModelRouterService
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** @var array<int, ModelCompletionResult|Throwable> */
    private array $queue = [];

    /** Queue a successful completion whose content is `$content`. */
    public function willReturn(string $content): void
    {
        $decoded = json_decode($content, true);

        $this->queue[] = new ModelCompletionResult(
            content: $content,
            parsedJson: is_array($decoded) ? $decoded : [],
            modelUsed: 'vendor/standard-1',
            tierUsed: 'standard',
            inputTokens: 900,
            outputTokens: 120,
            estimatedCostUsd: 0.0021,
        );
    }

    public function willThrow(Throwable $e): void
    {
        $this->queue[] = $e;
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

        if ($this->queue === []) {
            throw new \LogicException('FakeModelRouter received an unexpected call for task '.$taskType);
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}
