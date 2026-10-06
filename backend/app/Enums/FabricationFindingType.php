<?php

namespace App\Enums;

/**
 * What kind of unsupported claim {@see \App\Services\Tailoring\FabricationGuard}
 * found in a tailored document (Requirement 6.6).
 *
 * An enum rather than free-text labels because these values are persisted — they
 * are the `type` key inside `tailored_documents.fabrication_flags` (design.md
 * §"Data model") — and because the frontend's review call-to-action
 * (Requirement 9.4.3) has to say something different for each. A string typo in
 * a JSON column is invisible until someone reads the review queue and finds it
 * grouped wrongly.
 *
 * The three cases are genuinely different failures with different culprits, and
 * that is the reason for keeping them apart rather than reporting one
 * "suspicious" flag:
 *
 *  - {@see UnknownEmployer} and {@see UnsupportedDate}: the *model* wrote a
 *    string naming an organisation or a year the profile does not support.
 *  - {@see MissingFact}: the model is not involved at all. A fact the profile
 *    supplied did not survive into the rendered document, which is a merge or
 *    template bug — the document is *less* true than the profile rather than
 *    more, and re-running the same code will reproduce it.
 */
enum FabricationFindingType: string
{
    /**
     * A model-written string names an organisation that appears nowhere in the
     * profile.
     */
    case UnknownEmployer = 'unknown_employer';

    /**
     * A model-written string states a year, or a range of years, outside the
     * window the profile's own dates support.
     */
    case UnsupportedDate = 'unsupported_date';

    /**
     * An employer, title or date range from the profile is absent from the
     * rendered document. Not a fabrication — the opposite — but the same guard
     * is the only thing positioned to notice, and a resume missing an employer
     * name must not be auto-submitted either.
     */
    case MissingFact = 'missing_fact';

    /**
     * Is this finding evidence of a bug in our own code rather than of a model
     * inventing something?
     *
     * The distinction matters to whoever picks the review item up: a
     * {@see MissingFact} will not go away by re-queueing the job, while the
     * other two plausibly will, since the next generation is a different sample.
     */
    public function indicatesCodeDefect(): bool
    {
        return $this === self::MissingFact;
    }

    /** Short human phrasing for a review item and for log lines. */
    public function label(): string
    {
        return match ($this) {
            self::UnknownEmployer => 'employer not found in profile',
            self::UnsupportedDate => 'date not supported by profile',
            self::MissingFact => 'profile fact missing from document',
        };
    }
}
