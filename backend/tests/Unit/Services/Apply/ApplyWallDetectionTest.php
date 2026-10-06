<?php

namespace Tests\Unit\Services\Apply;

use App\Models\JobListing;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Apply\Adapters\GreenhouseApplyAdapter;
use App\Services\Apply\Adapters\LeverApplyAdapter;
use App\Services\Apply\Adapters\WorkdayApplyAdapter;
use App\Services\Apply\ApplyAdapter;
use App\Services\Apply\ApplyResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CAPTCHA, login-wall and bot-detection aborts (task 15.6, Requirement 9.6).
 *
 * The rule under test is the same for all three ATSs, which is why this sits
 * beside the per-adapter tests rather than inside them: a wall is always
 * `needs_review`, never `failed` (a retry hits the identical wall), never
 * `applied`, and never bypassed. The abort reason and the screenshots have to
 * reach the `ApplyResult` so the dashboard can tell the user to finish the
 * application by hand.
 *
 * The second half of the rule is the one that is easy to get wrong in the other
 * direction: every Greenhouse and Lever form carries a "protected by reCAPTCHA"
 * notice while remaining perfectly applicable, so a marker in the page's body
 * copy must not overturn a run where every step succeeded.
 */
class ApplyWallDetectionTest extends TestCase
{
    use FakesApplyWorker;

    private const WORKER = 'http://127.0.0.1:8081/apply';

    private const GREENHOUSE_URL = 'https://boards.greenhouse.io/acme/jobs/4242';

    private const RESUME_KEY = 'users/7/tailored-documents/11/resume.pdf';

    /** The posting URL under test, and therefore which adapter is driven. */
    private string $postingUrl = self::GREENHOUSE_URL;

