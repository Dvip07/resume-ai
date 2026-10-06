<?php

namespace App\Services\Apply\Adapters;

use App\Models\UserAutomationSetting;
use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyResult;
use App\Services\Apply\ApplyRunResult;
use App\Services\Apply\ApplyStepScript;
use App\Services\Apply\OptInGatedApplyAdapter;

/**
 * LinkedIn Easy Apply, and the opt-in that has to be true before a single step
 * of it runs (Requirement 9.3, task 15.9).
 *
 * ## The gate is the feature
 *
 * Every other adapter is admitted by a URL match. This one is not, and the
 * reason is worth stating plainly rather than leaving in a config comment:
 * Easy Apply runs against the candidate's *own signed-in LinkedIn account*.
 * Automating it is, on a plain reading, against LinkedIn's User Agreement, and
 * the thing at risk when a run looks scripted is not a failed submission — it
 * is the account the user's professional network lives in, which this platform
 * cannot give back. That is a decision only the user can make, so it is made by
 * the user, once, explicitly, and recorded on
 * `user_automation_settings.linkedin_auto_apply_opt_in`.
 *
 * The gate is therefore enforced in three places, deliberately redundant:
 *
 * 1. **Registration.** The class is absent from `apply.registry` and lives in
 *    `apply.gated_registry` instead, which {@see \App\Services\Apply\ApplyAdapterRegistry}
 *    only consults for a user whose opt-in is on. A domain match cannot reach
 *    it, and a missing user id counts as no consent rather than as "skip the
 *    check".
 * 2. **Dispatch.** {@see \App\Jobs\SubmitApplication} asks the registry whether
 *    a gated adapter *would* have matched, and turns that into `needs_review`
 *    with an "apply manually, or enable it in settings" note — without calling
 *    the worker.
 * 3. **Here.** {@see apply()} re-reads the flag before compiling anything. One
 *    database read on a path that is about to open a browser is a cheap price
 *    for the guarantee holding even if a future caller resolves this adapter
 *    some other way.
 *
 * The per-day ceiling is the strictest of the four
 * (`apply.adapters.linkedin.daily_cap`, default 5) for the same reason, and the
 * user's own LinkedIn cap (`linkedin_daily_apply_cap`) applies on top; the
 * lower wins. {@see \App\Services\Apply\ApplyDailyLimiter} already recognises
 * the `linkedin` key, so registering the adapter is what switches that on.
 *
 * ## What the flow can and cannot do
 *
 * Easy Apply is a modal, not a page: click "Easy Apply", and LinkedIn presents
 * one to four panels (Contact info → Resume → optional Additional Questions →
 * Review) behind "Next" buttons, ending at "Submit application". The step
 * script is a straight line compiled before the browser opens and cannot branch
 * on how many panels this posting happens to have, so the number of "Next"
 * clicks comes from config (`modal_pages`, default 2 — Contact info and
 * Resume, which is the common two-panel shape).
 *
 * When a posting has fewer panels than that, the extra "Next" click finds the
 * Review panel's submit button instead and fails *before* the compiled submit
 * click, which stays retryable and files nothing. When it has more — a posting
 * with employer questions — the submit click lands on a "Next" button, the
 * confirmation never arrives, and the base class's submit-boundary rule takes
 * over: `needs_review`, never a retry, because a half-walked modal is exactly
 * where a duplicate or a junk application comes from.
 *
 * Both are hand-offs rather than guesses, and that is the right trade here. The
 * alternative — clicking whatever looks like a forward button until something
 * confirms — is how an automation submits an application it never read.
 *
 * ## Walls are the expected outcome, not the exception
 *
 * An Easy Apply modal only exists for a signed-in session. A worker without one
 * gets the public posting page and LinkedIn's "Sign in to continue" or
 * authwall redirect, which {@see AbstractApplyAdapter::blockedDetection()}
 * reports as a wall: `needs_review`, screenshots attached, nothing submitted,
 * and no attempt to sign in as the user or clear a challenge. On this adapter
 * more than any other, that is the common path.
 */
class LinkedInEasyApplyAdapter extends AbstractApplyAdapter implements OptInGatedApplyAdapter
{
    /** Config key under `apply.adapters`, and the label in metadata/keys. */
    public const KEY = 'linkedin';

    /** The `user_automation_settings` flag that admits this adapter at all. */
    public const OPT_IN = 'linkedin_auto_apply_opt_in';

    protected function key(): string
    {
        return self::KEY;
    }

    protected function platformName(): string
    {
        return 'LinkedIn';
    }

    protected function platformLabel(): string
    {
        return 'LinkedIn job posting';
    }

    public function optInSettingName(): string
    {
        return self::OPT_IN;
    }

    /**
     * Strictly true, strictly from the user's stored settings. A user with no
     * settings row gets the platform default, which is off
     * ({@see UserAutomationSetting::defaultsFor()}) — never having answered the
     * question is not consent.
     */
    public function isPermittedFor(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return UserAutomationSetting::forUser($userId)->linkedin_auto_apply_opt_in === true;
    }

    public function optInRequiredReason(): string
    {
        return 'This is a LinkedIn Easy Apply posting, and LinkedIn automation is switched off for your account, so '
            .'nothing was submitted. Apply manually using the posting link — your tailored documents are ready to '
            .'download and attach — or enable LinkedIn auto-apply in your automation settings. It uses your own '
            .'LinkedIn session, may breach LinkedIn\'s terms of service, and risks your account being restricted.';
    }

