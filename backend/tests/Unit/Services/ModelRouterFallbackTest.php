<?php

namespace Tests\Unit\Services;

use App\Exceptions\ModelRouterException;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Same-tier fallback retry (task 7.4, Requirement 4.5): a failed or timed-out
 * call retries on the next model in the tier before the job fails, and every
 * attempt is logged with enough detail to diagnose it.
 *
 * Every test fakes the HTTP layer — no request ever reaches OpenRouter.
 */
class ModelRouterFallbackTest extends TestCase
{
    // The router writes a `model_usage_logs` row per attempt (task 7.5), so
    // these tests need the real schema: without it every call would quietly
    // take the "usage write failed" path and log warnings that have nothing to
    // do with fallback behaviour.
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
            // One shot per model unless a test says otherwise, so "moved to the
            // next model" is unambiguous.
            'services.openrouter.max_attempts_per_model' => 1,
            // Keep the suite fast; the backoff itself is asserted separately.
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
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.0001],
        ], $overrides);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'Rate this job.']];
    }

    /**
     * Models actually requested, in order.
     *
     * @return array<int, string>
     */
    private function requestedModels(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->data()['model'])
            ->values()
            ->all();
    }

    public function test_a_failed_first_model_falls_back_to_the_next_model_in_the_tier(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push(['error' => ['message' => 'upstream exploded']], 500)
                ->push($this->completionBody('{"stars":4}', ['model' => 'vendor/cheap-2'])),
        ]);

        $result = $this->router()->complete(
            'jd_rating',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA
        );

        $this->assertSame(['stars' => 4], $result->parsedJson);
        $this->assertSame('vendor/cheap-2', $result->modelUsed);
        $this->assertSame('cheap', $result->tierUsed);
        $this->assertSame(['vendor/cheap-1', 'vendor/cheap-2'], $this->requestedModels());
    }

    public function test_a_timeout_falls_back_to_the_next_model_in_the_tier(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: operation timed out');
            }

            return Http::response($this->completionBody('{"stars":3}', ['model' => 'vendor/cheap-2']));
        });

        $result = $this->router()->complete(
            'resume_tailor',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA
        );

        $this->assertSame('vendor/cheap-2', $result->modelUsed);
        $this->assertSame(2, $calls);
    }

    public function test_a_model_that_ignores_the_schema_falls_back_to_the_next_model(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->completionBody('I am afraid I cannot rate this job.'))
                ->push($this->completionBody('{"stars":5}', ['model' => 'vendor/cheap-2'])),
        ]);

        $result = $this->router()->complete(
            'jd_rating',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA
        );

        $this->assertSame(['stars' => 5], $result->parsedJson);
        $this->assertSame(['vendor/cheap-1', 'vendor/cheap-2'], $this->requestedModels());
    }

    public function test_when_every_candidate_fails_the_exception_carries_the_whole_attempt_history(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        try {
            $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);
            $this->fail('Expected ModelRouterException once every candidate model failed.');
        } catch (ModelRouterException $e) {
            $this->assertStringContainsString('All 2 OpenRouter candidate model(s)', $e->getMessage());
            $this->assertSame('jd_rating', $e->context['task_type']);
            $this->assertSame('cheap', $e->context['tier']);
            $this->assertSame(['vendor/cheap-1', 'vendor/cheap-2'], $e->context['models']);

            $attempts = $e->context['attempts'];
            $this->assertCount(2, $attempts);
            $this->assertSame('vendor/cheap-1', $attempts[0]['model']);
            $this->assertSame('vendor/cheap-2', $attempts[1]['model']);

            foreach ($attempts as $attempt) {
                $this->assertSame(429, $attempt['status']);
                $this->assertSame('cheap', $attempt['tier']);
                $this->assertSame('jd_rating', $attempt['task_type']);
                $this->assertTrue($attempt['retryable']);
                $this->assertStringContainsString('HTTP 429', $attempt['error']);
            }

            // The last underlying failure stays reachable for the precise reason.
            $this->assertInstanceOf(ModelRouterException::class, $e->getPrevious());
        }

        Http::assertSentCount(2);
    }

    public function test_a_non_retryable_failure_does_not_try_the_next_model(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'invalid api key']], 401)]);

        try {
            $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);
            $this->fail('Expected ModelRouterException for a terminal auth failure.');
        } catch (ModelRouterException $e) {
            // The precise reason survives instead of being flattened into a
            // generic "everything failed".
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertFalse($e->isRetryable());
            $this->assertCount(1, $e->context['attempts']);
        }

        // Same key, same payload: the second model would fail identically.
        Http::assertSentCount(1);
    }

    public function test_a_malformed_request_is_terminal_too(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'invalid schema']], 400)]);

        try {
            $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);
            $this->fail('Expected ModelRouterException for a malformed request.');
        } catch (ModelRouterException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertFalse($e->isRetryable());
        }

        Http::assertSentCount(1);
    }

    public function test_max_attempts_per_model_is_respected_before_moving_on(): void
    {
        config(['services.openrouter.max_attempts_per_model' => 2]);

        Http::fake([
            self::URL => Http::sequence()
                ->push(['error' => ['message' => 'busy']], 503)
                ->push(['error' => ['message' => 'busy']], 503)
                ->push($this->completionBody('{"stars":4}', ['model' => 'vendor/cheap-2'])),
        ]);

        $result = $this->router()->complete(
            'jd_rating',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA
        );

        $this->assertSame('vendor/cheap-2', $result->modelUsed);
        // Preferred model gets both of its attempts before the tier moves on.
        $this->assertSame(
            ['vendor/cheap-1', 'vendor/cheap-1', 'vendor/cheap-2'],
            $this->requestedModels()
        );
    }

    public function test_max_attempts_per_model_bounds_the_total_number_of_calls(): void
    {
        config(['services.openrouter.max_attempts_per_model' => 2]);

        Http::fake([self::URL => Http::response(['error' => ['message' => 'busy']], 503)]);

        try {
            $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);
            $this->fail('Expected ModelRouterException once every candidate model failed.');
        } catch (ModelRouterException $e) {
            $this->assertCount(4, $e->context['attempts']);
        }

        // 2 models x 2 attempts, and not one request more.
        Http::assertSentCount(4);
        $this->assertSame(
            ['vendor/cheap-1', 'vendor/cheap-1', 'vendor/cheap-2', 'vendor/cheap-2'],
            $this->requestedModels()
        );
    }

    public function test_each_failed_attempt_is_logged_with_model_tier_task_type_and_error(): void
    {
        Log::spy();

        Http::fake([
            self::URL => Http::sequence()
                ->push(['error' => ['message' => 'rate limited']], 429)
                ->push($this->completionBody('{"stars":4}', ['model' => 'vendor/cheap-2'])),
        ]);

        $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) {
                return $message === 'OpenRouter call attempt failed'
                    && $context['model'] === 'vendor/cheap-1'
                    && $context['tier'] === 'cheap'
                    && $context['task_type'] === 'jd_rating'
                    && $context['status'] === 429
                    && $context['attempt'] === 1
                    && str_contains($context['error'], 'HTTP 429');
            })
            ->once();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) {
                return $message === 'OpenRouter falling back to next model in tier'
                    && $context['from_model'] === 'vendor/cheap-1'
                    && $context['to_model'] === 'vendor/cheap-2';
            })
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => $message === 'OpenRouter call succeeded after fallback')
            ->once();
    }

    public function test_exhausting_the_tier_is_logged_as_an_error_with_the_attempt_history(): void
    {
        Log::spy();

        Http::fake([self::URL => Http::response(['error' => ['message' => 'boom']], 502)]);

        try {
            $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);
        } catch (ModelRouterException) {
            // asserted elsewhere
        }

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) {
                return $message === 'OpenRouter exhausted every candidate model in tier'
                    && $context['models'] === ['vendor/cheap-1', 'vendor/cheap-2']
                    && count($context['attempts']) === 2;
            })
            ->once();
    }

    public function test_a_successful_first_call_makes_no_extra_requests_and_logs_no_fallback(): void
    {
        Log::spy();

        Http::fake([self::URL => Http::response($this->completionBody('{"stars":4}'))]);

        $result = $this->router()->complete(
            'jd_rating',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA
        );

        $this->assertSame('vendor/cheap-1', $result->modelUsed);
        Http::assertSentCount(1);
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
    }
}
