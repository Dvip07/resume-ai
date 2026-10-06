<?php

namespace Tests\Unit\Services\Apply;

use App\Services\Apply\ApplyRunResult;
use App\Services\Apply\ApplyStepScript;
use App\Services\Apply\ApplyWorkerClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PHP's side of the automation worker's `POST /apply` endpoint (task 15.2,
 * Requirements 9.1 and 9.2).
 *
 * Every worker call is faked, so no browser is launched and nothing leaves the
 * suite — the same arrangement as the enrichment fallback tests.
 */
class ApplyWorkerClientTest extends TestCase
{
    private const APPLY_URL = 'https://jobs.example.com/apply/1';

    private const WORKER = 'http://127.0.0.1:8081/apply';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.automation_worker.enabled' => true,
            'services.automation_worker.base_url' => 'http://127.0.0.1:8081',
            'services.automation_worker.apply_path' => '/apply',
            'services.automation_worker.token' => 'test-token',
            'services.automation_worker.timeout' => 45,
            'services.automation_worker.connect_timeout' => 5,
            'services.automation_worker.apply_timeout' => 180,
            'services.automation_worker.apply_timeout_ms' => null,
            'services.automation_worker.apply_user_agent' => null,
        ]);

        Http::preventStrayRequests();
    }

    private function client(): ApplyWorkerClient
    {
        return $this->app->make(ApplyWorkerClient::class);
    }

    private function script(): ApplyStepScript
    {
        return ApplyStepScript::make()
            ->fill('#email', 'a@b.test')
            ->screenshot('review page')
            ->click('#submit');
    }

    /** @param array<string, mixed> $body */
    private function fakeWorker(array $body, int $status = 200): void
    {
        Http::fake([self::WORKER => Http::response($body, $status)]);
    }

    /** @return array<string, mixed> */
    private function successBody(array $overrides = []): array
    {
        return array_merge([
            'url' => self::APPLY_URL,
            'finalUrl' => 'https://jobs.example.com/apply/confirmed',
            'status' => 200,
            'title' => 'Application received',
            'html' => '<html><body>Thanks for applying</body></html>',
            'elapsedMs' => 4210,
            'steps' => [
                ['index' => 0, 'kind' => 'fill', 'status' => 'ok', 'selector' => '#email'],
                ['index' => 1, 'kind' => 'screenshot', 'status' => 'ok', 'name' => 'review_page'],
                ['index' => 2, 'kind' => 'click', 'status' => 'ok', 'selector' => '#submit'],
            ],
            'screenshots' => [
                ['name' => 'review_page', 'format' => 'png', 'bytes' => 4, 'base64' => base64_encode('PNG!')],
            ],
            'failedStep' => null,
            'navigationFailed' => false,
        ], $overrides);
    }

    public function test_it_posts_the_step_script_with_the_shared_token(): void
    {
        $this->fakeWorker($this->successBody());

        $result = $this->client()->run(self::APPLY_URL, $this->script());

        $this->assertTrue($result->ok);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === self::WORKER
                && $request->hasHeader('X-Automation-Token', 'test-token')
                && $body['url'] === self::APPLY_URL
                // Screenshots are the audit trail, so assets must not be stripped.
                && $body['blockAssets'] === false
                && count($body['steps']) === 3
                && $body['steps'][0] === ['kind' => 'fill', 'selector' => '#email', 'value' => 'a@b.test']
                && $body['steps'][1]['kind'] === 'screenshot'
                && $body['steps'][2] === ['kind' => 'click', 'selector' => '#submit'];
        });
    }

    public function test_it_maps_a_successful_run_including_decoded_screenshots(): void
    {
        $this->fakeWorker($this->successBody());

        $result = $this->client()->run(self::APPLY_URL, $this->script());

        $this->assertTrue($result->allStepsSucceeded());
        $this->assertNull($result->failedStep);
        $this->assertSame('https://jobs.example.com/apply/confirmed', $result->finalUrl);
        $this->assertSame('Application received', $result->title);
        $this->assertSame(200, $result->status);
        $this->assertSame(4210, $result->elapsedMs);
        $this->assertCount(3, $result->steps);

        $this->assertSame(['review_page'], $result->screenshotNames());
        $shot = $result->screenshot('review_page');
        $this->assertNotNull($shot);
        $this->assertSame('PNG!', $shot->bytes);
        $this->assertSame('review_page.png', $shot->filename());
    }

    public function test_a_failing_step_is_still_a_completed_run_with_artifacts(): void
    {
        $this->fakeWorker($this->successBody([
            'steps' => [
                ['index' => 0, 'kind' => 'fill', 'status' => 'ok', 'selector' => '#email'],
                ['index' => 1, 'kind' => 'click', 'status' => 'failed', 'selector' => '#submit', 'error' => 'Timeout 5000ms exceeded'],
                ['index' => 2, 'kind' => 'screenshot', 'status' => 'skipped', 'name' => 'done'],
            ],
            'failedStep' => ['index' => 1, 'kind' => 'click', 'status' => 'failed', 'selector' => '#submit', 'error' => 'Timeout 5000ms exceeded'],
        ]));

        $result = $this->client()->run(self::APPLY_URL, $this->script());

        // The run happened; judging it is the adapter's job, not the client's.
        $this->assertTrue($result->ok);
        $this->assertFalse($result->allStepsSucceeded());
        $this->assertNotNull($result->failedStep);
        $this->assertSame(1, $result->failedStep->index);
        $this->assertSame('#submit', $result->failedStep->selector);
        $this->assertStringContainsString('Timeout', (string) $result->failedStep->error);
        $this->assertCount(1, $result->screenshots);
    }

    public function test_it_reads_back_text_captured_by_a_read_text_step(): void
    {
        $this->fakeWorker($this->successBody([
            'steps' => [
                ['index' => 0, 'kind' => 'readText', 'status' => 'ok', 'selector' => '.error', 'name' => 'validation', 'text' => 'Phone is required'],
            ],
        ]));

        $result = $this->client()->run(self::APPLY_URL, $this->script());

        $this->assertSame('Phone is required', $result->text('validation'));
        $this->assertNull($result->text('missing'));
    }

    /**
     * @dataProvider refusals
     */
    public function test_worker_refusals_come_back_described_rather_than_thrown(int $status, string $error, string $expected): void
    {
        $this->fakeWorker(['error' => $error, 'message' => 'details here'], $status);

        $result = $this->client()->run(self::APPLY_URL, $this->script());

        $this->assertFalse($result->ok);
        $this->assertSame($status, $result->httpStatus);
        $this->assertStringContainsString($expected, (string) $result->failureReason);
    }

    /** @return array<string, array{int, string, string}> */
    public static function refusals(): array
    {
        return [
            'bad token' => [401, 'unauthorized', 'rejected the shared token'],
            'malformed script' => [422, 'invalid_request', 'rejected the apply script'],
            'no slots' => [503, 'busy', 'no free browser slots'],
            'navigation failed' => [502, 'navigation_failed', 'could not open the application page'],
        ];
    }

    public function test_an_unreachable_worker_is_reported_not_thrown(): void
    {
        Http::fake([self::WORKER => fn () => throw new ConnectionException('Connection refused')]);

        $result = $this->client()->run(self::APPLY_URL, $this->script());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('unreachable', (string) $result->failureReason);
    }

    public function test_a_disabled_worker_short_circuits_without_any_http_call(): void
    {
        config(['services.automation_worker.enabled' => false]);

        $client = $this->client();

        $this->assertFalse($client->isConfigured());

        $result = $client->run(self::APPLY_URL, $this->script());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('disabled', (string) $result->failureReason);

        Http::assertNothingSent();
    }

    public function test_an_empty_script_is_refused_locally(): void
    {
        $result = $this->client()->run(self::APPLY_URL, ApplyStepScript::make());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('empty apply script', (string) $result->failureReason);

        Http::assertNothingSent();
    }

    public function test_configured_budgets_and_user_agent_are_forwarded(): void
    {
        config([
            'services.automation_worker.apply_timeout_ms' => 120000,
            'services.automation_worker.apply_user_agent' => 'Mozilla/5.0 (Macintosh)',
        ]);

        $this->fakeWorker($this->successBody());

        $this->client()->run(self::APPLY_URL, $this->script());

        Http::assertSent(function ($request) {
            return $request->data()['timeoutMs'] === 120000
                && $request->data()['userAgent'] === 'Mozilla/5.0 (Macintosh)';
        });
    }

    public function test_a_non_json_body_is_treated_as_unavailable(): void
    {
        Http::fake([self::WORKER => Http::response('not json', 200, ['Content-Type' => 'text/plain'])]);

        $result = $this->client()->run(self::APPLY_URL, $this->script());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('unreadable', (string) $result->failureReason);
    }

    public function test_unavailable_results_never_claim_the_steps_succeeded(): void
    {
        $result = ApplyRunResult::unavailable('worker down');

        $this->assertFalse($result->allStepsSucceeded());
        $this->assertSame([], $result->screenshotNames());
    }
}
