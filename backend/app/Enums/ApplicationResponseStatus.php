<?php

namespace App\Enums;

/**
 * The outcome a user reports by hand for an application (Requirement 10.4).
 *
 * Deliberately separate from the pipeline's own vocabulary
 * ({@see PipelineStage}, and the automation-written `applications.status`):
 * this enum describes what the *employer* did after submission, which no
 * adapter can observe, while the pipeline columns describe what the
 * automation did. Keeping the two vocabularies disjoint is what stops a
 * manual "rejected" from being mistaken for a failed apply attempt.
 *
 * Stored in the pre-existing nullable `applications.response_status` string
 * column — design.md's "Modified existing tables" adds no manual-status
 * column, so no migration is involved and `null` keeps its original meaning
 * of "the user has not reported anything".
 */
enum ApplicationResponseStatus: string
{
    /** Submitted, nothing heard back yet. */
    case AwaitingResponse = 'awaiting_response';

    /** The employer acknowledged the application (screen, assessment, etc.). */
    case Acknowledged = 'acknowledged';

    /** An interview has been scheduled or held. */
    case Interviewing = 'interviewing';

    /** An offer was extended. */
    case Offer = 'offer';

    /** The user accepted an offer. */
    case Accepted = 'accepted';

    /** The employer declined the application. */
    case Rejected = 'rejected';

    /** The user pulled out of the process. */
    case Withdrawn = 'withdrawn';

    /**
     * Allowed values, for validation and for the frontend's status picker.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
