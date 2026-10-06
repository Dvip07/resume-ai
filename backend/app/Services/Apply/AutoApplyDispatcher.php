<?php

namespace App\Services\Apply;

use App\Enums\PipelineStage;
use App\Enums\TailoredDocumentType;
use App\Jobs\SubmitApplication;
use App\Models\JobListing;
use App\Models\JobScore;
use App\Models\TailoredDocument;
use App\Models\UserAutomationSetting;
use Illuminate\Support\Facades\Log;

/**
 * The `Tailor → Apply` hand-off (Requirement 11.3, design.md §4): the one place
 * {@see SubmitApplication} is dispatched from the tailoring stage, and the one
 * place that decides whether a tailored listing may be submitted at all.
 *
 * It exists as a service rather than as a few lines inside
 * {@see \App\Jobs\TailorResume} because *two* jobs finish the tailoring stage —
 * the resume job and {@see \App\Jobs\TailorCoverLetter} — and both need the same
 * answer to the same question. A copy in each would be two places for the
 * auto-apply gate to drift apart, which is the one piece of this pipeline where
 * drifting means submitting an application nobody asked for.
 *
 * ## The chain, and why the cover letter owns the last link
 *
 * Two mechanisms were available for the "do not submit a resume-only
 * application for a posting that wants a letter, and do not submit twice"
 * problem:
 *
 *  1. **Both jobs call this gate, which checks for both documents.** Whichever
 *     finishes last sees a complete set and dispatches. It needs this class to
 *     know whether a letter is *wanted* — and the only cheap proxy for that
 *     (is there a cover-letter row for the pair?) is false during the window
 *     between the resume finishing and the letter job creating its `pending`
 *     row, which is exactly the window a premature resume-only submission would
 *     slip through. Asking the detector again here would re-pay for a model
 *     call and still leave two call sites racing for one dispatch.
 *  2. **A linear chain: resume → letter → apply.** {@see \App\Jobs\TailorResume}
 *     detects the requirement once (it has the description in hand anyway),
 *     and *either* dispatches the letter job, handing the apply dispatch to it,
 *     *or* calls this gate itself when no letter is wanted. Exactly one job
 *     ever reaches this gate for a given listing, so ordering is a property of
 *     the structure rather than of a check.
 *
 * Option 2 is what is built. The cost is that every terminal path in
 * {@see \App\Jobs\TailorCoverLetter} — success, an optional letter that failed,
 * a re-detection that disagrees, a letter that was already rendered — has to
 * call this gate, or the chain dead-ends with documents and no submission.
 * That is a visible, testable obligation; a race in option 1 would not be.
 *
 * ## The gate (Requirements 5.2, 9.1)
 *
 * Every condition below has to hold, and each one is here because something
 * upstream legitimately produces a tailored document that must *not* be
 * submitted automatically:
 *
 * | Condition                            | What it keeps out                        |
 * |--------------------------------------|------------------------------------------|
 * | stage is exactly `tailored`          | a fabrication flag or a failed mandatory letter (`needs_review`), a dead stage (`failed`), an application already out (`applying`/`applied`) |
 * | a scored verdict exists for the pair | a hand-tailored listing that was never scored |
 * | {@see UserAutomationSetting::allowsAutoApplyAt()} on that verdict | auto-apply off, or a rating under the user's bar — i.e. Requirement 9.8's manual "tailor this" on a `store_only` job |
 * | a usable tailored resume exists      | a `rendered` row whose upload failed     |
 *
 * The rating is re-measured here rather than trusted from the fact that
 * tailoring ran, because tailoring runs for more reasons than the auto-apply
 * branch: {@see \App\Jobs\TailorResume::isPastTailoring()} deliberately admits
 * `store_only`, `needs_review` and `scored` rows so a user can tailor documents
 * by hand. Those runs must produce downloadable documents and nothing else, and
 * the only way to tell them from the automated branch after the fact is to ask
 * the same question {@see \App\Jobs\ScoreJobListing::branch()} asked.
 *
 * A refused dispatch is not a failure: the documents are stored, the listing
 * sits at `tailored`, and the dashboard offers them for a manual application.
 * Every refusal is logged with the reason, because "my documents are ready and
 * nothing happened" is otherwise indistinguishable from a broken queue.
 */
class AutoApplyDispatcher
{
    /**
     * Dispatch {@see SubmitApplication} for this (listing, user) pair if every
     * condition in the class docblock holds.
     *
     * @param  array<string, mixed>  $context  extra log context from the calling job
     * @return bool whether a submission was queued
     */
    public function dispatchIfReady(JobListing $listing, int $userId, array $context = []): bool
    {
        $context = [
            'job_listing_id' => $listing->getKey(),
            'user_id' => $userId,
            'pipeline_stage' => $listing->pipeline_stage?->value,
        ] + $context;

        if ($listing->pipeline_stage !== PipelineStage::Tailored) {
            // Not an error: `needs_review` is where a fabrication flag or a
            // failed mandatory letter puts the row, and keeping it out of the
            // apply path is the whole point of that stage.
            Log::info('Not auto-applying: the listing is not in the tailored stage.', $context);

            return false;
        }

        $score = JobScore::latestAttempt((int) $listing->getKey(), $userId);

        if ($score === null) {
            Log::info('Not auto-applying: the listing has no score for this user.', $context);

            return false;
        }

        $settings = UserAutomationSetting::forUser($userId);

        if (! $settings->allowsAutoApplyAt((int) $score->stars)) {
            Log::info('Not auto-applying: the listing did not clear this user\'s auto-apply gate.', $context + [
                'stars' => (int) $score->stars,
                'auto_apply_enabled' => $settings->auto_apply_enabled,
                'star_threshold' => $settings->auto_apply_star_threshold,
                'reason' => $settings->auto_apply_enabled
                    ? 'rating below the user\'s auto-apply threshold'
                    : 'auto-apply is disabled for this user',
            ]);

            return false;
        }

        $resume = $this->usableResume($listing, $userId);

        if ($resume === null) {
            // `rendered` alone is not enough — TailoredDocument::isUsable() also
            // insists on a path, and a row whose upload failed is exactly the one
            // that must not be attached to a submission.
            Log::warning('Not auto-applying: no usable tailored resume is stored for this listing.', $context);

            return false;
        }

        SubmitApplication::dispatch((int) $listing->getKey(), $userId);

        Log::info('Queued an application for a tailored job listing.', $context + [
            'stars' => (int) $score->stars,
            'tailored_document_id' => $resume->getKey(),
        ]);

        return true;
    }

    /**
     * The newest tailored resume for the pair that could actually be attached to
     * a submission, or null.
     *
     * The same question {@see SubmitApplication::document()} asks when it runs.
     * Asked twice on purpose: that job has to ask it anyway (it may be dispatched
     * by hand), and asking it here means a failed upload becomes a log line at
     * dispatch time rather than a `needs_review` application the user has to read.
     */
    protected function usableResume(JobListing $listing, int $userId): ?TailoredDocument
    {
        $document = TailoredDocument::query()
            ->where('user_id', $userId)
            ->where('job_listing_id', $listing->getKey())
            ->ofType(TailoredDocumentType::Resume)
            ->rendered()
            ->latest('id')
            ->first();

        return $document !== null && $document->isUsable() ? $document : null;
    }
}