    /** Where the worker is told the browser ended up. */
    private string $landedUrl = self::GREENHOUSE_URL;

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
    }

    private function applyUrl(): string
    {
        return $this->landedUrl;
    }

    private function confirmedUrl(): string
    {
        return self::GREENHOUSE_URL.'/confirmation';
    }

    /** Answers for every question config marks required, so runs reach the form. */
    private const ANSWERS = [
        'work_authorization' => 'Yes',
        'visa_sponsorship' => 'No',
    ];

    private function context(array $answers = self::ANSWERS): \App\Services\Apply\ApplyContext
    {
        $user = new User(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
        $user->forceFill(['id' => 7]);

        $profile = new UserProfile(['user_id' => 7, 'location' => ['city' => 'London', 'country' => 'UK']]);
        $profile->forceFill(['id' => 9]);

        $job = new JobListing;
        $job->forceFill(['id' => 31, 'application_url' => $this->postingUrl]);

        return new \App\Services\Apply\ApplyContext(
            job: $job,
            user: $user,
            profile: $profile,
            tailoredResumePath: self::RESUME_KEY,
            coverLetterPath: null,
            answers: $answers,
        );
    }

    private function adapter(string $class = GreenhouseApplyAdapter::class): ApplyAdapter
    {
        return $this->app->make($class);
    }

    /**
     * A run that stopped before the submit click on a page whose own text is a
     * bot challenge. The page text is read back *before* the form is touched,
     * so the evidence exists whatever the form wait then did.
     */
    public function test_a_captcha_page_aborts_to_needs_review_with_the_reason_and_screenshots(): void
    {
        $this->pageText = 'Verify you are human before continuing.';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'upload')),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        // Never retried: a retry meets the same challenge.
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('CAPTCHA or bot check', (string) $result->failureReason);
        $this->assertStringContainsString('manually', (string) $result->failureReason);

        // The abort reason and the audit trail both reach the result, which is
        // what the dashboard's call to action is built from (Req 9.5, 10.3).
        $this->assertNotEmpty($result->screenshotPaths);
        $this->assertSame($result->failureReason, $result->toArray()['failure_reason']);
        $this->assertSame($result->screenshotPaths, $result->toArray()['screenshot_paths']);

        $this->assertSame([
            'kind' => 'captcha',
            'marker' => 'verify you are human',
            'scope' => 'page_text',
            'bypass_attempted' => false,
        ], $result->metadata['blocked']);
    }

    /**
     * The case a pure step-log reading misses: a challenge page where the
     * script's broad selectors all matched *something*, so the worker reports a
     * clean run. The page title says what the page is, and that outranks a step
     * log full of green ticks.
     */
    public function test_a_wall_aborts_even_when_every_step_reported_success(): void
    {
        // Nothing in the body copy gives it away — only the page itself does.
        $this->pageText = 'One more step';
        $this->confirmationText = 'Reference 55';

        $this->fakeWorker(
            fn (array $steps) => $this->successBody($steps, [
                'title' => 'reCAPTCHA challenge',
                'html' => '<html><body>Checking your browser</body></html>',
                'finalUrl' => self::GREENHOUSE_URL,
            ]),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertStringContainsString('CAPTCHA or bot check', (string) $result->failureReason);
        $this->assertSame('title', $result->metadata['blocked']['scope']);
        // Not reported as a submission, because nothing was submitted.
        $this->assertArrayNotHasKey('confirmed_by', $result->metadata);
    }

    /**
     * The other direction, and the reason body copy is treated as weak
     * evidence: a form that mentions its own bot protection is still a form.
     */
    public function test_a_bot_protection_notice_on_a_clean_run_is_not_a_wall(): void
    {
        $this->pageText = 'This site is protected by reCAPTCHA. Verify you are human if prompted.';

        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_APPLIED, $result->status);
        $this->assertArrayNotHasKey('blocked', $result->metadata);
    }

    /** A redirect away from the form is the strongest wall signal there is. */
    public function test_a_redirect_to_a_sign_in_page_is_an_auth_wall(): void
    {
        $this->pageText = 'Senior Engineer, London';
        $this->landedUrl = 'https://boards.greenhouse.io/login?next=/acme/jobs/4242';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'upload'), [
                'title' => 'Senior Engineer',
                'finalUrl' => $this->landedUrl,
            ]),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertStringContainsString('sign-in or account-creation wall', (string) $result->failureReason);
        $this->assertSame('auth_wall', $result->metadata['blocked']['kind']);
        $this->assertSame('final_url', $result->metadata['blocked']['scope']);
    }

    /** A 403 against an application form is a bot wall in all but name. */
    public function test_a_forbidden_status_is_treated_as_bot_detection(): void
    {
        $this->pageText = 'Senior Engineer, London';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'upload'), [
                'title' => 'Senior Engineer',
                'status' => 403,
            ]),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertSame('http_status', $result->metadata['blocked']['scope']);
        $this->assertSame('403', $result->metadata['blocked']['marker']);
    }

    /**
     * One source of truth: the scraper's generalized Access-Denied list
     * (`enrichment.blocked_title_markers`) is the same judgement about the same
     * kind of page, so apply reads it rather than keeping a fourth copy.
     */
    public function test_the_enrichment_access_denied_markers_are_reused(): void
    {
        $this->pageText = 'Senior Engineer, London';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'upload'), [
                'title' => 'Access Denied',
            ]),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertSame('access denied', $result->metadata['blocked']['marker']);

        // And it really is that list doing the work, not a duplicate.
        config(['enrichment.blocked_title_markers' => []]);

        $this->assertSame(ApplyResult::STATUS_FAILED, $this->adapter()->apply($this->context())->status);
    }

    /** Greenhouse's own account gate, from the markers added to its config block. */
    public function test_greenhouses_account_gate_is_detected(): void
    {
        $this->pageText = 'Sign in to your My Greenhouse account to autofill and submit.';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'upload')),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame('auth_wall', $result->metadata['blocked']['kind']);
        $this->assertSame(
            'sign in to your my greenhouse account',
            $result->metadata['blocked']['marker']
        );
    }

    /**
     * Shared markers mean all three adapters are covered by the same list, so
     * none of them can quietly lack wall detection.
     *
     * @dataProvider adapters
     */
    public function test_every_adapter_aborts_on_a_shared_wall_marker(
        string $adapter,
        string $postingUrl,
        string $landedUrl,
    ): void {
        $this->postingUrl = $postingUrl;
        $this->landedUrl = $landedUrl;
        $this->pageText = 'Please sign in to apply for this role.';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'upload')),
            self::WORKER
        );

        $result = $this->adapter($adapter)->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertFalse($result->isRetryable());
        $this->assertSame('auth_wall', $result->metadata['blocked']['kind']);
        $this->assertFalse($result->metadata['blocked']['bypass_attempted']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function adapters(): array
    {
        return [
            'greenhouse' => [GreenhouseApplyAdapter::class, self::GREENHOUSE_URL, self::GREENHOUSE_URL],
            'lever' => [
                LeverApplyAdapter::class,
                'https://jobs.lever.co/acme/9f2c',
                'https://jobs.lever.co/acme/9f2c/apply',
            ],
            'workday' => [
                WorkdayApplyAdapter::class,
                'https://acme.wd5.myworkdayjobs.com/en-US/acme/job/Engineer_R-1',
                'https://acme.wd5.myworkdayjobs.com/en-US/acme/job/Engineer_R-1',
            ],
        ];
    }

    /**
     * Selector rot is not a wall. Nothing on the page says otherwise, so the
     * run stays retryable — the abort path must not swallow ordinary breakage.
     */
    public function test_a_plain_selector_failure_is_still_retryable(): void
    {
        $this->pageText = 'Senior Engineer, London. Apply now.';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'upload')),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_FAILED, $result->status);
        $this->assertTrue($result->isRetryable());
        $this->assertArrayNotHasKey('blocked', $result->metadata);
    }
}
