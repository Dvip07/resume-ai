<?php

namespace Tests\Feature\Jobs;

use App\Enums\PipelineStage;
use App\Enums\TailoredDocumentStatus;
use App\Enums\TailoredDocumentType;
use App\Jobs\SubmitApplication;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\TailoredDocument;
use App\Models\User;
use App\Models\UserAutomationSetting;
use App\Models\UserProfile;
use App\Services\Apply\ApplyAdapter;
use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyDailyLimiter;
use App\Services\Apply\ApplyResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The apply stage as a queued job (task 15.7): which adapter gets picked, and
 * what each outcome does to the `applications` row and the listing's
 * `pipeline_stage`.
 *
 * Nothing real is driven — the automation worker is faked over HTTP and S3 is
 * faked — so what is under test is this job's own share of the work: the
 * first-match adapter lookup, the four outcomes, the audit trail, and the
 * promise that a misbehaving adapter cannot take the queue worker with it.
 *
 * Validates: Requirements 9.1, 9.5
 */
class SubmitApplicationTest extends TestCase
{
    use RefreshDatabase;

    private const GREENHOUSE_URL = 'https://boards.greenhouse.io/acme/jobs/4242';

    private const COMPANY_URL = 'https://careers.acme.example/jobs/4242';

    private const LINKEDIN_URL = 'https://www.linkedin.com/jobs/view/3912345678/';

    private const WORKER = 'http://127.0.0.1:8081/apply';

    private const RESUME_KEY = 'users/1/tailored-documents/1/resume.pdf';

    /** Answers for the questions Greenhouse config marks required. */
    private const ANSWERS = [
        'work_authorization' => 'Yes',
        'visa_sponsorship' => 'No',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Storage::disk('s3')->put(self::RESUME_KEY, '%PDF-1.4 resume');

        config([
            'services.automation_worker.enabled' => true,
            'services.automation_worker.base_url' => 'http://127.0.0.1:8081',
            'services.automation_worker.apply_path' => '/apply',
            'services.automation_worker.token' => 'test-token',
            'apply.document_disk' => 's3',
            'apply.screenshot_disk' => 's3',
        ]);

        Http::preventStrayRequests();

        $this->user = User::factory()->create();
        $this->profile();
    }

    public function test_a_confirmed_run_marks_the_listing_applied_and_records_the_adapter(): void
    {
        $listing = $this->listing(self::GREENHOUSE_URL);
        $this->resume($listing);
        $this->fakeWorkerSuccess();

        $this->dispatch($listing);

        $this->assertSame(PipelineStage::Applied, $listing->fresh()->pipeline_stage);

        $application = $this->application($listing);
        $this->assertSame(ApplyResult::STATUS_APPLIED, $application->status);
        $this->assertSame('greenhouse', $application->apply_adapter_used);
        $this->assertNotNull($application->applied_at);

        $log = $application->automation_log;
        $this->assertSame('greenhouse', $log['adapter']);
        $this->assertSame(self::GREENHOUSE_URL, $log['application_url']);
        $this->assertNull($log['failure_reason']);
        $this->assertSame([], $log['unanswered_questions']);
        $this->assertNotEmpty($log['steps']);
        // Screenshots are the audit trail (Req 9.5) and arrive as keys, not
        // bytes — and the key has to point at something that is actually there.
        $this->assertNotEmpty($log['screenshots']);

        foreach ($log['screenshots'] as $key) {
            $this->assertTrue(Storage::disk('s3')->exists($key), $key.' was not written');
        }
    }

    public function test_an_unreachable_worker_fails_the_listing_without_claiming_a_submission(): void
    {
        $listing = $this->listing(self::GREENHOUSE_URL);
        $this->resume($listing);
        Http::fake([self::WORKER => Http::response(['error' => 'boom'], 500)]);

        $this->dispatch($listing);

        $this->assertSame(PipelineStage::Failed, $listing->fresh()->pipeline_stage);

        $application = $this->application($listing);
        $this->assertSame(ApplyResult::STATUS_FAILED, $application->status);
        $this->assertSame('greenhouse', $application->apply_adapter_used);
        $this->assertNotNull($application->automation_log['failure_reason']);
    }

