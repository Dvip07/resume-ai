<?php

namespace App\Enums;

/**
 * What a posting says about a cover letter (Requirements 7.1, 7.2).
 *
 * Three states rather than a boolean, because Requirement 7.1 puts "required"
 * and "optional-but-requested" in the same sentence while leaving them
 * distinguishable, and the caller has decisions that need the distinction:
 *
 *  1. Generate at all? {@see wantsCoverLetter()} — the only question
 *     Requirement 7.2 strictly needs answered, and the reason
 *     {@see NotRequested} exists as its own case rather than as a null.
 *  2. How to treat a *failed* generation? A required letter that could not be
 *     produced means the application is incomplete and should not be
 *     auto-applied; an optional one that failed means the application proceeds
 *     with the tailored resume alone. Collapsing the two into a boolean would
 *     force the tailoring job to either block every application on an optional
 *     letter or submit an incomplete one.
 *  3. Whether disagreement is worth reviewing. "Optional" is the state a
 *     detector is most likely to be wrong about, so a user reviewing why a
 *     letter was or was not written wants to see it named.
 *
 * {@see NotRequested} is the default the whole detector is built around: it is
 * what an empty description, an unmentioned cover letter and an unusable model
 * response all resolve to.
 */
enum CoverLetterRequirement: string
{
    /** The posting mandates a cover letter. */
    case Required = 'required';

    /**
     * The posting invites a cover letter without mandating it — "optional",
     * "encouraged", "welcome if you'd like". Requirement 7.1 treats this as
     * worth generating.
     */
    case Optional = 'optional';

    /**
     * The posting does not ask for one, or explicitly does not want one. The
     * two are deliberately the same state: Requirement 7.2 prescribes the same
     * action for both — generate nothing — and nothing downstream behaves
     * differently between "silent" and "refused". The *reason* survives in
     * {@see \App\Services\Tailoring\CoverLetterDetection::$evidence}, which is
     * where an operator asking "why no letter?" should be looking anyway.
     */
    case NotRequested = 'not_requested';

    /** Should a cover letter be generated for this posting (Requirement 7.1)? */
    public function wantsCoverLetter(): bool
    {
        return $this !== self::NotRequested;
    }

    /**
     * Would a missing cover letter make the application incomplete?
     *
     * The question that separates this case from {@see Optional} downstream: a
     * failed *required* letter should hold the application back, a failed
     * optional one should not.
     */
    public function isMandatory(): bool
    {
        return $this === self::Required;
    }
}
