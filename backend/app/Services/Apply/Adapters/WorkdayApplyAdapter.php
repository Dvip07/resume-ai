<?php

namespace App\Services\Apply\Adapters;

use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyRunResult;
use App\Services\Apply\ApplyStepScript;

/**
 * Drives the opening leg of a Workday application (Requirement 9.2, task 15.4).
 *
 * ## What this adapter honestly cannot do
 *
 * Workday is not a form, it is a wizard. A tenant typically walks a candidate
 * through "Apply" → account creation or sign-in → My Information → My
 * Experience → Application Questions → Voluntary Disclosures → Self Identify →
 * Review → Submit, with per-page "Save and Continue" buttons, and most tenants
 * require an account before any of it. Each page's content is tenant-configured,
 * so the pages cannot be enumerated ahead of time, and the step script this
 * platform speaks is a straight line compiled before the browser opens — it
 * cannot branch on what the next page turns out to be.
 *
 * So this adapter deliberately attempts only the one shape that is completable
 * in a single script: a tenant that lets an anonymous candidate apply, lands on
 * a My Information page, and offers Submit from it. It fills what that page
 * asks, uploads the resume, and submits.
 *
 * Everything else ends as `needs_review` rather than a pretend success:
 *
 * - **A sign-in or account-creation wall** — the common case. Detected from the
 *   page's own text via `auth_wall_markers`, and reported as "a person has to
 *   do this", because no number of retries gets past it (Req 9.6).
 * - **A deeper wizard** — a page shape where the configured Submit control is
 *   not present fails before the submit click, which the base class would
 *   normally call retryable; here the page text is checked first and a wall or
 *   bot check wins, so only genuine selector rot stays retryable.
 * - **A submitted-but-unconfirmed run** — same rule as every other adapter: at
 *   or after the submit click, never retry.
 *
 * The practical effect is that a lot of Workday postings route to manual apply.
 * That is the correct outcome: Workday is where an automated apply most easily
 * becomes a half-filled application or a duplicate, and a posting handed to the
 * user with screenshots is worth more than an optimistic one.
 */
class WorkdayApplyAdapter extends AbstractApplyAdapter
{
    /** Config key under `apply.adapters`, and the label used in metadata/keys. */
    public const KEY = 'workday';

    protected function key(): string
    {
        return self::KEY;
    }

    protected function platformName(): string
    {
        return 'Workday';
    }

    protected function platformLabel(): string
    {
        return 'Workday posting';
    }

    protected function compile(ApplyContext $context, ApplyDocuments $documents): ApplyPlan
    {
        $skipped = [];
        $unanswered = [];
        $script = ApplyStepScript::make();

        $script->waitForSelector($this->selector('posting'), 'visible', $this->timeout('page_ms'))
            ->screenshot('posting_loaded');

        // Read the page's own text back before touching anything. It is what
        // tells a sign-in wall apart from selector rot when a later step fails,
        // and `body` is the one selector that cannot have moved.
        $this->readPageText($script, $this->timeout('page_ms'));

        // "Apply" / "Apply Manually" — the gate into the wizard.
        $script->click($this->selector('apply'), $this->timeout('apply_ms'))
            ->waitForSelector($this->selector('form'), 'visible', $this->timeout('form_ms'))
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
            // Only some tenants expose a second attachment input. Configured
            // empty by default, in which case the step is skipped rather than
            // failing the run on a selector that may not exist.
            $coverLetterSelector = $this->selector('cover_letter');

            if ($coverLetterSelector !== '') {
                $script->upload(
                    $coverLetterSelector,
                    (string) $documents->coverLetterUrl,
                    $documents->coverLetterFilename,
                    $this->timeout('upload_ms')
                );
            }
        }

        $this->addScreeningQuestions($script, $context, $skipped, $unanswered);

        $attachedCoverLetter = $documents->hasCoverLetter() && $this->selector('cover_letter') !== '';

        // The shared tail: pre-submit screenshot, then the submit click and
        // confirmation wait — or nothing at all when a required question has no
        // answer. See AbstractApplyAdapter for both rules.
        return $this->finishPlan($script, $skipped, $unanswered, [
            'cover_letter_attached' => $attachedCoverLetter,
            'unattached_documents' => $documents->hasCoverLetter() && ! $attachedCoverLetter
                ? [(string) $documents->coverLetterFilename]
                : [],
            // Stated plainly in the metadata so nobody reads an `applied` here
            // as "the whole wizard was completed".
            'flow' => 'single_page_my_information',
        ]);
    }

    /**
     * Workday's walls come in one more flavour than the base class knows about:
     * a tenant that *only* offers "Apply with LinkedIn"/"Use My Last
     * Application", or one that has already recorded an application for this
     * candidate. Both are hand-offs, not retries.
     */
    protected function blockedDetection(ApplyRunResult $run): ?array
    {
        $marker = $this->firstMarkerInText($run, $this->configList('already_applied_markers'));

        if ($marker !== null) {
            return [
                'kind' => 'already_applied',
                'marker' => $marker,
                'scope' => 'page_text',
                // Not a marker that could turn up in a privacy footer: if the
                // page says this, it means it.
                'conclusive' => true,
                'reason' => 'Workday reports an application already exists for this posting, so nothing further was '
                    .'submitted.',
            ];
        }

        return parent::blockedDetection($run);
    }
}
