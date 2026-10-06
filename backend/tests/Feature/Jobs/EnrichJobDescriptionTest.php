<?php

namespace Tests\Feature\Jobs;

use App\Enums\PipelineStage;
use App\Jobs\EnrichJobDescription;
use App\Models\JobListing;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Enrichment\JobEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The single consolidated enrichment job (task 9.3): which rows it touches, and
 * what each enrichment outcome means for `pipeline_stage`.
 *
 * The service is replaced by a stub returning a fixed EnrichmentResult, so no
 * HTTP, robots.txt lookup or DOM parsing is involved — fetching and extraction
 * are already covered by JobEnrichmentServiceTest and
 * AutomationWorkerFallbackTest. What is under test here is only the job's own
 * decisions.
 *
 * Validates: Requirements 3.4, 12.3
 */
class EnrichJobDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://jobs.example.com/careers/123';

    protected function setUp(): void
    {
        parent::setUp();

        config(['job_sources.min_description_length' => 400]);
    }

    private function listing(array $overrides = []): JobListing
    {
        return JobListing::create(array_merge([
            'api_id' => '123',
            'title' => 'Senior Laravel Engineer',
            'company' => 'Acme',
            'location' => 'Toronto, Ontario, Canada',
            'description' => 'Teaser only.',
            'api_source' => 'adzuna',
            'source_key' => 'adzuna',
            'is_active' => true,
            'parsed_skills' => [],
            'application_url' => self::URL,
            'pipeline_stage' => PipelineStage::Discovered,
        ], $overrides));
    }

    /** Text long enough to clear the minimum usable description length. */
    private function fullDescription(): string
    {
        return str_repeat('We need a Laravel engineer who cares about correctness. ', 12);
    }

    private function runWith(EnrichmentResult $result, EnrichJobDescription $job): StubEnrichment
    {
        $stub = new StubEnrichment($result);
        $this->app->instance(JobEnrichmentService::class, $stub);

        $this->app->call([$job, 'handle']);

        return $stub;
    }

    public function test_a_successful_fetch_stores_the_description_and_leaves_the_row_at_enriching(): void
    {
        $listing = $this->listing();
        $description = $this->fullDescription();

        $stub = $this->runWith(
            EnrichmentResult::success($description, '.job-description', 200),
            new EnrichJobDescription($listing->id),
        );

        $this->assertSame([self::URL], $stub->calls);
        $listing->refresh();
        $this->assertSame($description, $listing->description);
        // Scoring owns the transition to `scored` (task 10.3); enrichment must
        // not invent a stage of its own.
        $this->assertSame(PipelineStage::Enriching, $listing->pipeline_stage);
    }

    public function test_a_row_with_a_usable_description_is_skipped_entirely(): void
    {
        $existing = $this->fullDescription();
        $listing = $this->listing(['description' => $existing]);

        $stub = $this->runWith(
            EnrichmentResult::success('should never be used', '.job-description', 200),
            new EnrichJobDescription($listing->id),
        );

        $this->assertSame([], $stub->calls, 'the service should not be called at all');
        $listing->refresh();
        $this->assertSame($existing, $listing->description);
        $this->assertSame(PipelineStage::Discovered, $listing->pipeline_stage);
    }

    public function test_force_re_enriches_a_row_that_already_has_a_description(): void
    {
        $listing = $this->listing(['description' => $this->fullDescription()]);
        $better = $this->fullDescription().' Plus the benefits section.';

        $stub = $this->runWith(
            EnrichmentResult::success($better, '.job-description', 200),
            new EnrichJobDescription($listing->id, force: true),
        );

        $this->assertSame([self::URL], $stub->calls);
        $this->assertSame($better, $listing->refresh()->description);
    }

    public function test_a_bot_wall_flags_the_row_for_review(): void
    {
        $listing = $this->listing();

        $this->runWith(
            EnrichmentResult::blocked('Page title matched the block marker "access denied".', 403),
            new EnrichJobDescription($listing->id),
        );

        $listing->refresh();
        $this->assertSame(PipelineStage::NeedsReview, $listing->pipeline_stage);
        $this->assertSame('Teaser only.', $listing->description, 'a block page is never stored as a description');
    }

    public function test_a_robots_disallowed_url_flags_the_row_for_review(): void
    {
        $listing = $this->listing();

        $this->runWith(
            EnrichmentResult::robotsDisallowed('Disallowed: /careers/'),
            new EnrichJobDescription($listing->id),
        );

        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);
    }

    public function test_a_missing_url_flags_the_row_for_review(): void
    {
        $listing = $this->listing(['application_url' => '']);

        $this->runWith(
            EnrichmentResult::invalidUrl('Not an absolute http(s) URL: (empty)'),
            new EnrichJobDescription($listing->id),
        );

        $this->assertSame(PipelineStage::NeedsReview, $listing->refresh()->pipeline_stage);
    }

    public function test_no_content_keeps_the_longest_partial_text_and_asks_for_review(): void
    {
        $listing = $this->listing();
        $partial = 'A short but longer-than-stored fragment of the posting.';

        $this->runWith(
            EnrichmentResult::noContent('Longest match was 54 characters.', bestEffort: $partial, selector: 'main'),
            new EnrichJobDescription($listing->id),
        );

        $listing->refresh();
        $this->assertSame($partial, $listing->description);
        $this->assertSame(PipelineStage::NeedsReview, $listing->pipeline_stage);
    }

    public function test_no_content_never_replaces_stored_text_with_something_shorter(): void
    {
        $listing = $this->listing(['description' => 'A teaser that is still longer than what the fetch found.']);

        $this->runWith(
            EnrichmentResult::noContent('Longest match was 6 characters.', bestEffort: 'Apply.'),
            new EnrichJobDescription($listing->id),
        );

        $listing->refresh();
        $this->assertSame('A teaser that is still longer than what the fetch found.', $listing->description);
        $this->assertSame(PipelineStage::NeedsReview, $listing->pipeline_stage);
    }

    public function test_a_transport_failure_is_rethrown_so_the_queue_retries_it(): void
    {
        $listing = $this->listing();

        $this->expectException(RuntimeException::class);

        try {
            $this->runWith(
                EnrichmentResult::fetchFailed('Fetch returned HTTP 503.', 503),
                new EnrichJobDescription($listing->id),
            );
        } finally {
            // Still mid-flight, not a dead end: the row must not be flagged.
            $this->assertSame(PipelineStage::Enriching, $listing->refresh()->pipeline_stage);
        }
    }

    public function test_exhausted_retries_mark_the_row_failed(): void
    {
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Enriching]);

        (new EnrichJobDescription($listing->id))->failed(new RuntimeException('Fetch returned HTTP 503.'));

        $this->assertSame(PipelineStage::Failed, $listing->refresh()->pipeline_stage);
    }

    public function test_a_submitted_application_is_never_dragged_back_into_the_pipeline(): void
    {
        $listing = $this->listing(['pipeline_stage' => PipelineStage::Applied]);

        $this->runWith(
            EnrichmentResult::success($this->fullDescription(), '.job-description', 200),
            new EnrichJobDescription($listing->id),
        );

        $this->assertSame(PipelineStage::Applied, $listing->refresh()->pipeline_stage);
    }

    public function test_a_deleted_listing_is_a_no_op(): void
    {
        $stub = $this->runWith(
            EnrichmentResult::success($this->fullDescription(), '.job-description', 200),
            new EnrichJobDescription(999999),
        );

        $this->assertSame([], $stub->calls);
    }

    public function test_the_legacy_scraping_entry_points_are_gone(): void
    {
        // Requirement 12.3: one implementation, not three.
        $this->assertFalse(class_exists(\App\Jobs\UpdateJobDescription::class));
        $this->assertFalse(class_exists(\App\Console\Commands\UpdateJobDescriptions::class));
        $this->assertFalse(method_exists(\App\Http\Controllers\JobListingController::class, 'updateJobDescriptions'));
        $this->assertFalse(method_exists(\App\Http\Controllers\JobListingController::class, 'scrapeAndUpdateJobDescription'));
    }
}

/**
 * Records the URLs it was asked about and answers with one canned result.
 * Subclasses the real service rather than mocking an interface, so a signature
 * change to `fetchDescription()` breaks this test loudly.
 */
class StubEnrichment extends JobEnrichmentService
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly EnrichmentResult $result)
    {
        // Deliberately does not call the parent constructor: the collaborators
        // it wires up (robots gate, automation worker) must never be reachable
        // from here.
    }

    public function fetchDescription(?string $url): EnrichmentResult
    {
        $this->calls[] = (string) $url;

        return $this->result;
    }
}
