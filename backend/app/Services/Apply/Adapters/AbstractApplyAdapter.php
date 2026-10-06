<?php

namespace App\Services\Apply\Adapters;

use App\Models\UserProfile;
use App\Services\Apply\ApplyAdapter;
use App\Services\Apply\ApplyArtifactStorage;
use App\Services\Apply\ApplyContext;
use App\Services\Apply\ApplyResult;
use App\Services\Apply\ApplyRunResult;
use App\Services\Apply\ApplyStepScript;
use App\Services\Apply\ApplyWorkerClient;
use App\Services\Storage\S3StorageService;
use Throwable;

/**
 * Everything the Greenhouse, Lever and Workday adapters do identically
 * (Requirement 9.2, tasks 15.3 and 15.4).
 *
 * The three ATSs differ in exactly one interesting way — the shape of the form —
 * and in nothing else. URL matching, signing the document URLs, splitting a
 * name, resolving a screening answer, deciding whether a run counts as
 * confirmed, and the `failed` vs `needs_review` call are the same reasoning
 * every time, and that reasoning is the part worth getting right once. So a
 * subclass supplies three things:
 *
 * - {@see key()}: which `apply.adapters.*` block holds its selectors;
 * - {@see platformName()}: what to call the ATS in a user-facing message;
 * - {@see compile()}: the step script, and the index of the submit click.
 *
 * ## Why a failure after the submit click is `needs_review`, never `failed`
 *
 * This is the one judgement that really matters, and it is why `compile()`
 * returns an {@see ApplyPlan} with a `submitIndex` rather than a bare script.
 * `failed` means "retry could work" — and a retry re-submits the form. If the
 * click landed and only the *confirmation* wait timed out, the application may
 * already be filed, so a retry risks a duplicate application under the
 * candidate's name. Every step from the submit click onwards therefore maps to
 * `needs_review` with the screenshots attached, which asks a human for thirty
 * seconds of attention instead. Steps before the click touched nothing
 * server-side and map to `failed` as normal.
 *
 * ## Selectors live in config, not here
 *
 * Selectors are the part of an adapter guaranteed to rot: an ATS ships a markup
 * change and a selector that worked yesterday times out today. Keeping them in
 * `config/apply.php` makes that a config/env fix rather than a deploy.
 *
 * Nothing is ever invented. A field with no value is skipped rather than filled
 * blank ({@see ApplyStepScript} drops empty values), and a screening question
 * nothing can answer is never guessed at.
 *
 * ## Unanswered questions pause the run (Requirement 9.4)
 *
 * An unanswerable question is split by whether the form requires it, which is
 * declared per question in config:
 *
 * - **Required** — {@see finishPlan()} compiles the script *without* the submit
 *   click, and {@see interpret()} returns `needs_review` carrying the exact
 *   field labels in {@see ApplyResult::$unansweredQuestions}. The form is still
 *   filled and screenshotted, so a human sees what was staged.
 * - **Optional** — blank is a valid answer, so the submission goes ahead and
 *   the label is reported under `skipped_questions`.
 *
 * Deciding this at compile time is not a shortcut, it is the only option: the
 * worker executes a straight-line script produced before the browser opens and
 * cannot branch on what it finds, so "don't submit" has to mean "don't compile
 * a submit".
 *
 * ## Why questions are enumerated in config rather than discovered on the page
 *
 * The same straight-line constraint rules out page discovery. A `readText` of
 * the form could certainly report required markers, but only *after* the script
 * has run to completion — by which point the submit click has already happened,
 * which is exactly what Req 9.4 says must not happen. Reading the form, ending
 * the run, and re-running to submit would double every apply's cost and still
 * race the page's own JS-rendered question set. And tenant-defined questions
 * (Lever `cards[...]`, Workday's Application Questions page) have neither
 * stable selectors nor stable labels to key answers off.
 *
 * ## Walls are handed over, never bypassed (Requirement 9.6)
 *
 * A CAPTCHA, a bot-detection interstitial or a sign-in/account-creation gate
 * ends the run as `needs_review` with the screenshots attached. No adapter
 * solves a challenge, calls a solver, replays a challenge cookie, spoofs a user
 * agent or signs in as the candidate — a wall means the employer asked for a
 * person, and `failed` would be wrong anyway because a retry hits the same
 * wall. The page text is read back *before* the form is touched
 * ({@see readPageText()}) so the evidence exists either way, and the check runs
 * on the returned page whether or not a step failed
 * ({@see blockedDetection()}).
 *
 * So the questions an adapter handles are the ones its ATS standardises, listed
 * in `apply.adapters.*.questions`. A tenant-specific question outside that list
 * is not detected; the form's own validation rejects the submission, the
 * confirmation never arrives, and the run lands in `needs_review` at the submit
 * boundary with screenshots attached — a hand-off, never a false `applied`.
 */
