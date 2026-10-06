<?php

namespace App\Services\Apply\Adapters;

use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyStepScript;

/**
 * Drives a Lever job-board application (Requirement 9.2, task 15.4).
 *
 * The easiest of the three ATSs to automate: one page, one POST, a redirect to a
 * "thanks" page. Three things differ from Greenhouse and are the whole reason
 * this class exists.
 *
 * **One name field.** Lever posts a single `name`, so the name is *not* split —
 * which avoids the surname guess Greenhouse forces on us.
 *
 * **The form lives at `/apply`.** A posting URL (`jobs.lever.co/acme/{id}`)
 * renders a description with an "Apply for this job" button; the form itself is
 * the `/apply` sibling. Rather than click through, {@see runUrl()} appends
 * `/apply` when it is missing and opens the form directly — one page load
 * instead of two, and no dependency on the button's markup.
 *
 * **No cover-letter upload.** Lever's standard application takes exactly one
 * file (the resume); a cover letter, where a company wants one, is a custom
 * field or the free-text "additional information" box, and neither can be
 * targeted reliably across boards. So a rendered cover letter is *not* attached,
 * and the result metadata says so under `unattached_documents` rather than
 * quietly dropping it. Uploading a PDF into a selector that may not exist would
 * fail the run before the submit click and earn a pointless retry.
 *
 * Everything else — URL matching, signed URLs, screening answers, confirmation
 * evidence, and the `failed`/`needs_review` boundary at the submit click — comes
 * from {@see AbstractApplyAdapter}.
 */
class LeverApplyAdapter extends AbstractApplyAdapter
{
    /** Config key under `apply.adapters`, and the label used in metadata/keys. */
    public const KEY = 'lever';

    protected function key(): string
    {
        return self::KEY;
    }

    protected function platformName(): string
    {
        return 'Lever';
    }

    protected function platformLabel(): string
    {
        return 'Lever posting';
    }

    /**
     * Open the application form rather than the job description. The query
     * string is preserved because Lever carries its source tracking there
     * (`?lever-origin=`, `?lever-source=`), and dropping it would silently
     * change how the application is attributed.
     */
    protected function runUrl(ApplyContext $context): string
    {
        $url = $context->applicationUrl();
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return $url;
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        if (str_ends_with(strtolower($path), '/apply')) {
            return $url;
        }

        $rebuilt = ($parts['scheme'] ?? 'https').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$path.'/apply';

        if (($query = (string) ($parts['query'] ?? '')) !== '') {
            $rebuilt .= '?'.$query;
        }

        return $rebuilt;
    }

    protected function compile(ApplyContext $context, ApplyDocuments $documents): ApplyPlan
    {
        $skipped = [];
        $unanswered = [];
        $script = ApplyStepScript::make();

        // The page's own text first, so a bot check or sign-in wall is detected
        // before the form is touched (Req 9.6, base class `blockedDetection()`).
        $this->readPageText($script, $this->timeout('form_ms'));

        $script->waitForSelector($this->selector('form'), 'visible', $this->timeout('form_ms'))
            ->screenshot('form_loaded');

        $script->fill($this->selector('name'), $this->fullName($context))
            ->fill($this->selector('email'), $this->email($context))
            ->fill($this->selector('phone'), $this->phone($context))
            ->fill($this->selector('company'), $this->currentCompany($context))
            ->fill($this->selector('location'), $this->locationText($context->profile));

        $script->upload(
            $this->selector('resume'),
            $documents->resumeUrl,
            $documents->resumeFilename,
            $this->timeout('upload_ms')
        );

        $script->fill($this->selector('linkedin'), $this->trimmed($context->profile->linkedin_url))
            ->fill($this->selector('github'), $this->trimmed($context->profile->github_url))
            ->fill($this->selector('portfolio'), $this->trimmed($context->profile->portfolio_url));

        $this->addScreeningQuestions($script, $context, $skipped, $unanswered);

        // The shared tail: pre-submit screenshot, then the submit click and
        // confirmation wait — or nothing at all when a required question has no
        // answer. See AbstractApplyAdapter for both rules.
        return $this->finishPlan($script, $skipped, $unanswered, [
            'cover_letter_attached' => false,
            // Named, not dropped: a human reviewing the attempt can see the
            // tailored letter exists and was not sendable through this form.
            'unattached_documents' => $documents->hasCoverLetter()
                ? [(string) $documents->coverLetterFilename]
                : [],
        ]);
    }

    /**
     * Lever's `org` field ("current company"). Only ever an explicit answer:
     * there is no current-employer column on the profile, and inferring one from
     * the most recent experience entry would be a guess about employment status.
     */
    private function currentCompany(ApplyContext $context): string
    {
        return $context->answer('current_company') ?? '';
    }
}
