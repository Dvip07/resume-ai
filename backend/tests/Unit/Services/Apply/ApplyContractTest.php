<?php

namespace Tests\Unit\Services\Apply;

use App\Models\JobListing;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Apply\ApplyAdapter;
use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyResult;
use App\Services\Apply\ApplyStepScript;
use Tests\TestCase;

/**
 * The `ApplyAdapter` contract and its two DTOs (design.md §7, Requirements 9.1
 * and 9.2). No database and no HTTP: these types are deliberately inert so the
 * concrete adapters in 15.3-15.4 can be tested against fixtures.
 */
class ApplyContractTest extends TestCase
{
    private function context(?string $coverLetter = null, array $answers = []): ApplyContext
    {
        $job = new JobListing;
        $job->application_url = 'https://boards.greenhouse.io/acme/jobs/1';

        return new ApplyContext(
            job: $job,
            user: new User,
            profile: new UserProfile,
            tailoredResumePath: 'tailored/1/resume.pdf',
            coverLetterPath: $coverLetter,
            answers: $answers,
        );
    }

    public function test_context_exposes_the_application_url_and_document_state(): void
    {
        $context = $this->context();

        $this->assertSame('https://boards.greenhouse.io/acme/jobs/1', $context->applicationUrl());
        $this->assertFalse($context->hasCoverLetter());

        $this->assertTrue($this->context('tailored/1/cover.pdf')->hasCoverLetter());
    }

    public function test_context_answers_lookup_ignores_blank_values(): void
    {
        $context = $this->context(answers: [
            'Are you authorized to work?' => 'Yes',
            'Salary expectation' => '',
        ]);

        $this->assertSame('Yes', $context->answer('Are you authorized to work?'));
        $this->assertNull($context->answer('Salary expectation'));
        $this->assertNull($context->answer('Never asked'));
    }

    public function test_result_statuses_carry_the_right_payload(): void
    {
        $applied = ApplyResult::applied(['audit/1/review.png'], 'https://boards.greenhouse.io/confirmed');
        $this->assertSame(ApplyResult::STATUS_APPLIED, $applied->status);
        $this->assertTrue($applied->isApplied());
        $this->assertNull($applied->failureReason);
        $this->assertFalse($applied->isRetryable());

        $failed = ApplyResult::failed('Submit button never appeared.', ['audit/1/error.png']);
        $this->assertSame(ApplyResult::STATUS_FAILED, $failed->status);
        $this->assertTrue($failed->isRetryable());
        $this->assertFalse($failed->needsHumanReview());
        $this->assertSame(['audit/1/error.png'], $failed->screenshotPaths);

        // Req 9.6: a bot wall is never retried, it is handed to a human.
        $review = ApplyResult::needsReview('CAPTCHA detected.', ['Why do you want this role?']);
        $this->assertSame(ApplyResult::STATUS_NEEDS_REVIEW, $review->status);
        $this->assertTrue($review->needsHumanReview());
        $this->assertFalse($review->isRetryable());
        $this->assertSame(['Why do you want this role?'], $review->unansweredQuestions);
    }

    public function test_result_screenshot_paths_can_be_appended_after_persistence(): void
    {
        $result = ApplyResult::failed('Timed out.')->withScreenshotPaths(['audit/1/a.png', 'audit/1/b.png']);

        $this->assertSame(['audit/1/a.png', 'audit/1/b.png'], $result->screenshotPaths);
        $this->assertSame('Timed out.', $result->failureReason);
        $this->assertSame(ApplyResult::STATUS_FAILED, $result->status);
    }

    public function test_step_script_skips_empty_values_and_emits_worker_step_kinds(): void
    {
        $steps = ApplyStepScript::make()
            ->fill('#first_name', 'Ada')
            ->fill('#phone', null)          // profile has no phone: leave it alone
            ->select('#country', 'CA')
            ->select('#skills', [])
            ->check('#terms')
            ->upload('#resume', 'https://s3.example.test/signed.pdf', 'resume.pdf')
            ->waitForSelector('#review')
            ->readText('.error', 'validation')
            ->screenshot('review page')
            ->click('#submit', 15000)
            ->goto('https://jobs.example.com/apply/2')
            ->toArray();

        $this->assertSame([
            ['kind' => 'fill', 'selector' => '#first_name', 'value' => 'Ada'],
            ['kind' => 'select', 'selector' => '#country', 'value' => 'CA'],
            ['kind' => 'check', 'selector' => '#terms', 'checked' => true],
            ['kind' => 'upload', 'selector' => '#resume', 'url' => 'https://s3.example.test/signed.pdf', 'filename' => 'resume.pdf'],
            ['kind' => 'waitFor', 'selector' => '#review', 'state' => 'visible'],
            ['kind' => 'readText', 'selector' => '.error', 'name' => 'validation'],
            ['kind' => 'screenshot', 'name' => 'review page', 'fullPage' => true],
            ['kind' => 'click', 'selector' => '#submit', 'timeoutMs' => 15000],
            ['kind' => 'goto', 'url' => 'https://jobs.example.com/apply/2'],
        ], $steps);
    }

    public function test_an_adapter_can_satisfy_the_interface_without_touching_a_browser(): void
    {
        $adapter = new class implements ApplyAdapter
        {
            public function supports(string $applicationUrl): bool
            {
                return str_contains($applicationUrl, 'boards.greenhouse.io');
            }

            public function apply(ApplyContext $context): ApplyResult
            {
                return ApplyResult::needsReview('Not implemented until task 15.3.');
            }
        };

        $this->assertTrue($adapter->supports('https://boards.greenhouse.io/acme/jobs/1'));
        $this->assertFalse($adapter->supports('https://acme.com/careers/1'));
        $this->assertTrue($adapter->apply($this->context())->needsHumanReview());
    }
}