abstract class AbstractApplyAdapter implements ApplyAdapter
{
    public function __construct(
        protected readonly ApplyWorkerClient $worker,
        protected readonly S3StorageService $storage,
        protected readonly ApplyArtifactStorage $artifacts,
    ) {}

    /** Config key under `apply.adapters`, and the label used in metadata and storage keys. */
    abstract protected function key(): string;

    /** How the ATS is named in a user-facing message, e.g. "Greenhouse". */
    abstract protected function platformName(): string;

    /** The step script for one attempt, plus the submit boundary. */
    abstract protected function compile(ApplyContext $context, ApplyDocuments $documents): ApplyPlan;

    /**
     * Host and path matching only — no network calls, since this runs for every
     * registered adapter on every attempt.
     *
     * Hosted boards are matched by host (exactly, or as a parent domain), plus
     * any configured host pattern for ATSs that shard across numbered
     * subdomains. Company-hosted embedded boards are claimed only when the URL
     * itself gives them away. A board that looks like any other careers page is
     * left unclaimed and falls through to manual apply, which is the right
     * failure: a guessed form fill on an unknown ATS is worse than no attempt.
     */
    public function supports(string $applicationUrl): bool
    {
        $url = trim($applicationUrl);

        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return false;
        }

        $host = strtolower(ltrim((string) $parts['host'], '.'));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        foreach ($this->configList('hosts') as $candidate) {
            $candidate = strtolower(trim($candidate, '. '));

            if ($candidate !== '' && ($host === $candidate || str_ends_with($host, '.'.$candidate))) {
                return true;
            }
        }

        foreach ($this->configList('host_patterns') as $pattern) {
            if (@preg_match($pattern, $host) === 1) {
                return true;
            }
        }

        $path = strtolower((string) ($parts['path'] ?? ''));

        foreach ($this->configList('embedded_path_markers') as $marker) {
            if ($marker !== '' && str_contains($path, strtolower($marker))) {
                return true;
            }
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        foreach ($this->configList('embedded_query_params') as $param) {
            if ($param !== '' && array_key_exists($param, $query)) {
                return true;
            }
        }

        return false;
    }

    public function apply(ApplyContext $context): ApplyResult
    {
        $url = $context->applicationUrl();

        if ($url === '') {
            return ApplyResult::needsReview(
                'This posting carries no application URL, so it has to be applied to manually.'
            );
        }

        if (! $this->supports($url)) {
            return ApplyResult::needsReview(
                'The application URL is not a '.$this->platformLabel().', so this adapter will not drive it.'
            );
        }

        if (! $this->worker->isConfigured()) {
            // Retryable on purpose: nothing about the posting is wrong, the
            // worker is simply not available right now.
            return ApplyResult::failed('The automation worker is disabled or unconfigured.');
        }

        try {
            $documents = $this->documents($context);
        } catch (Throwable $e) {
            return ApplyResult::failed('Could not prepare the tailored documents for upload: '.$e->getMessage());
        }

        $plan = $this->compile($context, $documents);

        $run = $this->worker->run($this->runUrl($context), $plan->script);

        return $this->interpret($run, $context, $plan);
    }

    /**
     * The URL the worker opens. Defaults to the posting's own apply link; an
     * adapter overrides when the form lives at a derivable sibling URL.
     */
    protected function runUrl(ApplyContext $context): string
    {
        return $context->applicationUrl();
    }