    /**
     * Host match *and* a job path. `linkedin.com` also covers profiles, company
     * pages and the feed, none of which have an application on them, so the
     * base class's host matching is narrowed by `job_path_markers` rather than
     * claiming the whole domain.
     */
    public function supports(string $applicationUrl): bool
    {
        if (! parent::supports($applicationUrl)) {
            return false;
        }

        $markers = $this->configList('job_path_markers');

        if ($markers === []) {
            return true;
        }

        $path = strtolower((string) (parse_url(trim($applicationUrl), PHP_URL_PATH) ?: ''));

        foreach ($markers as $marker) {
            if ($path !== '' && str_contains($path, strtolower($marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The last line of the gate. See the class docblock: the check is repeated
     * here so that resolving this adapter by any route still cannot drive it
     * for a user who has not opted in.
     */
    public function apply(ApplyContext $context): ApplyResult
    {
        $userId = (int) ($context->user->getKey() ?? 0);

        if (! $this->isPermittedFor($userId)) {
            // `needs_review`, not `failed`: there is nothing to retry. The
            // posting is fine and the automation is simply not allowed to touch
            // it until the user says so.
            return ApplyResult::needsReview($this->optInRequiredReason(), [], [], null, [
                'adapter' => self::KEY,
                'opt_in_setting' => self::OPT_IN,
                'opt_in' => false,
                'worker_called' => false,
            ]);
        }

        return parent::apply($context);
    }

    protected function compile(ApplyContext $context, ApplyDocuments $documents): ApplyPlan
    {
        $skipped = [];
        $unanswered = [];
        $script = ApplyStepScript::make();

        $script->waitForSelector($this->selector('posting'), 'visible', $this->timeout('page_ms'))
            ->screenshot('posting_loaded');

        // Before anything is touched: an unauthenticated session lands on the
        // authwall, and this read is what names that as a wall rather than
        // letting it surface as a missing Easy Apply button (Req 9.6).
        $this->readPageText($script, $this->timeout('page_ms'));

        // "Easy Apply" — the button that opens the modal. A posting that only
        // offers "Apply" (off-site) has no such button, so this step fails
        // early, before anything was submitted.
        $script->click($this->selector('easy_apply'), $this->timeout('modal_ms'))
            ->waitForSelector($this->selector('modal'), 'visible', $this->timeout('modal_ms'))
            ->screenshot('modal_opened');

        // Contact info. LinkedIn pre-fills these from the profile; filling them
        // again is harmless and covers the panel arriving blank. Empty values
        // are dropped by the script builder rather than typed as "".
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

        // Easy Apply has no cover-letter input of its own. Saying so in the
        // metadata is better than silently dropping a document the user paid a
        // tailoring run for.
        $coverLetterSelector = $this->selector('cover_letter');
        $attachedCoverLetter = $documents->hasCoverLetter() && $coverLetterSelector !== '';

        if ($attachedCoverLetter) {
            $script->upload(
                $coverLetterSelector,
                (string) $documents->coverLetterUrl,
                $documents->coverLetterFilename,
                $this->timeout('upload_ms')
            );
        }

        $this->addScreeningQuestions($script, $context, $skipped, $unanswered);

        // Walk the modal's "Next" buttons. Each click is a panel transition
        // inside the modal and submits nothing, so every one of them sits
        // before the submit boundary and stays retryable.
        $pages = $this->modalPages();

        for ($page = 1; $page <= $pages; $page++) {
            $script->click($this->selector('next'), $this->timeout('next_ms'))
                ->screenshot('modal_page_'.$page);
        }

        // The shared tail: pre-submit screenshot, then the submit click and the
        // confirmation wait — or nothing at all when a required question has no
        // answer (Req 9.4).
        return $this->finishPlan($script, $skipped, $unanswered, [
            'opt_in_setting' => self::OPT_IN,
            // Stated on every run, so the audit trail for an Easy Apply always
            // carries the consent that admitted it.
            'opt_in' => true,
            'flow' => 'easy_apply_modal',
            'modal_pages_walked' => $pages,
            'cover_letter_attached' => $attachedCoverLetter,
            'unattached_documents' => $documents->hasCoverLetter() && ! $attachedCoverLetter
                ? [(string) $documents->coverLetterFilename]
                : [],
        ]);
    }

    /**
     * How many "Next" clicks the modal is assumed to need. Clamped rather than
     * trusted: a negative value would be meaningless and a large one would
     * click blindly through panels nobody read.
     */
    protected function modalPages(): int
    {
        $configured = config('apply.adapters.'.self::KEY.'.modal_pages', 2);
        $pages = is_numeric($configured) ? (int) $configured : 2;

        return max(0, min(4, $pages));
    }

    /**
     * LinkedIn's wall has a name of its own — the "authwall" — and a posting
     * that has already been applied to shows "Applied" instead of the Easy
     * Apply button. Both are hand-offs; neither is retryable.
     */
    protected function blockedDetection(ApplyRunResult $run): ?array
    {
        $marker = $this->firstMarkerInText($run, $this->configList('already_applied_markers'));

        if ($marker !== null) {
            return [
                'kind' => 'already_applied',
                'marker' => $marker,
                'scope' => 'page_text',
                'conclusive' => true,
                'reason' => 'LinkedIn shows this posting as already applied to, so nothing further was submitted.',
            ];
        }

        return parent::blockedDetection($run);
    }
}
