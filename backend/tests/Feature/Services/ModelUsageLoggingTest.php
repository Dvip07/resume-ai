<?php

namespace Tests\Feature\Services;

use App\Enums\ModelUsageOutcome;
use App\Exceptions\ModelRouterException;
use App\Models\JobListing;
use App\Models\ModelUsageLog;
use App\Models\Resume;
use App\Models\User;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Usage/cost logging (task 7.5, Requirement 4.6): every OpenRouter attempt
 * made through ModelRouterService — success or failure — leaves a
 * `model_usage_logs` row carrying model, tier, tokens, cost, task type and the
 * related record.
 *
 * The HTTP layer is faked throughout; no request reaches OpenRouter.
 */
class ModelUsageLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://openrouter.ai/api/v1/chat/completions';

    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['stars' => ['type' => 'integer']],
        'required' => ['stars'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.default_tier' => 'cheap',
            'services.openrouter.tiers.cheap' => ['vendor/cheap-1', 'vendor/cheap-2'],
            'services.openrouter.max_attempts_per_model' => 1,
            'services.openrouter.retry_delay_ms' => 0,
        ]);

        Http::preventStrayRequests();
    }

    private function router(): ModelRouterService
    {
        return app(ModelRouterService::class);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function completionBody(string $content, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'gen-1',
            'model' => 'vendor/cheap-1',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 34, 'cost' => 0.001234],
        ], $overrides);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'Rate this job.']];
    }

    public function test_a_successful_call_records_tokens_cost_model_tier_and_task_type(): void
    {
        Http::fake([self::URL => Http::response($this->completionBody('{"stars":4}'))]);

        $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);

        $this->assertSame(1, ModelUsageLog::count());

        $row = ModelUsageLog::sole();
        $this->assertSame('jd_rating', $row->task_type);
        $this->assertSame('vendor/cheap-1', $row->model_used);
        $this->assertSame('cheap', $row->tier_used);
        $this->assertSame(ModelUsageOutcome::Success, $row->outcome);
        $this->assertSame(1, $row->attempt);
        $this->assertSame(120, $row->input_tokens);
        $this->assertSame(34, $row->output_tokens);
        $this->assertSame('0.001234', $row->estimated_cost_usd);
        $this->assertNull($row->error);
        $this->assertNull($row->http_status);
        $this->assertNotNull($row->created_at);
    }

    public function test_the_model_that_actually_answered_is_recorded_not_the_one_requested(): void
    {
        // OpenRouter can re-route a request; the ledger has to stay honest.
        Http::fake([
            self::URL => Http::response($this->completionBody('{"stars":4}', ['model' => 'vendor/rerouted'])),
        ]);

        $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);

        $this->assertSame('vendor/rerouted', ModelUsageLog::sole()->model_used);
    }

    public function test_each_failed_attempt_gets_its_own_row_including_the_final_failure(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        try {
            $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);
            $this->fail('Expected ModelRouterException once every candidate model failed.');
        } catch (ModelRouterException) {
            // asserted in the fallback test; here we care about the ledger
        }

        $rows = ModelUsageLog::orderBy('id')->get();

        // Two candidate models, one attempt each: two failure rows, no success.
        $this->assertCount(2, $rows);
        $this->assertSame(['vendor/cheap-1', 'vendor/cheap-2'], $rows->pluck('model_used')->all());

        foreach ($rows as $row) {
            $this->assertSame(ModelUsageOutcome::Failure, $row->outcome);
            $this->assertSame('jd_rating', $row->task_type);
            $this->assertSame('cheap', $row->tier_used);
            $this->assertSame(429, $row->http_status);
            $this->assertStringContainsString('HTTP 429', $row->error);
            // No usage block came back, so cost totals stay untouched.
            $this->assertSame(0, $row->input_tokens);
            $this->assertSame(0, $row->output_tokens);
            $this->assertSame('0.000000', $row->estimated_cost_usd);
        }
    }

    public function test_a_terminal_failure_is_recorded_before_the_walk_aborts(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'invalid api key']], 401)]);

        try {
            $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);
            $this->fail('Expected ModelRouterException for a terminal auth failure.');
        } catch (ModelRouterException) {
            // asserted elsewhere
        }

        $row = ModelUsageLog::sole();
        $this->assertSame(ModelUsageOutcome::Failure, $row->outcome);
        $this->assertSame(401, $row->http_status);
    }

    public function test_a_fallback_records_the_failed_attempt_and_the_successful_one(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: operation timed out');
            }

            return Http::response($this->completionBody('{"stars":3}', ['model' => 'vendor/cheap-2']));
        });

        $this->router()->complete('resume_tailor', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);

        $rows = ModelUsageLog::orderBy('id')->get();
        $this->assertCount(2, $rows);

        $this->assertSame(ModelUsageOutcome::Failure, $rows[0]->outcome);
        $this->assertSame('vendor/cheap-1', $rows[0]->model_used);
        // A timeout has no HTTP status, but the reason still has to be readable.
        $this->assertNull($rows[0]->http_status);
        $this->assertStringContainsString('timed out', $rows[0]->error);

        $this->assertSame(ModelUsageOutcome::Success, $rows[1]->outcome);
        $this->assertSame('vendor/cheap-2', $rows[1]->model_used);
    }

    public function test_the_attempt_number_distinguishes_retries_on_the_same_model(): void
    {
        config(['services.openrouter.max_attempts_per_model' => 2]);

        Http::fake([
            self::URL => Http::sequence()
                ->push(['error' => ['message' => 'busy']], 503)
                ->push($this->completionBody('{"stars":4}')),
        ]);

        $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);

        $rows = ModelUsageLog::orderBy('id')->get();
        $this->assertSame([1, 2], $rows->pluck('attempt')->all());
        $this->assertSame(['vendor/cheap-1', 'vendor/cheap-1'], $rows->pluck('model_used')->all());
    }

    public function test_a_related_record_is_associated_with_the_usage_row(): void
    {
        $resume = $this->resume();

        Http::fake([self::URL => Http::response($this->completionBody('{"stars":4}'))]);

        $this->router()->complete(
            'resume_tailor',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA,
            $resume
        );

        $row = ModelUsageLog::sole();
        $this->assertSame(Resume::class, $row->related_type);
        $this->assertSame($resume->id, $row->related_id);
        $this->assertTrue($resume->is($row->related));
    }

    public function test_failed_attempts_carry_the_related_record_too(): void
    {
        $resume = $this->resume();

        Http::fake([self::URL => Http::response(['error' => ['message' => 'boom']], 502)]);

        try {
            $this->router()->complete(
                'resume_tailor',
                $this->messages(),
                ModelTierContext::unknown(),
                self::SCHEMA,
                $resume
            );
        } catch (ModelRouterException) {
            // asserted elsewhere
        }

        $this->assertSame(2, ModelUsageLog::where('related_id', $resume->id)
            ->where('related_type', Resume::class)
            ->count());
    }

    /**
     * The relation is polymorphic, so a scoring call about a job listing has to
     * work exactly like a tailoring call about a resume (Requirement 4.6 wants
     * "related job/resume id").
     */
    public function test_a_job_listing_can_be_the_related_record(): void
    {
        $job = $this->jobListing();

        Http::fake([self::URL => Http::response($this->completionBody('{"stars":5}'))]);

        $this->router()->complete(
            'jd_fit_analysis',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA,
            $job
        );

        $row = ModelUsageLog::sole();
        $this->assertSame(JobListing::class, $row->related_type);
        $this->assertSame($job->id, $row->related_id);
        $this->assertTrue($job->is($row->related));
    }

    /**
     * Rows for different relation types coexist and stay attributable, so cost
     * can be rolled up per job or per resume.
     */
    public function test_rows_for_different_related_types_stay_distinguishable(): void
    {
        $job = $this->jobListing();
        $resume = $this->resume();

        Http::fake([self::URL => Http::response($this->completionBody('{"stars":4}'))]);

        $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA, $job);
        $this->router()->complete('resume_tailor', $this->messages(), ModelTierContext::unknown(), self::SCHEMA, $resume);

        $this->assertSame(2, ModelUsageLog::count());
        $this->assertSame('jd_rating', ModelUsageLog::where('related_type', JobListing::class)->sole()->task_type);
        $this->assertSame('resume_tailor', ModelUsageLog::where('related_type', Resume::class)->sole()->task_type);
    }

    /*
    |--------------------------------------------------------------------------
    | Cost estimate when the provider reports none
    |--------------------------------------------------------------------------
    |
    | OpenRouter only returns `usage.cost` when the request asks for usage
    | accounting and the endpoint supports it. When it is absent the router
    | records 0 rather than deriving a figure from a local price table (there
    | is none in config — the per-token prices in config/services.php are
    | documentation comments only). Token counts are still recorded, so the
    | spend is reconstructable after the fact; what these tests pin down is
    | that nothing is invented and nothing crashes.
    */

    public function test_a_missing_cost_field_records_zero_cost_but_keeps_token_counts(): void
    {
        Http::fake([
            self::URL => Http::response([
                'id' => 'gen-1',
                'model' => 'vendor/cheap-1',
                'choices' => [['message' => ['content' => '{"stars":4}']]],
                // Provider returned tokens but no cost figure.
                'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120],
            ]),
        ]);

        $result = $this->router()->complete(
            'jd_rating',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA
        );

        $this->assertSame(0.0, $result->estimatedCostUsd);

        $row = ModelUsageLog::sole();
        $this->assertSame(ModelUsageOutcome::Success, $row->outcome);
        $this->assertSame(900, $row->input_tokens);
        $this->assertSame(120, $row->output_tokens);
        $this->assertSame('0.000000', $row->estimated_cost_usd);
    }

    public function test_a_response_with_no_usage_block_at_all_still_logs_one_row(): void
    {
        Http::fake([
            self::URL => Http::response([
                'id' => 'gen-1',
                'model' => 'vendor/cheap-1',
                'choices' => [['message' => ['content' => '{"stars":4}']]],
            ]),
        ]);

        $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);

        $row = ModelUsageLog::sole();
        $this->assertSame(0, $row->input_tokens);
        $this->assertSame(0, $row->output_tokens);
        $this->assertSame('0.000000', $row->estimated_cost_usd);
    }

    /**
     * A cost far below the column's six decimal places must not round away to
     * nothing in the ledger — cheap models on high volume is the normal case.
     */
    public function test_a_reported_cost_is_persisted_at_the_precision_the_column_holds(): void
    {
        Http::fake([
            self::URL => Http::response($this->completionBody('{"stars":4}', [
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'cost' => 0.000004],
            ])),
        ]);

        $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);

        $this->assertSame('0.000004', ModelUsageLog::sole()->estimated_cost_usd);
    }

    public function test_a_call_without_a_related_record_still_logs_usage(): void
    {
        Http::fake([self::URL => Http::response($this->completionBody('{"stars":4}'))]);

        $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);

        $row = ModelUsageLog::sole();
        $this->assertNull($row->related_type);
        $this->assertNull($row->related_id);
    }

    public function test_a_usage_write_failure_does_not_break_the_call(): void
    {
        // The ledger is gone (migration not run, DB trouble): the completion
        // still has to reach the caller.
        Schema::drop('model_usage_logs');
        Log::spy();

        Http::fake([self::URL => Http::response($this->completionBody('{"stars":4}'))]);

        $result = $this->router()->complete(
            'jd_rating',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA
        );

        $this->assertSame(['stars' => 4], $result->parsedJson);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) {
                return $message === 'Failed to record OpenRouter usage'
                    && $context['task_type'] === 'jd_rating';
            })
            ->once();
    }

    private function jobListing(): JobListing
    {
        return JobListing::create([
            'api_id' => 'ext-42',
            'title' => 'Senior Backend Engineer',
            'company' => 'Acme',
            'location' => 'Remote',
            'description' => 'Build things.',
            'api_source' => 'adzuna',
            'parsed_skills' => ['php'],
            'application_url' => 'https://boards.greenhouse.io/acme/jobs/42',
        ]);
    }

    private function resume(): Resume
    {
        $user = User::factory()->create();

        return Resume::create([
            'user_id' => $user->id,
            'original_filename' => 'resume.pdf',
            'file_path' => 'resumes/resume.pdf',
        ]);
    }
}
