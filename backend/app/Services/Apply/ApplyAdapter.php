<?php

namespace App\Services\Apply;

/**
 * One ATS's application flow (design.md §7, Requirements 9.1 and 9.2).
 *
 * An adapter owns all the policy: selectors, field mapping, platform quirks,
 * screening-question answers, and the decision to abort to `needs_review` on a
 * CAPTCHA or login wall. The automation worker stays a dumb executor — adapters
 * compile their flow into a step script via {@see ApplyWorkerClient} and judge
 * the result themselves. That is the same split as JD enrichment, where
 * extraction stayed in PHP.
 *
 * Implementations MUST NOT throw to report a failed application; return
 * {@see ApplyResult::failed()} or {@see ApplyResult::needsReview()} instead. The
 * `SubmitApplication` job converts any escaping exception the same way, so a
 * single stubborn posting never crashes the queue worker.
 *
 * Adapters are selected in registration order by the first `supports()` match on
 * the job's `application_url`; nothing matches for most arbitrary career pages,
 * and those fall back to manual apply rather than a guessed form fill.
 */
interface ApplyAdapter
{
    /**
     * Can this adapter drive `$applicationUrl`? Host/path matching only — no
     * network calls, since this runs for every registered adapter in turn.
     */
    public function supports(string $applicationUrl): bool;

    /** Run the application. Never throws for an expected failure. */
    public function apply(ApplyContext $context): ApplyResult;
}
