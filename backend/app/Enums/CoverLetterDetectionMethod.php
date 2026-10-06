<?php

namespace App\Enums;

/**
 * How a {@see \App\Services\Tailoring\CoverLetterDetection} was reached.
 *
 * Recorded because the decision and the confidence in it are not the same
 * thing, and the caller (task 12.3) logs both. Two identical "not requested"
 * answers can come from a posting that plainly says so ({@see Keyword}), from a
 * posting that never raised the subject ({@see Absent}), or from a classifier
 * that could not be understood ({@see ModelFailed}) — and only the last of
 * those is a signal that something in the pipeline needs attention.
 *
 * It is also the cost audit: {@see Keyword} and {@see Absent} spent nothing,
 * {@see Model} spent one cheap call. A deployment where most postings are
 * arriving as {@see Model} has a keyword list that needs the phrases it is
 * missing (see config/cover_letter.php), which is exactly the kind of tuning
 * Requirement 7.2's cost concern rewards.
 */
enum CoverLetterDetectionMethod: string
{
    /**
     * The posting mentions a cover letter and a cue near that mention settled
     * the question. No model call.
     */
    case Keyword = 'keyword';

    /**
     * The posting never mentions a cover letter (or has no usable description
     * at all), so Requirement 7.2's default applies unopposed. No model call.
     */
    case Absent = 'absent';

    /**
     * The mentions were ambiguous and a routed classification call decided it
     * (Requirement 4.6 attributes the spend under the `cover_letter_detection`
     * task type).
     */
    case Model = 'model';

    /**
     * A model call was warranted and made, but every attempt came back
     * unusable. The result falls back to Requirement 7.2's default rather than
     * failing the pipeline.
     */
    case ModelFailed = 'model_failed';

    /** Did reaching this decision cost a model call? */
    public function spentModelCall(): bool
    {
        return $this === self::Model || $this === self::ModelFailed;
    }
}
