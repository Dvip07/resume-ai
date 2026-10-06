<?php

namespace Tests\Unit\Services\Apply;

use App\Models\JobListing;
use App\Models\User;
use App\Models\UserAutomationSetting;
use App\Models\UserProfile;
use App\Services\Apply\Adapters\LinkedInEasyApplyAdapter;
use App\Services\Apply\ApplyAdapterRegistry;
use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyDailyLimiter;
use App\Services\Apply\ApplyResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LinkedIn Easy Apply (task 15.9, Requirement 9.3).
 *
 * The opt-in is the subject of this file. LinkedIn Easy Apply drives the user's
 * own signed-in account, so the thing worth proving is not that the modal flow
 * works but that *nothing at all* happens for a user who has not said yes —
 * at resolution time, so a domain match on its own can never reach the adapter.
 *
 * The database is real here (unlike the other adapter tests) because consent
 * lives in `user_automation_settings` and faking it would prove nothing.
 *
 * Validates: Requirements 9.3, 9.7
 */
class LinkedInEasyApplyAdapterTest extends TestCase
{
    use FakesApplyWorker, RefreshDatabase;

    private const APPLY_URL = 'https://www.linkedin.com/jobs/view/3912345678/';

    private const WORKER = 'http://127.0.0.1:8081/apply';

    private const RESUME_KEY = 'users/7/tailored-documents/11/resume.pdf';