    /** How the ATS is named when declining a URL, e.g. "Greenhouse board". */
    protected function platformLabel(): string
    {
        return $this->platformName().' posting';
    }

    /**
     * Turn the mechanical {@see ApplyRunResult} into the policy decision the
     * pipeline acts on. See the class docblock for the submit-boundary rule.
     */
    protected function interpret(ApplyRunResult $run, ApplyContext $context, ApplyPlan $plan): ApplyResult
    {
        $screenshots = $this->artifacts->storeScreenshots($context, $this->key(), $run->screenshots);
        $metadata = $this->metadata($run, $plan);

        if (! $run->ok) {
            return ApplyResult::failed(
                (string) ($run->failureReason ?? 'The automation worker did not complete the application.'),
                $screenshots,
                null,
                $metadata
            );
        }

        if ($run->navigationFailed) {
            return ApplyResult::failed(
                'The '.$this->platformName().' application page could not be opened.',
                $screenshots,
                $run->finalUrl,
                $metadata
            );
        }

        $confirmed = $this->looksConfirmed($run);

        // Asked regardless of whether a step failed (Req 9.6). A wall is a
        // property of the page the browser ended up on, not of the step log: a
        // bot-check interstitial can satisfy every broad selector in the script
        // and report a clean run, and that is exactly the case that must not
        // come back `applied`. The one thing that outranks a wall is actual
        // evidence the application landed — a page that says "thanks for
        // applying" is not a wall, whatever else it mentions.
        $blocked = $confirmed ? null : $this->blockedDetection($run);

        if ($blocked !== null
            && $run->allStepsSucceeded()
            && $blocked['scope'] === 'page_text'
            && ($blocked['conclusive'] ?? false) !== true) {
            // Weak evidence against a run where nothing went wrong. Body copy
            // is where a form's own privacy footer mentions bot checks, so on a
            // script that completed end to end only page-level evidence (the
            // title, the final URL, the HTTP status) is allowed to overturn it
            // — or a `conclusive` phrase, like Workday stating outright that an
            // application already exists.
            $blocked = null;
        }

        if ($blocked !== null) {
            $metadata = $metadata + ['blocked' => [
                'kind' => $blocked['kind'],
                'marker' => $blocked['marker'],
                'scope' => $blocked['scope'],
                // Stated in the audit trail, not just implied by the status:
                // no adapter ever attempts to clear one of these.
                'bypass_attempted' => false,
            ]];
        }

        if ($plan->pausedForQuestions()) {
            // The script never contained a submit click, so whatever happened to
            // the individual steps, nothing was filed. Hand it over naming the
            // fields, rather than retrying into the same gap (Req 9.4).
            //
            // If the run also ran into a wall, the wall is the better
            // explanation of what a person is about to see — but the question
            // labels still travel on the result, because they are the next
            // thing that blocks this application either way.
            return ApplyResult::needsReview(
                $blocked['reason'] ?? $this->unansweredQuestionsReason($plan->unansweredRequired),
                $plan->unansweredRequired,
                $screenshots,
                $run->finalUrl,
                $metadata
            );
        }

        if ($run->allStepsSucceeded() || $confirmed) {
            if ($blocked !== null) {
                // Every step "succeeded" and the page is still a wall. Nothing
                // was filed, so this is a hand-off, not a success.
                return ApplyResult::needsReview($blocked['reason'], [], $screenshots, $run->finalUrl, $metadata);
            }

            return ApplyResult::applied(
                $screenshots,
                $run->finalUrl,
                $metadata + ['confirmed_by' => $confirmed ? 'page_evidence' : 'confirmation_element']
            );
        }

        $failed = $run->failedStep;
        $where = $failed !== null
            ? sprintf('%s step on "%s"', $failed->kind, (string) ($failed->selector ?? 'unknown selector'))
            : 'an unidentified step';
        $why = $failed?->error !== null ? ': '.$failed->error : '.';

        if ($failed !== null && $failed->index >= $plan->submitIndex) {
            // The form was submitted (or may have been) and the page never
            // confirmed it. Retrying would risk a duplicate application.
            return ApplyResult::needsReview(
                'The application was submitted but no confirmation could be detected, so it needs to be verified '
                .'manually before any retry. Stopped at the '.$where.$why,
                [],
                $screenshots,
                $run->finalUrl,
                $metadata
            );
        }

        if ($blocked !== null) {
            // A wall, not a bug. Retrying hits the same wall, so hand it over
            // rather than burning attempts (Req 9.6).
            return ApplyResult::needsReview($blocked['reason'], [], $screenshots, $run->finalUrl, $metadata);
        }

        return ApplyResult::failed(
            'The '.$this->platformName().' form could not be completed; it failed at the '.$where.$why,
            $screenshots,
            $run->finalUrl,
            $metadata
        );
    }

