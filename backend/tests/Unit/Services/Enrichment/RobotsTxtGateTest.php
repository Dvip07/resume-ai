<?php

namespace Tests\Unit\Services\Enrichment;

use App\Services\Enrichment\RobotsTxtGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The robots.txt gate that guards every enrichment fetch (Requirement 3.4c,
 * task 9.1). Every test fakes the HTTP layer — nothing reaches a real host.
 */
class RobotsTxtGateTest extends TestCase
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
            'enrichment.robots.cache_ttl' => 86400,
            'enrichment.robots.error_cache_ttl' => 900,
            'enrichment.robots_user_agent' => 'ResumeAiBot',
        ]);

        Http::preventStrayRequests();
    }

    private function gate(): RobotsTxtGate
    {
        return $this->app->make(RobotsTxtGate::class);
    }

    private function fakeRobots(string $body, int $status = 200): void
    {
        Http::fake([self::ROBOTS => Http::response($body, $status)]);
    }

    public function test_a_missing_robots_file_means_the_url_is_allowed(): void
    {
        $this->fakeRobots('', 404);

        $this->assertTrue($this->gate()->allows(self::JOB_URL));
    }

    public function test_a_disallowed_prefix_blocks_the_url(): void
    {
        $this->fakeRobots("User-agent: *\nDisallow: /careers/\n");

        $this->assertFalse($this->gate()->allows(self::JOB_URL));
    }

    public function test_an_unrelated_disallow_leaves_the_url_allowed(): void
    {
        $this->fakeRobots("User-agent: *\nDisallow: /admin/\n");

        $this->assertTrue($this->gate()->allows(self::JOB_URL));
    }

    public function test_an_empty_disallow_value_allows_everything(): void
    {
        $this->fakeRobots("User-agent: *\nDisallow:\n");

        $this->assertTrue($this->gate()->allows(self::JOB_URL));
    }

    public function test_disallow_all_blocks_every_path(): void
    {
        $this->fakeRobots("User-agent: *\nDisallow: /\n");

        $this->assertFalse($this->gate()->allows(self::JOB_URL));
        $this->assertFalse($this->gate()->allows('https://jobs.example.com/'));
    }

    public function test_a_longer_allow_rule_overrides_a_shorter_disallow(): void
    {
        $this->fakeRobots("User-agent: *\nDisallow: /careers/\nAllow: /careers/123\n");

        $this->assertTrue($this->gate()->allows(self::JOB_URL));
        $this->assertFalse($this->gate()->allows('https://jobs.example.com/careers/456'));
    }

    public function test_a_rule_for_our_agent_wins_over_the_wildcard_group(): void
    {
        $this->fakeRobots(
            "User-agent: *\nDisallow: /\n\nUser-agent: ResumeAiBot\nDisallow: /admin/\n"
        );

        $this->assertTrue($this->gate()->allows(self::JOB_URL));
        $this->assertFalse($this->gate()->allows('https://jobs.example.com/admin/x'));
    }

    public function test_wildcard_and_end_anchored_patterns_are_honoured(): void
    {
        $this->fakeRobots("User-agent: *\nDisallow: /*/apply$\n");

        $this->assertFalse($this->gate()->allows('https://jobs.example.com/careers/apply'));
        $this->assertTrue($this->gate()->allows('https://jobs.example.com/careers/apply/step-2'));
        $this->assertTrue($this->gate()->allows(self::JOB_URL));
    }

    public function test_an_unreadable_robots_file_fails_closed(): void
    {
        $this->fakeRobots('boom', 500);

        $this->assertFalse($this->gate()->allows(self::JOB_URL));
    }

    public function test_an_unreadable_robots_file_can_be_configured_to_fail_open(): void
    {
        config(['enrichment.robots.allow_on_unreachable' => true]);
        $this->fakeRobots('boom', 500);

        $this->assertTrue($this->gate()->allows(self::JOB_URL));
    }

    public function test_the_policy_is_fetched_once_per_host_and_then_cached(): void
    {
        $this->fakeRobots("User-agent: *\nDisallow: /admin/\n");

        $gate = $this->gate();
        $gate->allows(self::JOB_URL);
        $gate->allows('https://jobs.example.com/careers/999');
        $this->app->make(RobotsTxtGate::class)->allows('https://jobs.example.com/careers/1000');

        Http::assertSentCount(1);
    }

    public function test_the_gate_can_be_switched_off_entirely(): void
    {
        config(['enrichment.robots.enabled' => false]);
        Http::fake();

        $this->assertTrue($this->gate()->allows(self::JOB_URL));
        Http::assertNothingSent();
    }

    public function test_a_disallowed_query_string_pattern_is_matched(): void
    {
        $this->fakeRobots("User-agent: *\nDisallow: /*?preview=\n");

        $this->assertFalse($this->gate()->allows('https://jobs.example.com/careers/123?preview=1'));
        $this->assertTrue($this->gate()->allows(self::JOB_URL));
    }
}
