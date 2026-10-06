<?php

namespace Tests\Feature\Services;

use App\Models\ProviderUsage;
use App\Models\User;
use App\Services\JobSources\ProviderDailyLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Per-provider daily fetch budgets (Requirement 3.6), counted in
 * `provider_usage` (Requirement 1C.1).
 *
 * Validates: Requirements 3.6, 1C.1
 */
class ProviderDailyLimiterTest extends TestCase
{
    use RefreshDatabase;

    private ProviderDailyLimiter $limiter;

    /** Real users, because `provider_usage.user_id` is a foreign key. */
    private int $userId;

    private int $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limiter = new ProviderDailyLimiter();
        $this->userId = User::factory()->create()->id;
        $this->otherUserId = User::factory()->create()->id;

        config(['services.fake_source.daily_limit' => 2]);
    }

    public function test_it_allows_calls_up_to_the_configured_limit_then_refuses(): void
    {
        $this->assertTrue($this->limiter->attempt('fake_source', $this->userId));
        $this->assertTrue($this->limiter->attempt('fake_source', $this->userId));
        $this->assertFalse($this->limiter->attempt('fake_source', $this->userId));

        // A refused call is not counted: it never happened.
        $this->assertSame(2, $this->limiter->usedToday('fake_source', $this->userId));
        $this->assertSame(0, $this->limiter->remaining('fake_source', $this->userId));
        $this->assertSame(1, ProviderUsage::count());
    }

    public function test_budgets_are_per_provider_and_per_user(): void
    {
        config(['services.other_source.daily_limit' => 1]);

        $this->assertTrue($this->limiter->attempt('fake_source', $this->userId));
        $this->assertTrue($this->limiter->attempt('fake_source', $this->userId));
        $this->assertFalse($this->limiter->attempt('fake_source', $this->userId));

        // Same user, different source: its own untouched allowance.
        $this->assertTrue($this->limiter->attempt('other_source', $this->userId));
        // Same source, different user: likewise.
        $this->assertTrue($this->limiter->attempt('fake_source', $this->otherUserId));

        $this->assertSame(2, $this->limiter->usedToday('fake_source', $this->userId));
        $this->assertSame(1, $this->limiter->usedToday('fake_source', $this->otherUserId));
        $this->assertSame(3, ProviderUsage::count());
    }

    /** The counter is a daily budget, so yesterday's spend does not carry over. */
    public function test_the_budget_resets_on_the_next_day(): void
    {
        Carbon::setTestNow('2026-02-10 23:59:00');
        $this->assertTrue($this->limiter->attempt('fake_source', $this->userId));
        $this->assertTrue($this->limiter->attempt('fake_source', $this->userId));
        $this->assertFalse($this->limiter->attempt('fake_source', $this->userId));

        Carbon::setTestNow('2026-02-11 00:01:00');
        $this->assertTrue($this->limiter->attempt('fake_source', $this->userId));
        $this->assertSame(1, $this->limiter->usedToday('fake_source', $this->userId));

        // Yesterday's row is left intact as a usage record.
        $this->assertSame(2, ProviderUsage::count());
        $this->assertSame(
            2,
            (int) ProviderUsage::query()
                ->forWindow('fake_source', $this->userId, '2026-02-10')
                ->value('call_count')
        );

        Carbon::setTestNow();
    }

    /**
     * No configured limit means unlimited: sources with no quota to manage (the
     * public ATS boards) must not be silenced by a default.
     */
    public function test_a_source_with_no_configured_limit_is_unlimited_and_uncounted(): void
    {
        config(['services.unlimited_source.daily_limit' => null]);

        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($this->limiter->attempt('unlimited_source', $this->userId));
        }

        $this->assertNull($this->limiter->limitFor('unlimited_source'));
        $this->assertNull($this->limiter->remaining('unlimited_source', $this->userId));
        $this->assertSame(0, ProviderUsage::count());
    }

    /** 0 reads as "not configured", not "block everything"; use enabled=false for that. */
    public function test_a_zero_limit_means_unlimited_not_blocked(): void
    {
        config(['services.zero_source.daily_limit' => 0]);

        $this->assertNull($this->limiter->limitFor('zero_source'));
        $this->assertTrue($this->limiter->attempt('zero_source', $this->userId));
    }

    /** A provider whose config block is named differently declares the path. */
    public function test_it_honours_a_provider_specific_limit_config_path(): void
    {
        config([
            'job_sources.providers.llm_search.limit_config' => 'services.llm_job_search.daily_limit',
            'services.llm_job_search.daily_limit' => 1,
            'services.llm_search.daily_limit' => 99,
        ]);

        $this->assertSame(1, $this->limiter->limitFor('llm_search'));
        $this->assertTrue($this->limiter->attempt('llm_search', $this->userId));
        $this->assertFalse($this->limiter->attempt('llm_search', $this->userId));
    }

    /** A platform-wide counter (no user) is kept separate from any user's. */
    public function test_a_null_user_counts_against_a_platform_wide_budget(): void
    {
        $this->assertTrue($this->limiter->attempt('fake_source', null));
        $this->assertTrue($this->limiter->attempt('fake_source', null));
        $this->assertFalse($this->limiter->attempt('fake_source', null));

        $this->assertSame(2, $this->limiter->usedToday('fake_source', null));
        $this->assertSame(0, $this->limiter->usedToday('fake_source', $this->userId));
        $this->assertTrue($this->limiter->attempt('fake_source', $this->userId));
    }

    /**
     * Deleting a user does not delete the usage record — the row is detached
     * (`nullOnDelete`) rather than removed, so a deleted-and-recreated account
     * cannot hand back a fresh allowance mid-day.
     */
    public function test_deleting_a_user_detaches_rather_than_erases_the_counter(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->limiter->attempt('fake_source', $user->id));
        $user->delete();

        $row = ProviderUsage::sole();
        $this->assertNull($row->user_id);
        $this->assertSame(1, $row->call_count);
    }
}
