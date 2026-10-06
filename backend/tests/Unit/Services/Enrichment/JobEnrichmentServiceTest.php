<?php

namespace Tests\Unit\Services\Enrichment;

use App\Enums\EnrichmentOutcome;
use App\Services\Enrichment\JobEnrichmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Plain HTTP fetch + DOM selector extraction (Requirement 3.4a/3.4c, task 9.1).
 * All HTTP is faked, including robots.txt, so no request leaves the test suite.
 */
class JobEnrichmentServiceTest extends TestCase
{
    private const ROBOTS = 'https://jobs.example.com/robots.txt';

    private const JOB_URL = 'https://jobs.example.com/careers/123';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'enrichment.robots.enabled' => true,
            'enrichment.robots.allow_on_unreachable' => false,
            'enrichment.robots_user_agent' => 'ResumeAiBot',
            'enrichment.user_agent' => 'ResumeAiBot/1.0 (+test)',
            'enrichment.min_description_length' => 400,
            'enrichment.max_bytes' => 3_000_000,
        ]);

        Http::preventStrayRequests();
    }

    private function service(): JobEnrichmentService
    {
        return $this->app->make(JobEnrichmentService::class);
    }

    /** A body long enough to clear the minimum usable description length. */
    private function longBody(string $lead = 'About the role.'): string
    {
        return $lead.' '.str_repeat('We need a Laravel engineer who cares about correctness. ', 12);
    }

    /**
     * @param array<string, mixed> $pageResponse
     */
    private function fakePage(string $html, int $status = 200, string $robots = ''): void
    {
        Http::fake([
            self::ROBOTS => Http::response($robots, $robots === '' ? 404 : 200),
            'https://jobs.example.com/careers/*' => Http::response($html, $status, ['Content-Type' => 'text/html']),
        ]);
    }

    public function test_it_extracts_a_description_from_a_configured_selector(): void
    {
        $body = $this->longBody();
        $this->fakePage(<<<HTML
            <html><head><title>Senior Laravel Engineer at Acme</title></head>
            <body>
              <nav>Home Jobs Login</nav>
              <div class="show-more-less-html__markup"><p>{$body}</p></div>
            </body></html>
            HTML);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::Success, $result->outcome);
        $this->assertStringContainsString('Laravel engineer', $result->description);
        $this->assertNotNull($result->selector);
        $this->assertSame(200, $result->httpStatus);
    }

    public function test_extraction_returns_plain_text_with_list_structure_preserved(): void
    {
        $filler = str_repeat('Detail about the responsibilities of this role. ', 10);
        $this->fakePage(<<<HTML
            <html><head><title>Role</title></head><body>
              <div class="job-description">
                <p>{$filler}</p>
                <ul><li>Ship Laravel features</li><li>Write tests</li></ul>
              </div>
            </body></html>
            HTML);

        $description = $this->service()->fetchDescription(self::JOB_URL)->description;

        $this->assertStringNotContainsString('<li>', $description);
        $this->assertStringContainsString('- Ship Laravel features', $description);
        $this->assertStringContainsString('- Write tests', $description);
    }

    public function test_navigation_and_scripts_are_stripped_from_a_broad_match(): void
    {
        $body = $this->longBody();
        $this->fakePage(<<<HTML
            <html><head><title>Role</title></head><body>
              <main>
                <nav>Home Jobs Login</nav>
                <script>var tracking = 'analytics payload';</script>
                <p>{$body}</p>
                <footer>Cookie preferences</footer>
              </main>
            </body></html>
            HTML);

        $description = $this->service()->fetchDescription(self::JOB_URL)->description;

        $this->assertStringContainsString('Laravel engineer', $description);
        $this->assertStringNotContainsString('tracking', $description);
        $this->assertStringNotContainsString('Cookie preferences', $description);
        $this->assertStringNotContainsString('Home Jobs Login', $description);
    }

    public function test_the_longest_matching_selector_wins_over_a_truncated_one(): void
    {
        $full = $this->longBody('Full posting.');
        $this->fakePage(<<<HTML
            <html><head><title>Role</title></head><body>
              <div class="show-more-less-html__markup">Short teaser…</div>
              <div class="job-description"><p>{$full}</p></div>
            </body></html>
            HTML);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::Success, $result->outcome);
        $this->assertStringContainsString('Full posting.', $result->description);
        $this->assertSame('.job-description', $result->selector);
    }

    public function test_a_host_specific_selector_is_tried_and_reported(): void
    {
        config(['enrichment.host_selectors' => ['example.com' => ['#posting-body']]]);
        $body = $this->longBody();
        $this->fakePage(<<<HTML
            <html><head><title>Role</title></head><body>
              <div id="posting-body"><p>{$body}</p></div>
            </body></html>
            HTML);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::Success, $result->outcome);
        $this->assertSame('#posting-body', $result->selector);
    }

    public function test_the_legacy_xpath_selector_still_matches_linkedin_markup(): void
    {
        config(['enrichment.selectors' => [
            "xpath://section[contains(@class, 'show-more-less-html')]//div[contains(@class, 'show-more-less-html__markup')]",
        ]]);
        $body = $this->longBody();
        $this->fakePage(<<<HTML
            <html><head><title>Role</title></head><body>
              <section class="show-more-less-html">
                <div class="show-more-less-html__markup"><p>{$body}</p></div>
              </section>
            </body></html>
            HTML);

        $this->assertSame(EnrichmentOutcome::Success, $this->service()->fetchDescription(self::JOB_URL)->outcome);
    }

    public function test_a_robots_disallowed_url_is_never_fetched(): void
    {
        Http::fake([
            self::ROBOTS => Http::response("User-agent: *\nDisallow: /careers/\n"),
        ]);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::RobotsDisallowed, $result->outcome);
        $this->assertNull($result->description);
        $this->assertFalse($result->outcome->warrantsBrowserFallback());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === self::ROBOTS);
    }

    public function test_a_bot_wall_title_is_reported_as_blocked_not_as_content(): void
    {
        $this->fakePage('<html><head><title>Access Denied</title></head><body><main>Nope</main></body></html>');

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::Blocked, $result->outcome);
        $this->assertNull($result->description);
        $this->assertFalse($result->outcome->warrantsBrowserFallback());
    }

    public function test_a_403_is_treated_as_a_block(): void
    {
        $this->fakePage('<html><body>denied</body></html>', 403);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::Blocked, $result->outcome);
        $this->assertSame(403, $result->httpStatus);
    }

    public function test_a_server_error_is_a_retryable_fetch_failure(): void
    {
        $this->fakePage('oops', 500);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::FetchFailed, $result->outcome);
        $this->assertTrue($result->outcome->isRetryable());
    }

    public function test_a_page_with_no_matching_selector_asks_for_the_browser_fallback(): void
    {
        $this->fakePage('<html><head><title>Role</title></head><body><div id="root"></div></body></html>');

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::NoContent, $result->outcome);
        $this->assertTrue($result->outcome->warrantsBrowserFallback());
    }

    public function test_a_match_under_the_minimum_length_keeps_the_best_effort_text(): void
    {
        $this->fakePage(
            '<html><head><title>Role</title></head><body>'
            .'<div class="job-description">Apply on our site for details.</div></body></html>'
        );

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::NoContent, $result->outcome);
        $this->assertSame('Apply on our site for details.', $result->description);
        $this->assertSame('.job-description', $result->selector);
        $this->assertStringContainsString('400-character minimum', $result->reason);
    }

    public function test_the_whole_page_source_is_never_used_as_a_description(): void
    {
        $noise = str_repeat('Unrelated marketing copy about our company culture. ', 20);
        $this->fakePage(<<<HTML
            <html><head><title>Role</title></head><body>
              <div id="app-shell"><span>{$noise}</span></div>
            </body></html>
            HTML);

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::NoContent, $result->outcome);
        $this->assertNull($result->description);
    }

    public function test_a_missing_or_relative_url_is_rejected_without_any_request(): void
    {
        Http::fake();

        foreach ([null, '', '   ', '/careers/123', 'ftp://example.com/x'] as $url) {
            $this->assertSame(
                EnrichmentOutcome::InvalidUrl,
                $this->service()->fetchDescription($url)->outcome,
                'Expected '.var_export($url, true).' to be rejected.'
            );
        }

        Http::assertNothingSent();
    }

    public function test_the_declared_user_agent_is_sent_on_the_page_request(): void
    {
        $body = $this->longBody();
        $this->fakePage('<html><head><title>Role</title></head><body><main><p>'.$body.'</p></main></body></html>');

        $this->service()->fetchDescription(self::JOB_URL);

        Http::assertSent(fn ($request) => $request->url() === self::JOB_URL
            && $request->header('User-Agent')[0] === 'ResumeAiBot/1.0 (+test)');
    }

    public function test_an_oversized_response_is_refused_rather_than_parsed(): void
    {
        config(['enrichment.max_bytes' => 500]);
        $this->fakePage('<html><head><title>Role</title></head><body><main>'
            .str_repeat('x', 2000).'</main></body></html>');

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::FetchFailed, $result->outcome);
        $this->assertStringContainsString('ceiling', $result->reason);
    }

    public function test_an_invalid_configured_selector_is_skipped_instead_of_failing(): void
    {
        config(['enrichment.selectors' => ['<<<not a selector>>>', '.job-description']]);
        $body = $this->longBody();
        $this->fakePage('<html><head><title>Role</title></head>'
            ."<body><div class=\"job-description\"><p>{$body}</p></div></body></html>");

        $result = $this->service()->fetchDescription(self::JOB_URL);

        $this->assertSame(EnrichmentOutcome::Success, $result->outcome);
        $this->assertSame('.job-description', $result->selector);
    }
}