    /** The user-facing reason the run hit a wall, or null. */
    protected function blockedReason(ApplyRunResult $run): ?string
    {
        return $this->blockedDetection($run)['reason'] ?? null;
    }

    /**
     * Did the run end up against something a retry cannot get past — a bot
     * check, or a sign-in/account-creation wall (Requirement 9.6)?
     *
     * ## No bypass, ever
     *
     * This method detects and reports. It does not — and no adapter, and not
     * the automation worker, ever will — solve a CAPTCHA, call a solver
     * service, replay a challenge cookie, spoof a user agent, or sign in as the
     * candidate to get past a login wall. A wall means the employer has asked
     * for a person, and the only honest answer is to give them one: the run
     * ends as `needs_review` with its screenshots so the user can finish the
     * application by hand. That is also the only safe answer, since every
     * bypass technique available is either a terms-of-service breach for the
     * candidate or an impersonation of them.
     *
     * For the same reason a wall is never `failed`: `failed` means "a retry
     * could work", and retrying hits the identical wall while burning the
     * user's quota.
     *
     * ## Where the markers come from
     *
     * One source of truth, in `config/apply.php`: the shared `apply.blocked`
     * lists, plus anything the adapter's own block adds, plus — for the title
     * scope — `enrichment.blocked_title_markers`, the same generalized "Access
     * Denied" list the scraper judges a blocked page by
     * ({@see \App\Services\Enrichment\JobEnrichmentService::blockedMarkerIn()}).
     * Apply and enrichment are asking one question about one kind of page, so
     * they read one list rather than drifting apart.
     *
     * @return array{kind: string, marker: string, scope: string, reason: string, conclusive?: bool}|null
     */
    protected function blockedDetection(ApplyRunResult $run): ?array
    {
        if (($hit = $this->wallHit($run, 'captcha')) !== null) {
            return $hit + ['reason' => 'The '.$this->platformName().' page presented a CAPTCHA or bot check, which only '
                .'a person can clear, so nothing was submitted and this application has to be completed manually.'];
        }

        if (($hit = $this->wallHit($run, 'auth_wall')) !== null) {
            return $hit + ['reason' => 'The '.$this->platformName().' application is behind a sign-in or '
                .'account-creation wall, so nothing was submitted and it has to be completed manually.'];
        }

        return null;
    }

    /**
     * The first marker of one wall kind that the run's own evidence matches,
     * cheapest and most specific scope first.
     *
     * Page text means text the worker *read back* (`readText` on `body`, and
     * the confirmation element), never the raw HTML: boards routinely ship a
     * bot-check script and a "protected by reCAPTCHA" footer while remaining
     * perfectly applicable, and matching those would abort every run.
     *
     * @return array{kind: string, marker: string, scope: string}|null
     */
    protected function wallHit(ApplyRunResult $run, string $kind): ?array
    {
        if (($marker = $this->firstMarkerInText($run, $this->wallMarkers($kind, 'markers'))) !== null) {
            return ['kind' => $kind, 'marker' => $marker, 'scope' => 'page_text'];
        }

        $title = strtolower($run->title);

        foreach ($this->wallMarkers($kind, 'title_markers') as $marker) {
            if ($title !== '' && str_contains($title, strtolower($marker))) {
                return ['kind' => $kind, 'marker' => $marker, 'scope' => 'title'];
            }
        }

        $finalUrl = strtolower($run->finalUrl);

        foreach ($this->wallMarkers($kind, 'url_markers') as $marker) {
            if ($finalUrl !== '' && str_contains($finalUrl, strtolower($marker))) {
                return ['kind' => $kind, 'marker' => $marker, 'scope' => 'final_url'];
            }
        }

        foreach ($this->wallStatuses($kind) as $status) {
            if ($run->status === $status) {
                return ['kind' => $kind, 'marker' => (string) $status, 'scope' => 'http_status'];
            }
        }

        return null;
    }

