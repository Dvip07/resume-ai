<?php

namespace App\Services\Apply\Adapters;

use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyStepScript;

/**
 * Drives a Greenhouse job-board application (Requirement 9.2, task 15.3).
 *
 * The first concrete adapter, and the shape the Lever and Workday adapters
 * follow: compile one step script, hand it to the worker, let
 * {@see AbstractApplyAdapter} judge the run. Everything shared — URL matching,
 * signed document URLs, screening-answer resolution, confirmation evidence, and
 * the `failed` vs `needs_review` rule around the submit click — lives in the
 * base class. What is left here is the Greenhouse form itself.
 *
 * ## What it fills
 *
 * Name (split across Greenhouse's two inputs), email, phone, resume upload,
 * cover-letter upload when one was rendered, the optional LinkedIn/website URL
 * fields, and the standard screening questions configured under
 * `apply.adapters.greenhouse.questions`.
 *
 * A field with no value is skipped, not filled blank, and an unresolvable
 * screening question is never guessed: a required one pauses the run before the
 * submit click and names itself in the `needs_review` result, an optional one is
 * reported under `skipped_questions` (Req 9.4, handled in the base class).
 */
class GreenhouseApplyAdapter extends AbstractApplyAdapter
{
    /** Config key under `apply.adapters`, and the label used in metadata/keys. */
    public const KEY = 'greenhouse';

    protected function key(): string
    {
        return self::KEY;
    }

    protected function platformName(): string
    {
        return 'Greenhouse';
    }

    protected function platformLabel(): string
    {
        return 'Greenhouse board';
    }

    protected function compile(ApplyContext $context, ApplyDocuments $documents): ApplyPlan
    {
        $skipped = [];
        $unanswered = [];
        $script = ApplyStepScript::make();

        // Before anything else: the page's own text, so a CAPTCHA or sign-in
        // wall is detected from what the page said rather than guessed at from
        // a selector timeout (Req 9.6, base class `blockedDetection()`).
        $this->readPageText($script, $this->timeout('form_ms'));

        $script->waitForSelector($this->selector('form'), 'visible', $this->timeout('form_ms'))
            ->screenshot('form_loaded');

        [$firstName, $lastName] = $this->nameParts($context);

        $script->fill($this->selector('first_name'), $firstName)
            ->fill($this->selector('last_name'), $lastName)
            ->fill($this->selector('email'), $this->email($context))
            ->fill($this->selector('phone'), $this->phone($context));

        $script->upload(
            $this->selector('resume'),
            $documents->resumeUrl,
            $documents->resumeFilename,
            $this->timeout('upload_ms')
        );

        if ($documents->hasCoverLetter()) {
            $script->upload(
                $this->selector('cover_letter'),
                (string) $documents->coverLetterUrl,
                $documents->coverLetterFilename,
                $this->timeout('upload_ms')
            );
        }

        $script->fill($this->selector('linkedin'), $this->trimmed($context->profile->linkedin_url))
            ->fill($this->selector('website'), $this->trimmed(
                $context->profile->portfolio_url ?: $context->profile->github_url
            ));

        $this->addScreeningQuestions($script, $context, $skipped, $unanswered);

        // The shared tail: the pre-submit screenshot, then the submit click and
        // confirmation wait — or nothing at all when a required question has no
        // answer. See AbstractApplyAdapter for both rules.
        return $this->finishPlan($script, $skipped, $unanswered, [
            'cover_letter_attached' => $documents->hasCoverLetter(),
        ]);
    }
}
