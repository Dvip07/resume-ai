<?php

namespace Tests\Unit\Services\Apply;

use App\Models\JobListing;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Apply\Adapters\GreenhouseApplyAdapter;
use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Greenhouse apply adapter (task 15.3, Requirement 9.2).
 *
 * No browser and no bucket: the worker is faked, S3 is faked, and the models are
 * built in memory, so what is under test is the adapter's two jobs — the script
 * it compiles and the judgement it makes about the run that comes back.
 */
class GreenhouseApplyAdapterTest extends TestCase
{
    private const APPLY_URL = 'https://boards.greenhouse.io/acme/jobs/4242';

    private const WORKER = 'http://127.0.0.1:8081/apply';

    private const RESUME_KEY = 'users/7/tailored-documents/11/resume.pdf';

    private const COVER_LETTER_KEY = 'users/7/tailored-documents/12/cover-letter.pdf';

    /** What a `readText` on the page body reads back, i.e. what the page says. */
    private string $pageText = 'Apply for Senior Engineer at Acme';

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

    private function adapter(): GreenhouseApplyAdapter
    {
        return $this->app->make(GreenhouseApplyAdapter::class);
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
    private function context(bool $withCoverLetter = false, array $answers = self::ANSWERS): ApplyContext
    {
        $user = new User(['name' => 'Ada Mary Lovelace', 'email' => 'ada@example.test']);
        $user->forceFill(['id' => 7]);

        $profile = new UserProfile([
            'user_id' => 7,
            'location' => ['city' => 'London', 'country' => 'UK'],
            'linkedin_url' => 'https://linkedin.com/in/ada',
            'portfolio_url' => 'https://ada.dev',
        ]);
        $profile->forceFill(['id' => 9]);

        $job = new JobListing;
        $job->forceFill(['id' => 31, 'application_url' => self::APPLY_URL]);

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
     * Fake the worker by reading the script it was actually posted, so the step
     * indices in the response match the script the adapter built rather than a
     * hand-counted guess that drifts the moment a field is added.
     *
     * @param  callable(array<int, array<string, mixed>>): array<string, mixed>  $respond
     */
    private function fakeWorker(callable $respond): void
    {
        Http::fake([
            self::WORKER => function ($request) use ($respond) {
                return Http::response($respond($request->data()['steps']));
            },
        ]);
    }

    /**
     * Every step `ok`, a screenshot for each screenshot step, and confirmation
     * text for the readText step.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function successBody(array $steps, array $overrides = []): array
    {
        return array_merge([
            'finalUrl' => 'https://boards.greenhouse.io/acme/jobs/4242/confirmation',
            'status' => 200,
            'title' => 'Application confirmed',
            'html' => '<html><body>Thank you for applying</body></html>',
            'elapsedMs' => 8120,
            'steps' => $this->stepOutcomes($steps),
            'screenshots' => $this->screenshotsFor($steps),
            'failedStep' => null,
            'navigationFailed' => false,
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function stepOutcomes(array $steps, ?int $failAt = null, string $error = 'Timeout 30000ms exceeded'): array
    {
        $outcomes = [];

        foreach ($steps as $index => $step) {
            $status = match (true) {
                $failAt === null => 'ok',
                $index < $failAt => 'ok',
                $index === $failAt => 'failed',
                default => 'skipped',
            };

            $outcome = [
                'index' => $index,
                'kind' => $step['kind'],
                'status' => $status,
                'selector' => $step['selector'] ?? null,
                'name' => $step['name'] ?? null,
            ];

            if ($status === 'failed') {
                $outcome['error'] = $error;
            }

            if ($step['kind'] === 'readText' && $status === 'ok') {
                // The confirmation banner and the page body say very different
                // things, and the body read happens *before* the form is
                // touched — conflating the two would let a pre-submit read
                // masquerade as proof the application landed.
                $outcome['text'] = str_ends_with((string) $step['name'], '_confirmation')
                    ? 'Thank you for applying to Acme.'
                    : $this->pageText;
            }

            $outcomes[] = $outcome;
        }

        return $outcomes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function screenshotsFor(array $steps, ?int $failAt = null): array
    {
        $shots = [];

        foreach ($steps as $index => $step) {
            if ($step['kind'] !== 'screenshot') {
                continue;
            }

            if ($failAt !== null && $index > $failAt) {
                continue;
            }

            $shots[] = [
                'name' => $step['name'],
                'format' => 'png',
                'base64' => base64_encode('PNG:'.$step['name']),
            ];
        }

        return $shots;
    }

    /** @return int index of the first step of `$kind` in the posted script */
    private function indexOf(array $steps, string $kind): int
    {
        foreach ($steps as $index => $step) {
            if ($step['kind'] === $kind) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * @dataProvider urls
     */
    public function test_it_claims_only_greenhouse_urls(string $url, bool $supported): void
    {
        $this->assertSame($supported, $this->adapter()->supports($url));
    }

    /** @return array<string, array{string, bool}> */
    public static function urls(): array
    {
        return [
            'hosted board' => ['https://boards.greenhouse.io/acme/jobs/1', true],
            'current hosted board' => ['https://job-boards.greenhouse.io/acme/jobs/1', true],
            'eu board' => ['https://boards.eu.greenhouse.io/acme/jobs/1', true],
            'www prefixed' => ['https://www.greenhouse.io/embed/job_app?token=1', true],
            'embedded by query param' => ['https://careers.acme.com/jobs?gh_jid=4242', true],
            'embedded by path' => ['https://careers.acme.com/embed/job_app?for=acme', true],
            'lever' => ['https://jobs.lever.co/acme/1', false],
            'arbitrary careers page' => ['https://careers.acme.com/jobs/senior-engineer', false],
            'greenhouse in a path segment only' => ['https://example.test/blog/greenhouse-io-review', false],
            'not a url' => ['boards.greenhouse.io/acme', false],
            'empty' => ['', false],
        ];
    }

    public function test_a_confirmed_submission_is_applied_and_fills_the_profile_fields(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps));

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_APPLIED, $result->status);
        $this->assertTrue($result->isApplied());
        $this->assertSame('https://boards.greenhouse.io/acme/jobs/4242/confirmation', $result->confirmationUrl);
        $this->assertSame('greenhouse', $result->metadata['adapter']);
        $this->assertSame(8120, $result->metadata['elapsed_ms']);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $steps = $body['steps'];
            $fills = [];

            foreach ($steps as $step) {
                if ($step['kind'] === 'fill') {
                    $fills[] = $step['value'];
                }
            }

            return $body['url'] === self::APPLY_URL
                && $request->hasHeader('X-Automation-Token', 'test-token')
                // Name split in two for Greenhouse's separate inputs.
                && in_array('Ada Mary', $fills, true)
                && in_array('Lovelace', $fills, true)
                && in_array('ada@example.test', $fills, true)
                && in_array('https://linkedin.com/in/ada', $fills, true)
                && in_array('https://ada.dev', $fills, true)
                // No phone on the profile, so the field is left alone entirely.
                && ! in_array('', $fills, true)
                // The page's own text is read back before the form is waited
                // for, so a wall is detected pre-emptively (Req 9.6).
                && $steps[0]['kind'] === 'readText'
                && $steps[0]['name'] === 'greenhouse_page'
                && $steps[1]['kind'] === 'waitFor'
                && $steps[count($steps) - 1]['kind'] === 'screenshot';
        });
    }

    public function test_the_resume_is_uploaded_from_a_signed_url_and_the_cover_letter_only_when_present(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps));

        $this->adapter()->apply($this->context(withCoverLetter: true));

        Http::assertSent(function ($request) {
            $uploads = array_values(array_filter(
                $request->data()['steps'],
                fn (array $step): bool => $step['kind'] === 'upload'
            ));

            return count($uploads) === 2
                && $uploads[0]['filename'] === 'resume.pdf'
                && str_contains($uploads[0]['url'], 'resume.pdf')
                && $uploads[1]['filename'] === 'cover-letter.pdf'
                && str_contains($uploads[1]['url'], 'cover-letter.pdf');
        });

    }

    public function test_only_the_resume_is_uploaded_when_no_cover_letter_was_rendered(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps));

        $this->adapter()->apply($this->context());

        Http::assertSent(function ($request) {
            $uploads = array_filter(
                $request->data()['steps'],
                fn (array $step): bool => $step['kind'] === 'upload'
            );

            return count($uploads) === 1;
        });
    }

    public function test_screenshots_are_persisted_under_the_users_prefix_as_the_audit_trail(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps));

        $result = $this->adapter()->apply($this->context());

        $this->assertCount(3, $result->screenshotPaths);

        foreach ($result->screenshotPaths as $path) {
            $this->assertStringStartsWith('users/7/applications/31/apply/greenhouse/', $path);
            Storage::disk('s3')->assertExists($path);
        }

        $this->assertStringContainsString('form_loaded', $result->screenshotPaths[0]);
        $this->assertStringContainsString('after_submit', $result->screenshotPaths[2]);
    }

    public function test_answerable_screening_questions_are_filled_from_answers_and_the_profile(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps));

        $this->adapter()->apply($this->context());

        Http::assertSent(function ($request) {
            $selects = array_filter(
                $request->data()['steps'],
                fn (array $step): bool => $step['kind'] === 'select' && in_array($step['value'], ['Yes', 'No'], true)
            );

            $locations = array_filter(
                $request->data()['steps'],
                // Resolved from the profile, not from an answer.
                fn (array $step): bool => $step['kind'] === 'fill' && $step['value'] === 'London, UK'
            );

            return count($selects) === 2 && count($locations) === 1;
        });
    }

    /**
     * Requirement 9.4. The one question left unanswered here is configured
     * optional, so a blank is a valid answer and the submission goes ahead —
     * with the label still on the record.
     */
    public function test_an_unanswered_optional_question_does_not_block_the_submission(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps));

        $result = $this->adapter()->apply($this->context());

        $this->assertTrue($result->isApplied());
        $this->assertSame([], $result->unansweredQuestions);
        $this->assertFalse($result->metadata['paused_before_submit']);
        $this->assertContains('How did you hear about this job?', $result->metadata['skipped_questions']);
    }

    /**
     * Requirement 9.4. A required question nothing can answer stops the run
     * *before* the submit click, names the field, and is not retryable — a
     * retry would hit the same gap.
     */
    public function test_an_unanswered_required_question_pauses_before_submitting_and_names_the_field(): void
    {
        $this->fakeWorker(fn (array $steps) => $this->successBody($steps));

        $result = $this->adapter()->apply($this->context(answers: ['work_authorization' => 'Yes']));

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertFalse($result->isRetryable());
        $this->assertSame(
            ['Will you now or in the future require visa sponsorship?'],
            $result->unansweredQuestions
        );
        // The same labels, verbatim, where ApplicationPresenter reads them.
        $this->assertSame(
            $result->unansweredQuestions,
            $result->toArray()['unanswered_questions']
        );
        $this->assertStringContainsString('visa sponsorship', (string) $result->failureReason);
        $this->assertStringContainsString('not submitted', (string) $result->failureReason);
        $this->assertTrue($result->metadata['paused_before_submit']);

        // Nothing was sent to the employer: the script has no submit click and
        // no confirmation wait, and it still screenshots what was staged.
        Http::assertSent(function ($request) {
            $steps = $request->data()['steps'];
            $kinds = array_column($steps, 'kind');
            $shots = array_column(array_values(array_filter(
                $steps,
                fn (array $step): bool => $step['kind'] === 'screenshot'
            )), 'name');

            $reads = array_column(array_values(array_filter(
                $steps,
                fn (array $step): bool => $step['kind'] === 'readText'
            )), 'name');

            return ! in_array('click', $kinds, true)
                // The only read is the pre-form page text used for wall
                // detection; there is no confirmation read, because there is
                // nothing to confirm.
                && $reads === ['greenhouse_page']
                && $shots === ['form_loaded', 'before_submit'];
        });

        $this->assertNotEmpty($result->screenshotPaths);
    }

    public function test_a_failure_before_the_submit_click_is_retryable(): void
    {
        $this->fakeWorker(function (array $steps) {
            $failAt = $this->indexOf($steps, 'upload');

            return $this->successBody($steps, [
                'steps' => $this->stepOutcomes($steps, $failAt, 'Timeout 30000ms exceeded'),
                'screenshots' => $this->screenshotsFor($steps, $failAt),
                'failedStep' => $this->stepOutcomes($steps, $failAt)[$failAt],
                'title' => 'Apply to Acme',
                'html' => '<html><body>Apply to Acme</body></html>',
                'finalUrl' => self::APPLY_URL,
            ]);
        });

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_FAILED, $result->status);
        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('upload step', (string) $result->failureReason);
        $this->assertNotEmpty($result->screenshotPaths);
    }

    public function test_an_unconfirmed_submission_needs_review_rather_than_a_retry(): void
    {
        $this->fakeWorker(function (array $steps) {
            // The click landed; the confirmation wait timed out.
            $failAt = $this->lastWaitForIndex($steps);

            return $this->successBody($steps, [
                'steps' => $this->stepOutcomes($steps, $failAt),
                'screenshots' => $this->screenshotsFor($steps, $failAt),
                'failedStep' => $this->stepOutcomes($steps, $failAt)[$failAt],
                'title' => 'Apply to Acme',
                'html' => '<html><body>Apply to Acme</body></html>',
                'finalUrl' => self::APPLY_URL,
            ]);
        });

        $result = $this->adapter()->apply($this->context());

        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertFalse($result->isRetryable());
        $this->assertTrue($result->needsHumanReview());
        $this->assertStringContainsString('no confirmation', (string) $result->failureReason);
    }

    public function test_page_evidence_counts_as_confirmation_when_the_element_never_appears(): void
    {
        $this->fakeWorker(function (array $steps) {
            $failAt = $this->lastWaitForIndex($steps);

            return $this->successBody($steps, [
                'steps' => $this->stepOutcomes($steps, $failAt),
                'screenshots' => $this->screenshotsFor($steps, $failAt),
                'failedStep' => $this->stepOutcomes($steps, $failAt)[$failAt],
                'title' => 'Thanks for applying',
                'html' => '<html><body>Thanks for applying to Acme.</body></html>',
                'finalUrl' => 'https://boards.greenhouse.io/acme/jobs/4242/application_confirmation',
            ]);
        });

        $result = $this->adapter()->apply($this->context());

        $this->assertTrue($result->isApplied());
        $this->assertSame('page_evidence', $result->metadata['confirmed_by']);
    }

    public function test_an_unreachable_worker_is_a_retryable_failure(): void
    {
        Http::fake([self::WORKER => Http::response(['error' => 'busy'], 503)]);

        $result = $this->adapter()->apply($this->context());

        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('browser slots', (string) $result->failureReason);
    }

    public function test_a_disabled_worker_fails_without_touching_the_network(): void
    {
        config(['services.automation_worker.enabled' => false]);

        $result = $this->adapter()->apply($this->context());

        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('disabled', (string) $result->failureReason);

        Http::assertNothingSent();
    }

    public function test_a_posting_without_an_application_url_is_left_to_a_human(): void
    {
        $context = $this->context();
        $context->job->forceFill(['application_url' => null]);

        $result = $this->adapter()->apply($context);

        $this->assertTrue($result->needsHumanReview());
        $this->assertStringContainsString('manually', (string) $result->failureReason);

        Http::assertNothingSent();
    }

    public function test_a_non_greenhouse_url_is_declined_without_a_run(): void
    {
        $context = $this->context();
        $context->job->forceFill(['application_url' => 'https://jobs.lever.co/acme/1']);

        $result = $this->adapter()->apply($context);

        $this->assertTrue($result->needsHumanReview());
        $this->assertStringContainsString('not a Greenhouse board', (string) $result->failureReason);

        Http::assertNothingSent();
    }

    /** The confirmation wait is the last `waitFor` in the script. */
    private function lastWaitForIndex(array $steps): int
    {
        $last = -1;

        foreach ($steps as $index => $step) {
            if ($step['kind'] === 'waitFor') {
                $last = $index;
            }
        }

        return $last;
    }
}
