<?php

namespace Tests\Unit\Services\Apply;

use App\Models\JobListing;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Apply\Adapters\WorkdayApplyAdapter;
use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Workday apply adapter (task 15.4, Requirement 9.2).
 *
 * Workday is the ATS where getting the *refusals* right matters more than the
 * happy path: most tenants wall the wizard behind an account, and a confident
 * wrong answer there is either a half-filled application or a duplicate. So the
 * bulk of these tests are about what the adapter declines to do.
 */
class WorkdayApplyAdapterTest extends TestCase
{
    use FakesApplyWorker;

    private const APPLY_URL = 'https://acme.wd5.myworkdayjobs.com/en-US/acme_careers/job/London/Senior-Engineer_R-1234';

    private const WORKER = 'http://127.0.0.1:8081/apply';

    private const RESUME_KEY = 'users/7/tailored-documents/11/resume.pdf';

    private const COVER_LETTER_KEY = 'users/7/tailored-documents/12/cover-letter.pdf';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Storage::disk('s3')->put(self::RESUME_KEY, '%PDF-1.4 resume');
        Storage::disk('s3')->put(self::COVER_LETTER_KEY, '%PDF-1.4 cover letter');

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
        return self::APPLY_URL;
    }

    private function confirmedUrl(): string
    {
        return 'https://acme.wd5.myworkdayjobs.com/en-US/acme_careers/successfullySubmitted';
    }

    private function adapter(): WorkdayApplyAdapter
    {
        return $this->app->make(WorkdayApplyAdapter::class);
    }

    /** Answers for the questions config marks required, so a run can reach submit. */
    private const ANSWERS = [
        'work_authorization' => 'Yes',
        'visa_sponsorship' => 'No',
    ];

    /**
     * The required screening questions are answered by default, because an
     * unanswered required question is now a deliberate pause before the submit
     * click (Req 9.4) and every test that is about something else needs a run
     * that actually reaches the form's submit button. Passing `$answers`
     * replaces the defaults outright, which is how the pause is exercised.
     *
     * @param  array<string, string>  $answers
     */
    private function context(bool $withCoverLetter = false, array $answers = self::ANSWERS, ?string $url = null): ApplyContext
    {
        $user = new User(['name' => 'Ada Mary Lovelace', 'email' => 'ada@example.test']);
        $user->forceFill(['id' => 7]);

        $profile = new UserProfile([
            'user_id' => 7,
            'location' => ['city' => 'London', 'country' => 'UK'],
            'linkedin_url' => 'https://linkedin.com/in/ada',
        ]);
        $profile->forceFill(['id' => 9]);

        $job = new JobListing;
        $job->forceFill(['id' => 31, 'application_url' => $url ?? self::APPLY_URL]);

        return new ApplyContext(
            job: $job,
            user: $user,
            profile: $profile,
            tailoredResumePath: self::RESUME_KEY,
            coverLetterPath: $withCoverLetter ? self::COVER_LETTER_KEY : null,
            answers: $answers,
        );
    }

    /**
     * @dataProvider urls
     */
    public function test_it_claims_workday_tenant_urls_only(string $url, bool $supported): void
    {
        $this->assertSame($supported, $this->adapter()->supports($url));
    }

    /** @return array<string, array{string, bool}> */
    public static function urls(): array
    {
        return [
            'wd1 tenant' => ['https://acme.wd1.myworkdayjobs.com/en-US/acme/job/R-1', true],
            'wd5 tenant' => [self::APPLY_URL, true],
            // The shard number runs into the hundreds, so it is matched as a
            // parent domain rather than enumerated.
            'wd103 tenant' => ['https://acme.wd103.myworkdayjobs.com/en-US/acme/job/R-1', true],
            'myworkdaysite tenant' => ['https://acme.wd3.myworkdaysite.com/en-US/acme/job/R-1', true],
            'vanity host with a wd shard label' => ['https://careers.acme.wd5.example.test/job/R-1', true],
            'wday path on a company domain' => ['https://careers.acme.com/wday/authgwy/acme/login.htmld', true],
            'greenhouse' => ['https://boards.greenhouse.io/acme/jobs/1', false],
            'lever' => ['https://jobs.lever.co/acme/1', false],
            'arbitrary careers page' => ['https://careers.acme.com/jobs/senior-engineer', false],
            'not a url' => ['acme.wd5.myworkdayjobs.com/job/1', false],
            'empty' => ['', false],
        ];
    }

    public function test_a_confirmed_submission_is_applied_and_splits_the_name(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_APPLIED, $result->status);
        $this->assertSame('workday', $result->metadata['adapter']);
        // Stated plainly, so an `applied` here is not read as "the whole
        // multi-page wizard was completed".
        $this->assertSame('single_page_my_information', $result->metadata['flow']);

        Http::assertSent(function ($request) {
            $steps = $request->data()['steps'];
            $fills = $this->valuesOf($steps, 'fill');

            return $request->data()['url'] === self::APPLY_URL
                && in_array('Ada Mary', $fills, true)
                && in_array('Lovelace', $fills, true)
                && in_array('ada@example.test', $fills, true)
                && ! in_array('', $fills, true)
                // The posting page is read before anything is touched, which is
                // what later tells a wall apart from selector rot.
                && $this->indexOf($steps, 'readText') < $this->indexOf($steps, 'click')
                && $this->valuesOf($steps, 'upload', 'filename') === ['resume.pdf'];
        });
    }

    public function test_a_sign_in_wall_is_handed_to_a_person_instead_of_retried(): void
    {
        $this->pageText = 'Sign In to apply. Already have an account? Create Account';

        // The wall means the Apply button never leads anywhere fillable, so the
        // run stops long before the submit click.
        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'click')),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('sign-in or account-creation wall', (string) $result->failureReason);
        $this->assertNotEmpty($result->screenshotPaths);
    }

    public function test_an_existing_application_is_reported_rather_than_submitted_again(): void
    {
        $this->pageText = 'You have already applied to this job.';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'click')),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertTrue($result->needsHumanReview());
        $this->assertStringContainsString('already exists', (string) $result->failureReason);
    }

    public function test_plain_selector_rot_before_the_submit_click_stays_retryable(): void
    {
        // Nothing on the page says "wall", so this is a markup change, which a
        // retry (after a config fix) can survive.
        $this->pageText = 'Senior Engineer, London. Apply now.';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'upload')),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_FAILED, $result->status);
        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('upload step', (string) $result->failureReason);
    }

    public function test_an_unconfirmed_submission_needs_review_rather_than_a_retry(): void
    {
        $this->pageText = 'Review your application';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->lastWaitForIndex($steps)),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('no confirmation', (string) $result->failureReason);
    }

    public function test_a_cover_letter_is_only_uploaded_when_the_tenant_exposes_an_input(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $result = $this->adapter()->apply($this->context(withCoverLetter: true));

        $this->assertFalse($result->metadata['cover_letter_attached']);
        $this->assertSame(['cover-letter.pdf'], $result->metadata['unattached_documents']);

        Http::assertSent(fn ($request) => $this->valuesOf($request->data()['steps'], 'upload', 'filename') === ['resume.pdf']);

        config(['apply.adapters.workday.selectors.cover_letter' => '[data-automation-id="coverLetterUpload"]']);

        $result = $this->adapter()->apply($this->context(withCoverLetter: true));

        $this->assertTrue($result->metadata['cover_letter_attached']);
        $this->assertSame([], $result->metadata['unattached_documents']);
    }

    public function test_screenshots_are_persisted_under_the_users_prefix_as_the_audit_trail(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $result = $this->adapter()->apply($this->context());

        $this->assertCount(4, $result->screenshotPaths);

        foreach ($result->screenshotPaths as $path) {
            $this->assertStringStartsWith('users/7/applications/31/apply/workday/', $path);
            Storage::disk('s3')->assertExists($path);
        }

        $this->assertStringContainsString('posting_loaded', $result->screenshotPaths[0]);
        $this->assertStringContainsString('after_submit', $result->screenshotPaths[3]);
    }

    public function test_a_non_workday_url_is_declined_without_a_run(): void
    {
        $result = $this->adapter()->apply($this->context(url: 'https://jobs.lever.co/acme/1'));

        $this->assertTrue($result->needsHumanReview());
        $this->assertStringContainsString('not a Workday posting', (string) $result->failureReason);

        Http::assertNothingSent();
    }

    public function test_an_unreachable_worker_is_a_retryable_failure(): void
    {
        Http::fake([self::WORKER => Http::response(['error' => 'busy'], 503)]);

        $result = $this->adapter()->apply($this->context());

        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('browser slots', (string) $result->failureReason);
    }

    /**
     * Requirement 9.4. Workday asks three standard questions; the two required
     * ones have to be answered before anything is submitted.
     */
    public function test_an_unanswered_required_question_pauses_before_submitting(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $result = $this->adapter()->apply($this->context(answers: ['visa_sponsorship' => 'No']));

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertFalse($result->isRetryable());
        $this->assertSame(
            ['Are you legally authorized to work in the posting country?'],
            $result->unansweredQuestions
        );
        $this->assertTrue($result->metadata['paused_before_submit']);

        Http::assertSent(function ($request) {
            $steps = $request->data()['steps'];
            $shots = $this->valuesOf($steps, 'screenshot', 'name');

            // The "Apply" click is still made — it is what opens the form — but
            // there is no second click, which is the submit.
            return count($this->valuesOf($steps, 'click', 'selector')) === 1
                && $shots === ['posting_loaded', 'form_loaded', 'before_submit'];
        });
    }

    /**
     * A wall explains the run better than the question gap, and both are
     * hand-offs — but the labels still travel on the result so the dashboard can
     * list what will block this application next.
     */
    public function test_a_wall_still_reports_the_unanswered_questions_on_the_result(): void
    {
        $this->pageText = 'Sign In to apply. Create Account';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'click')),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context(answers: []));

        $this->assertTrue($result->needsHumanReview());
        $this->assertStringContainsString('sign-in or account-creation wall', (string) $result->failureReason);
        $this->assertCount(2, $result->unansweredQuestions);
    }
}
