<?php

namespace Tests\Unit\Services;

use App\Exceptions\ModelRouterException;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Transport-level behaviour of ModelRouterService::complete() (task 7.2,
 * Requirement 4.1). Every test fakes the HTTP layer — no request ever leaves
 * the machine.
 */
class ModelRouterServiceTest extends TestCase
{
    private const URL = 'https://openrouter.ai/api/v1/chat/completions';

    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['stars' => ['type' => 'integer']],
        'required' => ['stars'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic routing/credentials so assertions don't depend on the
        // developer's .env.
        config([
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.referer' => 'https://resume-ai.test',
            'services.openrouter.title' => 'Resume AI',
            'services.openrouter.default_tier' => 'cheap',
            'services.openrouter.tiers.cheap' => ['vendor/cheap-1', 'vendor/cheap-2'],
            // This class covers one round trip at a time; same-tier fallback
            // retry (task 7.4) has its own suite in ModelRouterFallbackTest.
            // One attempt per model keeps the failure paths below readable.
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
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $content]],
            ],
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 30,
                'cost' => 0.00042,
            ],
        ], $overrides);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'Rate this job.']];
    }

    public function test_structured_output_happy_path_returns_parsed_json_and_usage(): void
    {
        Http::fake([
            self::URL => Http::response($this->completionBody('{"stars":4,"rationale":"strong match"}')),
        ]);

        $result = $this->router()->complete(
            'jd_rating',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA
        );

        $this->assertSame(['stars' => 4, 'rationale' => 'strong match'], $result->parsedJson);
        $this->assertSame('vendor/cheap-1', $result->modelUsed);
        $this->assertSame('cheap', $result->tierUsed);
        $this->assertSame(120, $result->inputTokens);
        $this->assertSame(30, $result->outputTokens);
        $this->assertSame(150, $result->totalTokens());
        $this->assertSame(0.00042, $result->estimatedCostUsd);
    }

    public function test_structured_output_request_sends_json_schema_response_format(): void
    {
        Http::fake([self::URL => Http::response($this->completionBody('{"stars":3}'))]);

        $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            $this->assertSame('vendor/cheap-1', $body['model']);
            $this->assertSame($this->messages(), $body['messages']);
            $this->assertSame('json_schema', $body['response_format']['type']);
            $this->assertTrue($body['response_format']['json_schema']['strict']);
            $this->assertSame(self::SCHEMA, $body['response_format']['json_schema']['schema']);
            // Usage accounting is what task 7.5 logs against.
            $this->assertSame(['include' => true], $body['usage']);

            return true;
        });
    }

    public function test_an_already_wrapped_json_schema_block_is_passed_through(): void
    {
        Http::fake([self::URL => Http::response($this->completionBody('{"stars":3}'))]);

        $this->router()->complete(
            'jd_rating',
            $this->messages(),
            ModelTierContext::unknown(),
            ['name' => 'rating', 'strict' => false, 'schema' => self::SCHEMA]
        );

        Http::assertSent(function (Request $request) {
            $schema = $request->data()['response_format']['json_schema'];

            $this->assertSame('rating', $schema['name']);
            $this->assertFalse($schema['strict']);
            $this->assertSame(self::SCHEMA, $schema['schema']);

            return true;
        });
    }

    public function test_api_key_and_attribution_headers_are_sent(): void
    {
        Http::fake([self::URL => Http::response($this->completionBody('hello'))]);

        $this->router()->complete('resume_parse', $this->messages(), ModelTierContext::unknown());

        Http::assertSent(function (Request $request) {
            $this->assertSame(self::URL, $request->url());
            $this->assertSame('Bearer test-key', $request->header('Authorization')[0]);
            $this->assertSame('https://resume-ai.test', $request->header('HTTP-Referer')[0]);
            $this->assertSame('Resume AI', $request->header('X-Title')[0]);

            return true;
        });
    }

    public function test_fenced_json_is_recovered_when_a_model_wraps_it_in_prose(): void
    {
        $content = "Sure! Here's the analysis you asked for:\n\n"
            . "```json\n{\"stars\": 5, \"recommendedAction\": \"auto_apply\"}\n```\n\nHope that helps.";

        Http::fake([self::URL => Http::response($this->completionBody($content))]);

        $result = $this->router()->complete(
            'jd_rating',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA
        );

        $this->assertSame(['stars' => 5, 'recommendedAction' => 'auto_apply'], $result->parsedJson);
        // The untouched content is still available for the usage log / debugging.
        $this->assertSame($content, $result->content);
    }

    public function test_bare_json_is_recovered_from_surrounding_prose(): void
    {
        $content = 'Based on the description: {"stars": 2, "rationale": "junior role"} — let me know if you need more.';

        Http::fake([self::URL => Http::response($this->completionBody($content))]);

        $result = $this->router()->complete(
            'jd_rating',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA
        );

        $this->assertSame(['stars' => 2, 'rationale' => 'junior role'], $result->parsedJson);
    }

    public function test_unparseable_output_throws_when_json_was_requested(): void
    {
        Http::fake([
            self::URL => Http::response($this->completionBody('I am afraid I cannot rate this job.')),
        ]);

        try {
            $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);
            $this->fail('Expected ModelRouterException for unparseable output.');
        } catch (ModelRouterException $e) {
            // Unparseable output is retryable, so this surfaces after the whole
            // tier has been walked; the per-attempt reason is in the history.
            $this->assertStringContainsString('Could not extract JSON', $e->getMessage());

            $attempt = $e->context['attempts'][0];
            $this->assertSame('jd_rating', $attempt['task_type']);
            $this->assertSame('cheap', $attempt['tier']);
            $this->assertSame('vendor/cheap-1', $attempt['model']);
        }
    }

    public function test_prose_is_returned_as_is_when_no_schema_was_requested(): void
    {
        Http::fake([self::URL => Http::response($this->completionBody('Plain prose answer.'))]);

        $result = $this->router()->complete('resume_parse', $this->messages(), ModelTierContext::unknown());

        $this->assertSame('Plain prose answer.', $result->content);
        $this->assertSame([], $result->parsedJson);
    }

    public function test_http_error_response_throws_with_status_and_body_context(): void
    {
        Http::fake([
            self::URL => Http::response(['error' => ['message' => 'rate limited']], 429),
        ]);

        try {
            $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown(), self::SCHEMA);
            $this->fail('Expected ModelRouterException for an HTTP error.');
        } catch (ModelRouterException $e) {
            $this->assertStringContainsString('HTTP 429', $e->getMessage());
            $this->assertSame(429, $e->context['attempts'][0]['status']);

            $underlying = $e->getPrevious();
            $this->assertInstanceOf(ModelRouterException::class, $underlying);
            $this->assertStringContainsString('rate limited', $underlying->context['body']);
        }
    }

    public function test_connection_failure_becomes_a_typed_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: operation timed out'));

        try {
            $this->router()->complete('resume_tailor', $this->messages(), ModelTierContext::unknown());
            $this->fail('Expected ModelRouterException for a connection failure.');
        } catch (ModelRouterException $e) {
            $this->assertStringContainsString('operation timed out', $e->getMessage());
            $this->assertStringContainsString(
                'OpenRouter request failed for model [vendor/cheap-1]',
                $e->context['attempts'][0]['error']
            );
        }
    }

    public function test_empty_completion_content_throws(): void
    {
        Http::fake([
            self::URL => Http::response($this->completionBody('', ['choices' => [['message' => ['content' => '']]]])),
        ]);

        $this->expectException(ModelRouterException::class);
        $this->expectExceptionMessage('no completion content');

        $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown());
    }

    public function test_missing_api_key_throws_before_any_request_is_made(): void
    {
        config(['services.openrouter.api_key' => null]);

        try {
            $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown());
            $this->fail('Expected ModelRouterException for a missing API key.');
        } catch (ModelRouterException $e) {
            $this->assertStringContainsString('OPENROUTER_API_KEY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_unknown_default_tier_throws_rather_than_guessing_a_model(): void
    {
        config(['services.openrouter.default_tier' => 'nonexistent']);

        $this->expectException(ModelRouterException::class);
        $this->expectExceptionMessage('Unknown OpenRouter tier [nonexistent]');

        $this->router()->complete('jd_rating', $this->messages(), ModelTierContext::unknown());
    }

    public function test_reported_model_prefers_what_openrouter_says_actually_ran(): void
    {
        Http::fake([
            self::URL => Http::response($this->completionBody('{"stars":1}', ['model' => 'vendor/cheap-2'])),
        ]);

        $result = $this->router()->complete(
            'jd_rating',
            $this->messages(),
            ModelTierContext::unknown(),
            self::SCHEMA
        );

        $this->assertSame('vendor/cheap-2', $result->modelUsed);
    }

    /**
     * The capability pin (task 8.5): the LLM job-search source needs a model
     * that can search the live web, which no salary or complexity signal can
     * express. The pinned tier's models still come from config.
     */
    public function test_a_tier_override_pins_the_tier_regardless_of_the_context(): void
    {
        config(['services.openrouter.tiers.web_search' => ['vendor/search-1']]);

        Http::fake([
            self::URL => Http::response($this->completionBody('{"stars":5}', ['model' => 'vendor/search-1'])),
        ]);

        // A context that would otherwise route to premium.
        $result = $this->router()->complete(
            taskType: 'job_search',
            messages: $this->messages(),
            context: new ModelTierContext(salaryMax: 250000, currency: 'CAD'),
            jsonSchema: self::SCHEMA,
            tierOverride: 'web_search',
        );

        $this->assertSame('web_search', $result->tierUsed);
        $this->assertSame('vendor/search-1', $result->modelUsed);

        Http::assertSent(fn (Request $request) => $request->data()['model'] === 'vendor/search-1');
    }

    /**
     * Silently falling back to a normal tier would run a model that cannot do
     * the job and return answers from training data, so a missing pinned tier
     * has to fail loudly.
     */
    public function test_an_unconfigured_tier_override_throws_rather_than_running_a_lesser_model(): void
    {
        $this->expectException(ModelRouterException::class);
        $this->expectExceptionMessage('Unknown OpenRouter tier [web_search]');

        config(['services.openrouter.tiers' => ['cheap' => ['vendor/cheap-1']]]);

        $this->router()->complete(
            taskType: 'job_search',
            messages: $this->messages(),
            context: ModelTierContext::unknown(),
            tierOverride: 'web_search',
        );
    }
}