    public function test_an_unanswered_required_question_pauses_for_review_with_the_question_named(): void
    {
        $listing = $this->listing(self::GREENHOUSE_URL);
        $this->resume($listing);
        $this->fakeWorkerSuccess();

        // No answers: the adapter refuses to guess and stops before submit.
        SubmitApplication::dispatchSync($listing->id, $this->user->id, []);

        $this->assertSame(PipelineStage::NeedsReview, $listing->fresh()->pipeline_stage);

        $application = $this->application($listing);
        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $application->status);
        $this->assertSame('greenhouse', $application->apply_adapter_used);
        $this->assertNotEmpty($application->automation_log['unanswered_questions']);
    }

    public function test_no_matching_adapter_needs_review_with_an_apply_manually_note_and_no_worker_call(): void
    {
        $listing = $this->listing(self::COMPANY_URL);
        $this->resume($listing);

        $this->dispatch($listing);

        $this->assertSame(PipelineStage::NeedsReview, $listing->fresh()->pipeline_stage);

        $application = $this->application($listing);
        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $application->status);
        $this->assertNull($application->apply_adapter_used);
        $this->assertStringContainsString('manually', $application->automation_log['failure_reason']);
        // Nothing was driven, so nothing was spent.
        Http::assertNothingSent();
    }

    public function test_an_adapter_that_throws_becomes_a_failed_attempt_rather_than_a_dead_queue_worker(): void
    {
        config(['apply.registry' => ['explosive' => ExplosiveApplyAdapter::class]]);

        $listing = $this->listing(self::GREENHOUSE_URL);
        $this->resume($listing);

        $this->dispatch($listing);

        $this->assertSame(PipelineStage::Failed, $listing->fresh()->pipeline_stage);

        $application = $this->application($listing);
        $this->assertSame(ApplyResult::STATUS_FAILED, $application->status);
        $this->assertSame('explosive', $application->apply_adapter_used);
        $this->assertStringContainsString('selector cache exploded', $application->automation_log['failure_reason']);
    }

    public function test_a_listing_with_no_usable_resume_is_sent_to_review_without_calling_the_worker(): void
    {
        $listing = $this->listing(self::GREENHOUSE_URL);
        // Rendered, but the upload never landed: `s3_path` is empty.
        $this->resume($listing, ['s3_path' => '']);

        $this->dispatch($listing);

        $this->assertSame(PipelineStage::NeedsReview, $listing->fresh()->pipeline_stage);
        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $this->application($listing)->status);
        Http::assertNothingSent();
    }

    public function test_an_already_applied_listing_is_not_submitted_twice(): void
    {
        $listing = $this->listing(self::GREENHOUSE_URL, ['pipeline_stage' => PipelineStage::Applied]);
        $this->resume($listing);

        $this->dispatch($listing);

        $this->assertSame(PipelineStage::Applied, $listing->fresh()->pipeline_stage);
        $this->assertNull(Application::query()->where('job_listing_id', $listing->id)->first());
        Http::assertNothingSent();
    }

    public function test_a_real_attempt_counts_against_both_the_global_and_the_platform_budget(): void
    {
        $listing = $this->listing(self::GREENHOUSE_URL);
        $this->resume($listing);
        $this->fakeWorkerSuccess();

        $this->dispatch($listing);

        $limiter = app(ApplyDailyLimiter::class);
        $this->assertSame(1, $limiter->usedToday(null, $this->user->id));
        $this->assertSame(1, $limiter->usedToday('greenhouse', $this->user->id));
    }

    public function test_the_platform_cap_stops_the_run_before_the_worker_is_called(): void
    {
        // The platform is spent; the user's own budget is untouched, so this
        // can only be the per-platform cap talking.
        config([
            'apply.adapters.greenhouse.daily_cap' => 0,
            'pipeline.daily_apply_cap' => 20,
        ]);

        $listing = $this->listing(self::GREENHOUSE_URL);
        $this->resume($listing);

        $this->dispatch($listing);

        Http::assertNothingSent();
        $this->assertCapped($listing);

        $limiter = app(ApplyDailyLimiter::class);
        $this->assertSame(0, $limiter->usedToday('greenhouse', $this->user->id));
        $this->assertSame(
            0,
            $limiter->usedToday(null, $this->user->id),
            'a refused attempt must not spend the other cap\'s budget'
        );
    }

    public function test_the_global_cap_stops_the_run_independently_of_the_platform_cap(): void
    {
        // Mirror image: platform budget wide open, the user's own cap spent.
        config([
            'apply.adapters.greenhouse.daily_cap' => 100,
            'pipeline.daily_apply_cap' => 0,
        ]);

        $listing = $this->listing(self::GREENHOUSE_URL);
        $this->resume($listing);

        $this->dispatch($listing);

        Http::assertNothingSent();
        $this->assertCapped($listing);
        $this->assertSame(0, app(ApplyDailyLimiter::class)->usedToday(null, $this->user->id));
    }

    public function test_a_capped_posting_is_submitted_once_the_budget_resets(): void
    {
        config(['pipeline.daily_apply_cap' => 0]);

        $listing = $this->listing(self::GREENHOUSE_URL);
        $this->resume($listing);

        $this->dispatch($listing);

        $this->assertCapped($listing);
        Http::assertNothingSent();

        // Tomorrow (modelled here as the cap being restored): the same listing
        // goes through, with no leftover state blocking it.
        config(['pipeline.daily_apply_cap' => 20]);
        $this->fakeWorkerSuccess();

        $this->dispatch($listing);

        $this->assertSame(PipelineStage::Applied, $listing->fresh()->pipeline_stage);
        $this->assertSame(ApplyResult::STATUS_APPLIED, $this->application($listing)->status);
    }

    /**
     * Requirement 9.3. A LinkedIn posting for a user who has not opted in:
     * resolved to nothing, driven not at all, and reported as itself rather
     * than as an unsupported ATS.
     */
    public function test_a_linkedin_posting_is_never_driven_without_the_opt_in(): void
    {
        $listing = $this->listing(self::LINKEDIN_URL);
        $this->resume($listing);
        $this->optIn(false);

        $this->dispatch($listing);

        // No browser, and no budget spent — consent is checked before the cap.
        Http::assertNothingSent();

        $this->assertSame(PipelineStage::NeedsReview, $listing->fresh()->pipeline_stage);

        $application = $this->application($listing);
        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $application->status);
        // Named, so the row says "LinkedIn, blocked by your settings" rather
        // than "no automation supports this site".
        $this->assertSame('linkedin', $application->apply_adapter_used);

        $log = $application->automation_log;
        $this->assertStringContainsString('Apply manually', $log['failure_reason']);
        $this->assertStringContainsString('automation settings', $log['failure_reason']);
        $this->assertFalse($log['metadata']['opt_in']);
        $this->assertFalse($log['metadata']['worker_called']);
        $this->assertSame('linkedin_auto_apply_opt_in', $log['metadata']['opt_in_setting']);
        $this->assertSame([], $log['screenshots']);

        $limiter = app(ApplyDailyLimiter::class);
        $this->assertSame(0, $limiter->usedToday(null, $this->user->id));
        $this->assertSame(0, $limiter->usedToday('linkedin', $this->user->id));
    }

    /** With the opt-in on, the same posting is driven and counts as LinkedIn. */
    public function test_with_the_opt_in_a_linkedin_posting_is_submitted_through_the_gated_adapter(): void
    {
        $listing = $this->listing(self::LINKEDIN_URL);
        $this->resume($listing);
        $this->optIn(true);
        $this->fakeWorkerSuccess();

        $this->dispatch($listing);

        $this->assertSame(PipelineStage::Applied, $listing->fresh()->pipeline_stage);

        $application = $this->application($listing);
        $this->assertSame(ApplyResult::STATUS_APPLIED, $application->status);
        $this->assertSame('linkedin', $application->apply_adapter_used);
        $this->assertTrue($application->automation_log['metadata']['opt_in']);

        $limiter = app(ApplyDailyLimiter::class);
        $this->assertSame(1, $limiter->usedToday('linkedin', $this->user->id));
    }

    /**
     * Requirement 9.7: LinkedIn's cap is the strictest, and it bites after five
     * submissions even though the user's global cap is nowhere near spent.
     */
    public function test_the_stricter_linkedin_cap_stops_the_sixth_attempt_of_the_day(): void
    {
        $this->optIn(true);
        config(['pipeline.daily_apply_cap' => 50]);

        $limiter = app(ApplyDailyLimiter::class);
        $this->assertSame(5, $limiter->platformLimitFor('linkedin'));

        // Five already submitted today.
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->attempt('linkedin', $this->user->id));
        }

        $listing = $this->listing(self::LINKEDIN_URL);
        $this->resume($listing);

        $this->dispatch($listing);

        Http::assertNothingSent();
        $this->assertCapped($listing);
        $this->assertSame('linkedin', $this->application($listing)->apply_adapter_used);
        $this->assertSame(5, $limiter->usedToday('linkedin', $this->user->id));
    }

    /** Consent, recorded the way the settings endpoint records it. */
    private function optIn(bool $enabled): void
    {
        UserAutomationSetting::updateOrCreate(
            ['user_id' => $this->user->id],
            ['linkedin_auto_apply_opt_in' => $enabled] + UserAutomationSetting::defaultsFor($this->user->id),
        );
    }

    /** A capped run leaves no terminal state: still pending, still `tailored`. */
    private function assertCapped(JobListing $listing): void
    {
        $this->assertSame(PipelineStage::Tailored, $listing->fresh()->pipeline_stage);

        $application = $this->application($listing);
        $this->assertNotNull($application, 'the pause has to be visible on the row');
        $this->assertSame('pending', $application->status);
        $this->assertSame(SubmitApplication::STATUS_CAPPED, $application->automation_log['status']);
        $this->assertTrue($application->automation_log['metadata']['capped']);
    }

    private function dispatch(JobListing $listing): void
    {
        SubmitApplication::dispatchSync($listing->id, $this->user->id, self::ANSWERS);
    }

    private function application(JobListing $listing): ?Application
    {
        return Application::query()
            ->where('user_id', $this->user->id)
            ->where('job_listing_id', $listing->id)
            ->first();
    }

    /**
     * A worker that runs every step successfully, screenshots what it was asked
     * to, and reads back a page that says the application landed.
     */
    private function fakeWorkerSuccess(): void
    {
        Http::fake([
            self::WORKER => function ($request) {
                $steps = $request->data()['steps'];
                $outcomes = [];
                $screenshots = [];

                foreach ($steps as $index => $step) {
                    $outcome = [
                        'index' => $index,
                        'kind' => $step['kind'],
                        'status' => 'ok',
                        'selector' => $step['selector'] ?? null,
                        'name' => $step['name'] ?? null,
                    ];

                    if ($step['kind'] === 'readText') {
                        $outcome['text'] = str_ends_with((string) ($step['name'] ?? ''), '_confirmation')
                            ? 'Thank you for applying to Acme.'
                            : 'Apply for Senior Laravel Engineer at Acme';
                    }

                    if ($step['kind'] === 'screenshot') {
                        $screenshots[] = [
                            'name' => $step['name'],
                            'format' => 'png',
                            'base64' => base64_encode('PNG:'.$step['name']),
                        ];
                    }

                    $outcomes[] = $outcome;
                }

                return Http::response([
                    'finalUrl' => self::GREENHOUSE_URL.'/confirmation',
                    'status' => 200,
                    'title' => 'Application confirmed',
                    'html' => '<html><body>Thank you for applying</body></html>',
                    'elapsedMs' => 4211,
                    'steps' => $outcomes,
                    'screenshots' => $screenshots,
                    'failedStep' => null,
                    'navigationFailed' => false,
                ]);
            },
        ]);
    }

    private function profile(): UserProfile
    {
        return UserProfile::create([
            'user_id' => $this->user->id,
            'skills' => ['primary' => ['PHP', 'Laravel'], 'secondary' => []],
            'location' => ['city' => 'Toronto', 'state' => 'Ontario', 'country' => 'Canada'],
            'linkedin_url' => 'https://linkedin.com/in/ada',
            'github_url' => '',
            'portfolio_url' => '',
            'suggested_roles' => ['Backend Engineer'],
            'experience' => [],
            'education' => [],
            'parsed_keywords' => 'php laravel',
            'resume_text' => 'Backend engineer.',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function listing(string $applicationUrl, array $overrides = []): JobListing
    {
        return JobListing::create(array_merge([
            'api_id' => 'gh-'.bin2hex(random_bytes(4)),
            'title' => 'Senior Laravel Engineer',
            'company' => 'Acme',
            'location' => 'Toronto, Ontario, Canada',
            'description' => str_repeat('We need a Laravel engineer. ', 10),
            'api_source' => 'adzuna',
            'source_key' => 'adzuna',
            'is_active' => true,
            'parsed_skills' => [],
            'application_url' => $applicationUrl,
            'pipeline_stage' => PipelineStage::Tailored,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function resume(JobListing $listing, array $overrides = []): TailoredDocument
    {
        return TailoredDocument::create(array_merge([
            'user_id' => $this->user->id,
            'job_listing_id' => $listing->id,
            'type' => TailoredDocumentType::Resume,
            'status' => TailoredDocumentStatus::Rendered,
            's3_path' => self::RESUME_KEY,
        ], $overrides));
    }
}

/**
 * An adapter that claims everything and then breaks, standing in for the null
 * dereference a real adapter will eventually hit on a weird posting.
 */
class ExplosiveApplyAdapter implements ApplyAdapter
{
    public function supports(string $applicationUrl): bool
    {
        return true;
    }

    public function apply(ApplyContext $context): ApplyResult
    {
        throw new RuntimeException('the selector cache exploded');
    }
}
