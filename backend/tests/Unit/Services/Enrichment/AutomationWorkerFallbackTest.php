<?php

namespace Tests\Unit\Services\Enrichment;

use App\Enums\EnrichmentOutcome;
use App\Services\Enrichment\AutomationWorkerClient;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Enrichment\JobEnrichmentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The browser-automation fallback path (Requirement 3.4b, task 9.2): when the
 * plain fetch finds nothing usable, the Node/Playwright worker re-renders the
 * page and the same extraction runs over the result.
 *
 * All HTTP is faked — the page, robots.txt and the worker itself — so no browser
 * is launched and nothing leaves the test suite.
 */
class AutomationWorkerFallbackTest extends TestCase
{
    private const ROBOTS = 'https://jobs.example.com/robots.txt';

    private const JOB_URL = 'https://jobs.example.com/careers/123';

    private const WORKER = 'http://127.0.0.1:8081/render';

    /** An SPA shell: served HTML has no description for any selector to find. */
    private const SHELL = '<html><head><title>Role</title></head><body><div id="root"></div></body></html>';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'enrichment.robots.enabled' => true,
            'enrichment.user_agent' => 'ResumeAiBot/1.0 (+test)',
            'enrichment.min_description_length' => 400,
            'enrichment.max_bytes' => 3_000_000,
            'services.automation_worker.enabled' => true,
            'services.automation_worker.base_url' => 'http://127.0.0.1:8081',
            'services.automation_worker.render_path' => '/render',
            'services.automation_worker.token' => 'test-token',
            'services.automation_worker.timeout' => 45,
            'services.automation_worker.connect_timeout' => 5,
            'services.automation_worker.wait_until' => null,
            'services.automation_worker.settle_ms' => null,
            'services.automation_worker.wait_for_selector' => false,
        ]);

        Http::preventStrayRequests();
    }

    private function service(): JobEnrichmentService
    {
        return $this->app->make(JobEnrichmentService::class);
    }

    private function longBody(string $lead = 'About the role.'): string
    {
        return $lead.' '.str_repeat('We need a Laravel engineer who cares about correctness. ', 12);
    }

    /**
     * @param array<string, mixed>|null $workerResponse Body the worker answers with,
     *                                                  or null to leave it unfaked.
     */
    private function fake(string $pageHtml, ?array $workerResponse = null, int $workerStatus = 200): void
    {
        $fakes = [
            self::ROBOTS => Http::response('', 404),
            'https://jobs.example.com/careers/*' => Http::response($pageHtml, 200, ['Content-Type' => 'text/html']),
        ];

        if ($workerResponse !== null) {
            $fakes[self::WORKER] = Http::response($workerResponse, $workerStatus);
        }

        Http::fake($fakes);
    }

    private function renderedPage(string $description): string
    {
        return '<html><head><title>Role</title></head><body>'
            ."<div class=\"job-description\"><p>{$description}</p></div></body></html>";
    }

    public function test_a_client_rendered_posting_is_recovered_through_the_worker(): void
    {
        $body = $this->longBody('Rendered posting.');
        $this->fake(self::SHELL, [
            'url' => self::JOB_URL,
            'finalUrl' => self::JOB_URL,
            'status' => 200,
            'html' => $this->renderedPage($body),
            'elapsedMs' => 812,
        ]);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::Success, $result->outcome);
        $this->assertStringContainsString('Rendered posting.', $result->description);
        $this->assertSame('.job-description', $result->selector);
        $this->assertTrue($result->usedBrowser());
        $this->assertSame(EnrichmentResult::VIA_BROWSER, $result->via);
    }

    public function test_the_worker_is_called_with_the_shared_token_and_the_target_url(): void
    {
        $this->fake(self::SHELL, [
            'html' => $this->renderedPage($this->longBody()),
            'status' => 200,
        ]);

        $this->service()->fetchDescription(self::JOB_URL);

        Http::assertSent(function ($request) {
            if ($request->url() !== self::WORKER) {
                return false;
            }

            return $request['url'] === self::JOB_URL
                && $request['userAgent'] === 'ResumeAiBot/1.0 (+test)'
                && $request->header('X-Automation-Token')[0] === 'test-token';
        });
    }

    public function test_a_successful_plain_fetch_never_reaches_the_worker(): void
    {
        $body = $this->longBody();
        $this->fake('<html><head><title>Role</title></head>'
            ."<body><div class=\"job-description\"><p>{$body}</p></div></body></html>");

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::Success, $result->outcome);
        $this->assertFalse($result->usedBrowser());
        Http::assertNotSent(fn ($request) => $request->url() === self::WORKER);
    }

    public function test_a_robots_disallowed_url_is_never_rendered_in_a_browser(): void
    {
        Http::fake([
            self::ROBOTS => Http::response("User-agent: *\nDisallow: /careers/\n"),
        ]);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::RobotsDisallowed, $result->outcome);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->url() === self::WORKER);
    }

    public function test_a_bot_wall_is_not_retried_in_a_browser(): void
    {
        $this->fake('<html><head><title>Access Denied</title></head><body>no</body></html>');

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::Blocked, $result->outcome);
        Http::assertNotSent(fn ($request) => $request->url() === self::WORKER);
    }

    public function test_the_fallback_is_skipped_entirely_when_the_worker_is_disabled(): void
    {
        config(['services.automation_worker.enabled' => false]);
        $this->fake(self::SHELL);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::NoContent, $result->outcome);
        $this->assertFalse($result->usedBrowser());
        Http::assertNotSent(fn ($request) => $request->url() === self::WORKER);
    }

    public function test_an_unreachable_worker_degrades_to_the_plain_fetch_outcome(): void
    {
        Http::fake([
            self::ROBOTS => Http::response('', 404),
            'https://jobs.example.com/careers/*' => Http::response(self::SHELL, 200),
            self::WORKER => fn () => throw new ConnectionException('Connection refused'),
        ]);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::NoContent, $result->outcome);
        $this->assertFalse($result->usedBrowser());
    }

    public function test_a_worker_error_response_degrades_to_the_plain_fetch_outcome(): void
    {
        $this->fake(self::SHELL, ['error' => 'busy', 'message' => 'All 2 render slots are in use.'], 503);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::NoContent, $result->outcome);
        $this->assertFalse($result->usedBrowser());
    }

    public function test_a_worker_payload_without_html_degrades_to_the_plain_fetch_outcome(): void
    {
        $this->fake(self::SHELL, ['url' => self::JOB_URL, 'status' => 200, 'html' => '']);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::NoContent, $result->outcome);
        $this->assertFalse($result->usedBrowser());
    }

    public function test_a_bot_wall_that_only_appears_after_rendering_is_reported_as_blocked(): void
    {
        $this->fake(self::SHELL, [
            'html' => '<html><head><title>Just a moment…</title></head><body>checking</body></html>',
            'status' => 200,
        ]);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::Blocked, $result->outcome);
        $this->assertTrue($result->usedBrowser());
        $this->assertNull($result->description);
    }

    public function test_the_longer_best_effort_text_survives_when_neither_path_clears_the_floor(): void
    {
        $this->fake(
            '<html><head><title>Role</title></head><body>'
            .'<div class="job-description">Teaser.</div></body></html>',
            [
                'html' => $this->renderedPage('A longer but still too short rendered summary.'),
                'status' => 200,
            ]
        );

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::NoContent, $result->outcome);
        $this->assertSame('A longer but still too short rendered summary.', $result->description);
        $this->assertTrue($result->usedBrowser());
    }

    public function test_the_plain_best_effort_text_is_kept_when_rendering_finds_less(): void
    {
        $teaser = 'A reasonably long teaser paragraph that still falls short of the minimum length.';
        $this->fake(
            '<html><head><title>Role</title></head><body>'
            ."<div class=\"job-description\">{$teaser}</div></body></html>",
            ['html' => $this->renderedPage('Short.'), 'status' => 200]
        );

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::NoContent, $result->outcome);
        $this->assertSame($teaser, $result->description);
        $this->assertFalse($result->usedBrowser());
    }

    public function test_the_render_hint_is_only_sent_when_enabled_and_css(): void
    {
        config(['services.automation_worker.wait_for_selector' => true]);
        $this->fake(
            '<html><head><title>Role</title></head><body>'
            .'<div class="job-description">Teaser.</div></body></html>',
            ['html' => $this->renderedPage($this->longBody()), 'status' => 200]
        );

        $this->service()->fetchDescription(self::JOB_URL);

        Http::assertSent(fn ($request) => $request->url() !== self::WORKER
            || $request['waitForSelector'] === '.job-description');
    }

    public function test_optional_render_tuning_is_forwarded_only_when_configured(): void
    {
        config([
            'services.automation_worker.wait_until' => 'networkidle',
            'services.automation_worker.settle_ms' => 2500,
        ]);
        $this->fake(self::SHELL, ['html' => $this->renderedPage($this->longBody()), 'status' => 200]);

        $this->service()->fetchDescription(self::JOB_URL);

        Http::assertSent(fn ($request) => $request->url() !== self::WORKER
            || ($request['waitUntil'] === 'networkidle' && $request['settleMs'] === 2500));
    }

    public function test_the_client_reports_itself_unconfigured_without_a_base_url(): void
    {
        config(['services.automation_worker.base_url' => '']);

        $this->assertFalse($this->app->make(AutomationWorkerClient::class)->isConfigured());
    }
}
