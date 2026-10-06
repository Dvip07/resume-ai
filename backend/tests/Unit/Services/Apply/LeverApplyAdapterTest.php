<?php

namespace Tests\Unit\Services\Apply;

use App\Models\JobListing;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Apply\Adapters\LeverApplyAdapter;
use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Lever apply adapter (task 15.4, Requirement 9.2).
 *
 * No browser and no bucket: the worker is faked, S3 is faked, and the models are
 * built in memory, so what is under test is the adapter's two jobs — the script
 * it compiles and the judgement it makes about the run that comes back.
 */
class LeverApplyAdapterTest extends TestCase
{
    use FakesApplyWorker;

    private const POSTING_URL = 'https://jobs.lever.co/acme/9f2c-1234';

    private const FORM_URL = 'https://jobs.lever.co/acme/9f2c-1234/apply';

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
        return self::FORM_URL;
    }

    private function confirmedUrl(): string
    {
        return 'https://jobs.lever.co/acme/9f2c-1234/thanks';
    }

    private function adapter(): LeverApplyAdapter
    {
        return $this->app->make(LeverApplyAdapter::class);
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
            'github_url' => 'https://github.com/ada',
            'portfolio_url' => 'https://ada.dev',
        ]);
        $profile->forceFill(['id' => 9]);

        $job = new JobListing;
        $job->forceFill(['id' => 31, 'application_url' => $url ?? self::POSTING_URL]);

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
    public function test_it_claims_only_lever_candidate_boards(string $url, bool $supported): void
    {
        $this->assertSame($supported, $this->adapter()->supports($url));
    }

    /** @return array<string, array{string, bool}> */
    public static function urls(): array
    {
        return [
            'posting' => ['https://jobs.lever.co/acme/1234', true],
            'apply form' => ['https://jobs.lever.co/acme/1234/apply', true],
            'eu board' => ['https://jobs.eu.lever.co/acme/1234', true],
            // The employer side of Lever, which must never be driven.
            'employer app' => ['https://hire.lever.co/jobs/1234', false],
            'greenhouse' => ['https://boards.greenhouse.io/acme/jobs/1', false],
            'arbitrary careers page' => ['https://careers.acme.com/jobs/senior-engineer', false],
            'lever in a path segment only' => ['https://example.test/blog/lever-review', false],
            'not a url' => ['jobs.lever.co/acme/1', false],
            'empty' => ['', false],
        ];
    }

    public function test_it_opens_the_apply_form_rather_than_the_job_description(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $this->adapter()->apply($this->context());

        Http::assertSent(fn ($request) => $request->data()['url'] === self::FORM_URL);
    }

    public function test_an_apply_url_is_left_alone_and_source_tracking_is_preserved(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $this->adapter()->apply($this->context(url: self::POSTING_URL.'?lever-source=linkedin'));

        Http::assertSent(fn ($request) => $request->data()['url'] === self::FORM_URL.'?lever-source=linkedin');
    }

    public function test_a_confirmed_submission_is_applied_and_fills_one_name_field(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_APPLIED, $result->status);
        $this->assertSame($this->confirmedUrl(), $result->confirmationUrl);
        $this->assertSame('lever', $result->metadata['adapter']);

        Http::assertSent(function ($request) {
            $steps = $request->data()['steps'];
            $fills = $this->valuesOf($steps, 'fill');

            return $request->hasHeader('X-Automation-Token', 'test-token')
                // Lever takes one `name`, so the name is not split.
                && in_array('Ada Mary Lovelace', $fills, true)
                && ! in_array('Lovelace', $fills, true)
                && in_array('ada@example.test', $fills, true)
                && in_array('London, UK', $fills, true)
                && in_array('https://linkedin.com/in/ada', $fills, true)
                && in_array('https://github.com/ada', $fills, true)
                && in_array('https://ada.dev', $fills, true)
                // No phone on the profile, so the field is left alone entirely.
                && ! in_array('', $fills, true)
                // The page's own text is read back first, before the form is
                // waited for, so a wall is detected pre-emptively (Req 9.6).
                && $steps[0]['kind'] === 'readText'
                && $steps[0]['name'] === 'lever_page'
                && $steps[1]['kind'] === 'waitFor'
                && $steps[count($steps) - 1]['kind'] === 'screenshot';
        });
    }

    public function test_only_the_resume_is_uploaded_and_a_cover_letter_is_reported_as_unattachable(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $result = $this->adapter()->apply($this->context(withCoverLetter: true));

        // Lever's standard form takes exactly one file. The rendered letter is
        // named rather than silently dropped.
        $this->assertFalse($result->metadata['cover_letter_attached']);
        $this->assertSame(['cover-letter.pdf'], $result->metadata['unattached_documents']);

        Http::assertSent(function ($request) {
            $uploads = $this->valuesOf($request->data()['steps'], 'upload', 'filename');

            return $uploads === ['resume.pdf'];
        });
    }

    public function test_screenshots_are_persisted_under_the_users_prefix_as_the_audit_trail(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $result = $this->adapter()->apply($this->context());

        $this->assertCount(3, $result->screenshotPaths);

        foreach ($result->screenshotPaths as $path) {
            $this->assertStringStartsWith('users/7/applications/31/apply/lever/', $path);
            Storage::disk('s3')->assertExists($path);
        }
    }

    public function test_answerable_screening_questions_are_filled(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $this->adapter()->apply($this->context());

        Http::assertSent(fn ($request) => $this->valuesOf($request->data()['steps'], 'select') === ['Yes', 'No']);
    }

    /**
     * Requirement 9.4. Lever gets the pause from the shared base class, so the
     * check here is that it reaches this adapter too — no submit click compiled,
     * and the field named on the result.
     */
    public function test_an_unanswered_required_question_pauses_before_submitting(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps), self::WORKER);

        $result = $this->adapter()->apply($this->context(answers: ['work_authorization' => 'Yes']));

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertFalse($result->isRetryable());
        $this->assertSame(
            ['Will you now or in the future require visa sponsorship?'],
            $result->unansweredQuestions
        );
        $this->assertTrue($result->metadata['paused_before_submit']);

        Http::assertSent(function ($request) {
            $kinds = array_column($request->data()['steps'], 'kind');

            return ! in_array('click', $kinds, true);
        });
    }

    public function test_a_failure_before_the_submit_click_is_retryable(): void
    {
        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'upload')),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_FAILED, $result->status);
        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('upload step', (string) $result->failureReason);
        $this->assertNotEmpty($result->screenshotPaths);
    }

    public function test_an_unconfirmed_submission_needs_review_rather_than_a_retry(): void
    {
        // The click landed; the confirmation wait timed out.
        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->lastWaitForIndex($steps)),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('no confirmation', (string) $result->failureReason);
    }

    public function test_a_bot_check_is_handed_to_a_person_instead_of_retried(): void
    {
        $this->pageText = 'Please verify you are human before continuing.';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->indexOf($steps, 'fill')),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertTrue($result->needsHumanReview());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('CAPTCHA', (string) $result->failureReason);
    }

    public function test_the_thanks_page_counts_as_confirmation_when_the_element_never_appears(): void
    {
        $this->pageText = 'Acme';

        $this->fakeWorker(
            fn (array $steps) => $this->failedBody($steps, $this->lastWaitForIndex($steps), [
                'finalUrl' => $this->confirmedUrl(),
            ]),
            self::WORKER
        );

        $result = $this->adapter()->apply($this->context());

        $this->assertTrue($result->isApplied());
        $this->assertSame('page_evidence', $result->metadata['confirmed_by']);
    }

    public function test_a_non_lever_url_is_declined_without_a_run(): void
    {
        $result = $this->adapter()->apply($this->context(url: 'https://boards.greenhouse.io/acme/jobs/1'));

        $this->assertTrue($result->needsHumanReview());
        $this->assertStringContainsString('not a Lever posting', (string) $result->failureReason);

        Http::assertNothingSent();
    }

    public function test_a_disabled_worker_fails_without_touching_the_network(): void
    {
        config(['services.automation_worker.enabled' => false]);

        $result = $this->adapter()->apply($this->context());

        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('disabled', (string) $result->failureReason);

        Http::assertNothingSent();
    }
}
