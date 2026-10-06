<?php

namespace Tests\Unit\Services\JobSources;

use App\Services\JobSources\JobDiscoveryResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The accumulator JobDiscoveryOrchestrator hands back. Its dedupe-oriented
 * bookkeeping is exercised end to end in
 * Tests\Feature\Services\JobDiscoveryOrchestratorTest; this covers the
 * reporting shape on its own.
 *
 * Validates: Requirements 3.2, 3.3
 */
class JobDiscoveryResultTest extends TestCase
{
    public function test_it_reports_created_rows_before_updated_ones_without_repeats(): void
    {
        $result = new JobDiscoveryResult();
        $result->recordCreated('adzuna', 7);
        $result->recordUpdated('jsearch', 3);
        // Two sources folding into the same existing row is normal; the row is
        // still one row of work for the next stage.
        $result->recordUpdated('greenhouse', 3);
        $result->recordSkipped('greenhouse');

        $this->assertSame(1, $result->created());
        $this->assertSame(2, $result->updated());
        $this->assertSame(1, $result->skipped());
        $this->assertSame(4, $result->seen());
        $this->assertSame([7, 3], $result->touchedIds());
    }

    public function test_it_summarizes_per_provider_counts_and_failures_for_logging(): void
    {
        $result = new JobDiscoveryResult();
        // A source that answered with nothing is still visible as attempted.
        $result->registerProvider('lever');
        $result->recordCreated('adzuna', 1);
        $result->recordProviderFailure('llm_search', new RuntimeException('rate limited'));

        $this->assertSame(['lever', 'adzuna', 'llm_search'], $result->providerKeys());
        $this->assertSame(
            ['created' => 0, 'updated' => 0, 'skipped' => 0],
            $result->perProvider()['lever']
        );
        $this->assertSame(['llm_search' => 'rate limited'], $result->failures());
        $this->assertTrue($result->hasFailures());
        $this->assertFalse($result->allProvidersFailed());

        $summary = $result->toArray();
        $this->assertSame(1, $summary['created']);
        $this->assertSame(['llm_search' => 'rate limited'], $summary['failures']);
    }

    /**
     * Requirement 3.6: a source skipped for budget is reported apart from a
     * broken one, so "done for today" never reads as "misconfigured".
     */
    public function test_budget_exhausted_providers_are_reported_apart_from_failures(): void
    {
        $result = new JobDiscoveryResult();
        $result->recordCreated('greenhouse', 5);
        $result->recordRateLimited('adzuna', 25);

        $this->assertSame(['adzuna' => 25], $result->rateLimited());
        $this->assertSame(['adzuna'], $result->rateLimitedProviderKeys());
        $this->assertTrue($result->wasRateLimited('adzuna'));
        $this->assertFalse($result->wasRateLimited('greenhouse'));
        // Not a failure, and still part of the fan-out.
        $this->assertFalse($result->hasFailures());
        $this->assertSame(['greenhouse', 'adzuna'], $result->providerKeys());
        $this->assertSame(['adzuna' => 25], $result->toArray()['rate_limited']);
    }

    /** Every source being out of budget is not the all-sources-broken alarm. */
    public function test_an_all_budgets_spent_run_is_not_an_all_providers_failed_run(): void
    {
        $result = new JobDiscoveryResult();
        $result->recordRateLimited('adzuna', 25);
        $result->recordRateLimited('llm_search', 4);

        $this->assertTrue($result->hasRateLimited());
        $this->assertFalse($result->allProvidersFailed());
        $this->assertSame(0, $result->seen());
    }
}