    /**
     * The markers for one wall kind and scope: the shared `apply.blocked` list,
     * the adapter's own additions, and — for the title scope of a bot check —
     * the enrichment block list, which is where the original Access-Denied
     * detection already lives.
     *
     * @return list<string>
     */
    protected function wallMarkers(string $kind, string $scope): array
    {
        $shared = $this->stringList(config('apply.blocked.'.$kind.'_'.$scope, []));
        $own = $this->configList($kind.'_'.$scope);

        $inherited = $kind === 'captcha' && $scope === 'title_markers'
            ? $this->stringList(config('enrichment.blocked_title_markers', []))
            : [];

        return array_values(array_unique(array_merge($shared, $own, $inherited)));
    }

    /** @return list<int> */
    protected function wallStatuses(string $kind): array
    {
        $statuses = array_merge(
            $this->stringList(config('apply.blocked.'.$kind.'_statuses', [])),
            $this->configList($kind.'_statuses'),
        );

        return array_values(array_unique(array_map('intval', $statuses)));
    }

    /**
     * The first marker mentioned by text the worker read back from the page.
     *
     * @param  list<string>  $markers
     */
    protected function firstMarkerInText(ApplyRunResult $run, array $markers): ?string
    {
        $haystacks = [
            strtolower((string) $run->text($this->pageTextName())),
            strtolower((string) $run->text($this->confirmationTextName())),
        ];

        foreach ($markers as $marker) {
            $needle = strtolower($marker);

            if ($needle === '') {
                continue;
            }

            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && str_contains($haystack, $needle)) {
                    return $marker;
                }
            }
        }

        return null;
    }

    /**
     * Second opinion on whether the application landed, used when the
     * confirmation selector never appeared. Checks the confirmation text, the
     * page title, the final URL and the page body against the configured
     * markers — a board that redirects to its own "thanks for applying" page
     * without the element we waited for should not be retried into a duplicate.
     */
    protected function looksConfirmed(ApplyRunResult $run): bool
    {
        if ($this->pageMentions($run, $this->configList('confirmation_markers'))) {
            return true;
        }

        $finalUrl = strtolower($run->finalUrl);

        foreach ($this->configList('confirmation_url_markers') as $marker) {
            if ($marker !== '' && $finalUrl !== '' && str_contains($finalUrl, strtolower($marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case-insensitive search for any marker in what the run ended up looking
     * like: the text read back from the confirmation element, the page title,
     * and the body HTML.
     *
     * The wider page text is deliberately *not* searched here. It is read back
     * before the form is touched ({@see readPageText()}), so letting it answer
     * "does this page say the application landed?" would let a pre-submit read
     * masquerade as proof of submission. Wall detection reads it instead, where
     * pre-submit is exactly the point ({@see firstMarkerInText()}).
     *
     * @param  list<string>  $markers
     */
    protected function pageMentions(ApplyRunResult $run, array $markers): bool
    {
        $haystacks = [
            strtolower((string) $run->text($this->confirmationTextName())),
            strtolower($run->title),
            strtolower($run->html),
        ];

        foreach ($markers as $marker) {
            $marker = strtolower($marker);

            if ($marker === '') {
                continue;
            }

            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && str_contains($haystack, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** `readText` name for whatever the confirmation element says. */
    protected function confirmationTextName(): string
    {
        return $this->key().'_confirmation';
    }

    /** `readText` name for any wider page text an adapter chose to capture. */
    protected function pageTextName(): string
    {
        return $this->key().'_page';
    }

    /**
     * Read the page's own text back before anything is typed, clicked or
     * uploaded (Requirement 9.6).
     *
     * This is the cheap, early half of wall detection, and it belongs first in
     * every script. The worker runs a straight line and cannot branch, so an
     * adapter cannot ask "is this a CAPTCHA page?" and stop; what it can do is
     * capture the answer before it touches the form, so the evidence exists
     * whether the run then fails on a missing selector or sails through one
     * that matched nothing meaningful. `body` is the only selector that cannot
     * have moved, which is why it is the configured default.
     */
    protected function readPageText(ApplyStepScript $script, ?int $timeoutMs = null): ApplyStepScript
    {
        $selector = $this->selector('page_text');

        if ($selector !== '') {
            $script->readText($selector, $this->pageTextName(), $timeoutMs);
        }

        return $script;
    }

    /**
     * Fill in the standard screening questions this adapter can answer, and
     * split the ones it cannot into "blank is fine" and "a person has to answer
     * this" (Requirement 9.4).
     *
     * Requiredness comes from the question's `required` flag in config, and a
     * question with no flag counts as required — unknown requiredness is not a
     * licence to submit.
     *
     * @param  list<string>  $skipped  Optional questions left blank; the submission still goes ahead.
     * @param  list<string>  $unansweredRequired  Required questions with no answer; these pause the run.
     */
    protected function addScreeningQuestions(
        ApplyStepScript $script,
        ApplyContext $context,
        array &$skipped,
        array &$unansweredRequired,
    ): void {
        $questions = config('apply.adapters.'.$this->key().'.questions', []);

        if (! is_array($questions)) {
            return;
        }

        foreach ($questions as $key => $question) {
            if (! is_array($question)) {
                continue;
            }

            $selector = $this->trimmed($question['selector'] ?? null);
            $label = $this->trimmed($question['label'] ?? null) ?: (string) $key;

            if ($selector === '') {
                continue;
            }

            $answer = $this->resolveAnswer($context, (string) $key, $question);

            if ($answer === null) {
                // Never guessed, and never filled blank. A required question
                // stops the run; an optional one is only reported.
                if ($this->isRequiredQuestion($question)) {
                    $unansweredRequired[] = $label;
                } else {
                    $skipped[] = $label;
                }

                continue;
            }

            match ($this->trimmed($question['kind'] ?? null) ?: 'fill') {
                'select' => $script->select($selector, $answer),
                'check' => $script->check($selector, $this->isAffirmative($answer)),
                default => $script->fill($selector, $answer),
            };
        }
    }

    /**
     * A question is required unless config says otherwise. Anything truthy —
     * `true`, `1`, `"yes"` — counts as required, so an env-driven override
     * behaves the way the rest of the config does.
     *
     * @param  array<string, mixed>  $question
     */
    protected function isRequiredQuestion(array $question): bool
    {
        if (! array_key_exists('required', $question) || $question['required'] === null) {
            return true;
        }

        $value = $question['required'];

        return is_bool($value) ? $value : $this->isAffirmative($this->trimmed($value));
    }

    /**
     * Close off a compiled script: the pre-submit screenshot, then either the
     * submit tail or nothing at all (Requirement 9.4).
     *
     * This is the shared half of every adapter's `compile()`, and it is shared
     * precisely because the pause decision must not be re-made per ATS. The
     * step script the worker speaks is a straight line compiled before the
     * browser opens and cannot branch mid-run, so "do not submit" can only mean
     * one thing: do not compile the submit click. When a required question has
     * no answer the script therefore ends at `before_submit` — the form is still
     * filled and screenshotted, so the audit trail (Req 9.5) shows a human
     * exactly what was staged, and nothing was sent to the employer.
     *
     * `submitIndex` is then set past the end of the script, which keeps the
     * base-class rule intact: no step can be at or after the submit boundary,
     * because there is no submit.
     *
     * @param  list<string>  $skipped
     * @param  list<string>  $unansweredRequired
     * @param  array<string, mixed>  $notes
     */
    protected function finishPlan(
        ApplyStepScript $script,
        array $skipped,
        array $unansweredRequired,
        array $notes = [],
    ): ApplyPlan {
        $script->screenshot('before_submit');

        if ($unansweredRequired !== []) {
            return new ApplyPlan($script, $script->count(), $skipped, $unansweredRequired, $notes);
        }

        $submitIndex = $script->count();

        $script->click($this->selector('submit'), $this->timeout('submit_ms'))
            ->waitForSelector($this->selector('confirmation'), 'visible', $this->timeout('confirmation_ms'))
            ->readText($this->selector('confirmation'), $this->confirmationTextName())
            ->screenshot('after_submit');

        return new ApplyPlan($script, $submitIndex, $skipped, $unansweredRequired, $notes);
    }

    /**
     * The user-facing reason for a paused run, naming the fields verbatim so the
     * dashboard's call to action can list them (see
     * {@see \App\Support\ApplicationPresenter}, which reads the same labels from
     * `automation_log.unanswered_questions`).
     *
     * @param  list<string>  $questions
     */
    protected function unansweredQuestionsReason(array $questions): string
    {
        $labels = implode('", "', $questions);

        return 'The '.$this->platformName().' application asks '
            .(count($questions) === 1 ? 'a required question' : count($questions).' required questions')
            .' that nothing on file answers, so the form was filled but not submitted: "'.$labels.'". '
            .(count($questions) === 1 ? 'Answer it' : 'Answer them').' and the application can be submitted.';
    }

    /**
     * An explicit context answer wins, then the named profile attribute, then
     * the configured default. Null means "nobody has answered this".
     *
     * @param  array<string, mixed>  $question
     */
    protected function resolveAnswer(ApplyContext $context, string $key, array $question): ?string
    {
        $answerKey = $this->trimmed($question['answer_key'] ?? null) ?: $key;

        if (($answer = $context->answer($answerKey)) !== null) {
            return $answer;
        }

        $profileSource = $this->trimmed($question['profile'] ?? null);

        if ($profileSource !== '') {
            $fromProfile = $profileSource === 'location'
                ? $this->locationText($context->profile)
                : $this->trimmed($context->profile->{$profileSource} ?? null);

            if ($fromProfile !== '') {
                return $fromProfile;
            }
        }

        $default = $this->trimmed($question['default'] ?? null);

        return $default !== '' ? $default : null;
    }

    /**
     * Split the stored single-string name in two for forms that ask separately.
     * The last whitespace-separated token is taken as the surname, which is
     * right for the overwhelming majority of Latin-script names and wrong in a
     * recoverable way for the rest — hence `first_name`/`last_name` being
     * overridable through the context's answers.
     *
     * @return array{0: string, 1: string}
     */
    protected function nameParts(ApplyContext $context): array
    {
        $first = $context->answer('first_name');
        $last = $context->answer('last_name');

        if ($first !== null || $last !== null) {
            return [(string) $first, (string) $last];
        }

        $name = $this->fullName($context);

        if ($name === '') {
            return ['', ''];
        }

        $tokens = preg_split('/\s+/', $name) ?: [$name];

        if (count($tokens) === 1) {
            return [$tokens[0], ''];
        }

        $surname = (string) array_pop($tokens);

        return [implode(' ', $tokens), $surname];
    }

    /** The name as one string, for forms with a single name field. */
    protected function fullName(ApplyContext $context): string
    {
        if (($answer = $context->answer('full_name')) !== null) {
            return $answer;
        }

        $parts = array_filter([$context->answer('first_name'), $context->answer('last_name')]);

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return $this->trimmed($context->user->name ?? null);
    }

    protected function email(ApplyContext $context): string
    {
        return $context->answer('email')
            ?? $this->trimmed($context->user->email ?? null);
    }

    /**
     * There is no phone column on `user_profiles`, so a number only exists if
     * something upstream resolved one onto the context. Absent, the field is
     * left alone rather than filled blank — every one of these forms treats
     * phone as optional.
     */
    protected function phone(ApplyContext $context): string
    {
        return $context->answer('phone')
            ?? $this->trimmed($context->profile->phone ?? null);
    }

    /** Same shape the LaTeX tailoring services use, so documents and forms agree. */
    protected function locationText(UserProfile $profile): string
    {
        $location = $profile->location;

        if (is_string($location)) {
            return $this->trimmed($location);
        }

        if (! is_array($location)) {
            return '';
        }

        $parts = [];

        foreach (['city', 'state', 'country'] as $segment) {
            $value = $this->trimmed($location[$segment] ?? null);

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(', ', $parts);
    }

    /** Signed, short-lived URLs for the documents the worker has to download. */
    protected function documents(ApplyContext $context): ApplyDocuments
    {
        return new ApplyDocuments(
            resumeUrl: $this->documentUrl($context->tailoredResumePath),
            resumeFilename: $this->filename($context->tailoredResumePath, 'resume.pdf'),
            coverLetterUrl: $context->hasCoverLetter()
                ? $this->documentUrl((string) $context->coverLetterPath)
                : null,
            coverLetterFilename: $context->hasCoverLetter()
                ? $this->filename((string) $context->coverLetterPath, 'cover-letter.pdf')
                : null,
        );
    }

    /**
     * A short-lived signed URL for a document the worker has to download. Signed
     * against the disk tailored artefacts are actually written to, which is not
     * necessarily `filesystems.resume_disk`.
     */
    protected function documentUrl(string $path): string
    {
        $disk = config('apply.document_disk', 's3');
        $ttl = (int) config('apply.document_url_ttl_minutes', 10);

        return $this->storage->temporaryUrl(
            $path,
            is_string($disk) && $disk !== '' ? $disk : 's3',
            $ttl
        );
    }

    /**
     * What the uploaded file is called on the form. Taken from the S3 key's own
     * basename, because that is already `resume.pdf` / `cover-letter.pdf`, and
     * stripped of any query string a signed URL would have added.
     */
    protected function filename(string $path, string $fallback): string
    {
        $base = basename(parse_url($path, PHP_URL_PATH) ?: $path);

        return $base !== '' && str_contains($base, '.') ? $base : $fallback;
    }

    /** @return array<string, mixed> */
    protected function metadata(ApplyRunResult $run, ApplyPlan $plan): array
    {
        return [
            'adapter' => $this->key(),
            'elapsed_ms' => $run->elapsedMs,
            'page_title' => $run->title,
            'screenshot_names' => $run->screenshotNames(),
            // Optional questions left blank, versus required ones that stopped
            // the run. `unanswered_questions` mirrors
            // `ApplyResult::$unansweredQuestions` so the same labels are
            // readable from either place in the automation log.
            'skipped_questions' => array_values($plan->skippedQuestions),
            'unanswered_questions' => array_values($plan->unansweredRequired),
            'paused_before_submit' => $plan->pausedForQuestions(),
            'steps' => array_map(static fn ($step): array => [
                'index' => $step->index,
                'kind' => $step->kind,
                'status' => $step->status,
                'selector' => $step->selector,
                'error' => $step->error,
            ], $run->steps),
        ] + $plan->notes;
    }

    protected function isAffirmative(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'yes', 'true', 'y', 'on'], true);
    }

    /** @return list<string> */
    protected function configList(string $key): array
    {
        return $this->stringList(config('apply.adapters.'.$this->key().'.'.$key, []));
    }

    /**
     * Trimmed, non-empty scalars from a config list, defensively — these lists
     * are env-overridable and a deployment can leave a stray blank entry.
     *
     * @return list<string>
     */
    protected function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => is_scalar($value) ? trim((string) $value) : '',
            $values
        ), static fn (string $value): bool => $value !== ''));
    }

    protected function selector(string $name): string
    {
        return $this->trimmed(config('apply.adapters.'.$this->key().'.selectors.'.$name));
    }

    protected function timeout(string $name): ?int
    {
        $value = config('apply.adapters.'.$this->key().'.timeouts.'.$name);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    protected function trimmed(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