    /** Answers for the questions config marks required, so a run can reach submit. */
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
    }

    private function applyUrl(): string
    {
        return self::APPLY_URL;
    }

    private function confirmedUrl(): string
    {
        return 'https://www.linkedin.com/jobs/view/3912345678/post-apply/';
    }

    private function adapter(): LinkedInEasyApplyAdapter
    {
        return $this->app->make(LinkedInEasyApplyAdapter::class);
    }

    /** Record the user's consent (or refusal) exactly the way the settings endpoint does. */
    private function optIn(bool $enabled): UserAutomationSetting
    {
        return UserAutomationSetting::updateOrCreate(
            ['user_id' => $this->user->id],
            ['linkedin_auto_apply_opt_in' => $enabled] + UserAutomationSetting::defaultsFor($this->user->id),
        );
    }

    /** @param array<string, string> $answers */
    private function context(array $answers = self::ANSWERS, ?string $url = null): ApplyContext
    {
        $profile = new UserProfile([
            'user_id' => $this->user->id,
            'location' => ['city' => 'London', 'country' => 'UK'],
            'linkedin_url' => 'https://linkedin.com/in/ada',
        ]);
        $profile->forceFill(['id' => 9]);

        $job = new JobListing;
        $job->forceFill(['id' => 31, 'application_url' => $url ?? self::APPLY_URL]);

        return new ApplyContext(
            job: $job,
            user: $this->user,
            profile: $profile,
            tailoredResumePath: self::RESUME_KEY,
            coverLetterPath: null,
            answers: $answers,
        );
    }

    /**
     * The core of the task: no opt-in, no run. Not a failed run, not a stubbed
     * run — no HTTP call at all.
     */
    public function test_without_the_opt_in_nothing_is_driven(): void
    {
        $this->optIn(false);

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('Apply manually', (string) $result->failureReason);
        $this->assertStringContainsString('automation settings', (string) $result->failureReason);
        $this->assertFalse($result->metadata['opt_in']);
        $this->assertSame('linkedin_auto_apply_opt_in', $result->metadata['opt_in_setting']);

        Http::assertNothingSent();
    }

    /** A user who has never opened the settings screen has not consented. */
    public function test_a_user_with_no_settings_row_has_not_opted_in(): void
    {
        $this->assertNull(UserAutomationSetting::query()->where('user_id', $this->user->id)->first());

        $result = $this->adapter()->apply($this->context());

        $this->assertTrue($result->needsHumanReview());
        Http::assertNothingSent();
    }

    /**
     * The gate is enforced at resolution, not just inside the adapter: the
     * registry does not hand LinkedIn out for a user who has not opted in, and
     * the adapter is absent from the ungated registry entirely, so a domain
     * match alone cannot reach it.
     */
    public function test_the_registry_never_resolves_linkedin_without_the_opt_in(): void
    {
        $registry = $this->app->make(ApplyAdapterRegistry::class);

        $this->assertArrayNotHasKey('linkedin', $registry->all());
        $this->assertArrayHasKey('linkedin', $registry->gated());

        // No user: no consent, and deliberately not "skip the check".
        $this->assertNull($registry->resolve(self::APPLY_URL));

        $this->optIn(false);
        $this->assertNull($registry->resolve(self::APPLY_URL, $this->user->id));

        // ...but the registry can still say *why*, so the posting is not
        // reported as an unsupported ATS.
        $blocked = $registry->gatedMatchFor(self::APPLY_URL, $this->user->id);
        $this->assertInstanceOf(LinkedInEasyApplyAdapter::class, $blocked);
        $this->assertSame('linkedin', $registry->keyFor($blocked));

        $this->optIn(true);
        $this->assertInstanceOf(
            LinkedInEasyApplyAdapter::class,
            $registry->resolve(self::APPLY_URL, $this->user->id)
        );
        $this->assertNull($registry->gatedMatchFor(self::APPLY_URL, $this->user->id));
    }

    public function test_with_the_opt_in_a_confirmed_easy_apply_is_applied(): void
    {
        $this->optIn(true);
        $this->confirmationText = 'Your application was sent to Acme';
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_APPLIED, $result->status);
        $this->assertSame('linkedin', $result->metadata['adapter']);
        $this->assertSame('easy_apply_modal', $result->metadata['flow']);
        $this->assertTrue($result->metadata['opt_in']);
        $this->assertSame(2, $result->metadata['modal_pages_walked']);

        Http::assertSent(function ($request) {
            $steps = $request->data()['steps'];
            $clicks = $this->valuesOf($steps, 'click', 'selector');

            return $request->data()['url'] === self::APPLY_URL
                // The page is read back before anything is touched, which is
                // what names the authwall as a wall rather than selector rot.
                && $this->indexOf($steps, 'readText') < $this->indexOf($steps, 'click')
                // Easy Apply, two "Next" panels, then Submit.
                && count($clicks) === 4
                && $this->valuesOf($steps, 'upload', 'filename') === ['resume.pdf']
                // Nothing is ever typed blank.
                && ! in_array('', $this->valuesOf($steps, 'fill'), true)
                && $this->valuesOf($steps, 'screenshot', 'name') === [
                    'posting_loaded', 'modal_opened', 'modal_page_1', 'modal_page_2', 'before_submit', 'after_submit',
                ];
        });
    }

    /** Requirement 9.7: five a day, the strictest of the four platforms. */
    public function test_the_platform_cap_is_five_a_day_and_is_enforced(): void
    {
        $this->optIn(true);
        $limiter = $this->app->make(ApplyDailyLimiter::class);

        $this->assertSame(5, $limiter->platformLimitFor('linkedin'));
        // The user's own LinkedIn cap is separate and also stricter than their
        // global one; the lower of the two wins.
        $this->assertSame(5, $limiter->globalLimitFor('linkedin', $this->user->id));

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->attempt('linkedin', $this->user->id), 'attempt '.($i + 1).' should be allowed');
        }

        $this->assertFalse($limiter->attempt('linkedin', $this->user->id));
        $this->assertSame(0, $limiter->remaining('linkedin', $this->user->id));
        $this->assertSame(5, $limiter->usedToday('linkedin', $this->user->id));
    }

    /**
     * @dataProvider urls
     */
    public function test_it_claims_linkedin_job_urls_only(string $url, bool $supported): void
    {
        $this->assertSame($supported, $this->adapter()->supports($url));
    }

    /** @return array<string, array{string, bool}> */
    public static function urls(): array
    {
        return [
            'job view' => [self::APPLY_URL, true],
            'job view without www' => ['https://linkedin.com/jobs/view/391/', true],
            'search with a selected posting' => ['https://www.linkedin.com/jobs/search/?currentJobId=391', true],
            'collections' => ['https://www.linkedin.com/jobs/collections/recommended/', true],
            // On the domain, but there is no application on any of these.
            'profile' => ['https://www.linkedin.com/in/ada/', false],
            'company page' => ['https://www.linkedin.com/company/acme/', false],
            'feed' => ['https://www.linkedin.com/feed/', false],
            'another ats' => ['https://boards.greenhouse.io/acme/jobs/1', false],
            'empty' => ['', false],
        ];
    }

    /**
     * The expected LinkedIn outcome for a worker with no session. Handed over,
     * never signed into on the user's behalf (Req 9.6).
     */
    public function test_the_authwall_is_handed_to_a_person_rather_than_signed_into(): void
    {
        $this->optIn(true);
        $this->pageText = 'Sign in to view this job. New to LinkedIn? Join now';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'click')),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('sign-in or account-creation wall', (string) $result->failureReason);
        $this->assertFalse($result->metadata['blocked']['bypass_attempted']);
        $this->assertNotEmpty($result->screenshotPaths);
    }

    public function test_an_already_applied_posting_is_not_submitted_again(): void
    {
        $this->optIn(true);
        $this->pageText = 'You have already applied to this job.';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'click')),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertTrue($result->needsHumanReview());
        $this->assertStringContainsString('already applied', (string) $result->failureReason);
    }

    /** Requirement 9.4, same rule as every other adapter. */
    public function test_an_unanswered_required_question_pauses_before_submitting(): void
    {
        $this->optIn(true);
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $result = $this->adapter()->apply($this->context(answers: ['visa_sponsorship' => 'No']));

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertSame(
            ['Are you legally authorized to work in the posting country?'],
            $result->unansweredQuestions
        );
        $this->assertTrue($result->metadata['paused_before_submit']);

        Http::assertSent(function ($request) {
            $steps = $request->data()['steps'];

            // Easy Apply plus the two "Next" clicks; the submit click is the
            // one that was never compiled.
            return count($this->valuesOf($steps, 'click', 'selector')) === 3
                && ! in_array('after_submit', $this->valuesOf($steps, 'screenshot', 'name'), true);
        });
    }

    /**
     * A modal with more panels than configured means the submit click lands on
     * a "Next" and no confirmation arrives. That is the one case where a retry
     * could file a second application, so it is never retried.
     */
    public function test_a_submission_that_never_confirms_needs_review_rather_than_a_retry(): void
    {
        $this->optIn(true);
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

    public function test_a_non_linkedin_url_is_declined_without_a_run(): void
    {
        $this->optIn(true);

        $result = $this->adapter()->apply($this->context(url: 'https://jobs.lever.co/acme/1'));

        $this->assertTrue($result->needsHumanReview());
        $this->assertStringContainsString('not a LinkedIn job posting', (string) $result->failureReason);

        Http::assertNothingSent();
    }
}
