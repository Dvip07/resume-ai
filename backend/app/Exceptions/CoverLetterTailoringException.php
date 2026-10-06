<?php

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The cover-letter prompt could not produce usable body prose, even after the
 * stricter-format retry (Requirement 7.1).
 *
 * ## Why this is a separate class from {@see ResumeTailoringException}
 *
 * Structurally the two are the same object, and reusing the resume one was the
 * obvious move. It was rejected for a reason that has nothing to do with the
 * shape and everything to do with the two documents' different standing in an
 * application.
 *
 * A failed *resume* is fatal to the application: there is nothing to submit, so
 * the run is flagged for review. A failed *cover letter* is not. Requirement
 * 7.2's asymmetry is that the letter is conditional work in the first place —
 * detection ({@see \App\Services\Tailoring\CoverLetterRequirementDetector})
 * already answers "not requested" for most postings and, when its own
 * classifier fails, answers "not requested" rather than throwing. So the
 * caller's correct response to *this* exception is usually to record it and
 * submit the resume alone, and the correct response to the resume one is to
 * stop. Two branches, therefore two catch clauses; one shared class would make
 * the queued jobs distinguish them by inspecting a message, which is exactly
 * the fragility a typed exception exists to remove.
 *
 * The second reason is smaller and more immediate: {@see reviewReason()} is
 * written to a column and rendered in the frontend, so the noun in it is
 * user-visible. "Resume tailoring failed" against a run whose resume tailored
 * fine would be a wrong statement shown to a user.
 *
 * As with its sibling, deliberately *not* a subclass of
 * {@see ModelRouterException}: the common case is a billed, successful call
 * that returned something unusable — one paragraph, or LaTeX where prose was
 * asked for — and "the model will not follow the contract" is a different
 * operational fact from "OpenRouter is down".
 */
class CoverLetterTailoringException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $attempts  one entry per attempt, in order
     * @param  array<string, mixed>  $context  diagnostics for logs and any stored
     *                                         review note
     */
    public function __construct(
        string $message,
        public readonly array $attempts = [],
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Every attempt at the generation prompt failed.
     *
     * The message names the count and each attempt's reason, because that
     * string is what survives into a review note where the structured context
     * is not visible.
     *
     * @param  array<int, array<string, mixed>>  $attempts
     */
    public static function promptFailed(
        string $taskType,
        array $attempts,
        ?Throwable $previous = null,
    ): self {
        $reasons = array_map(
            fn (array $attempt): string => sprintf(
                '#%s %s',
                $attempt['attempt'] ?? '?',
                $attempt['reason'] ?? 'unknown error'
            ),
            $attempts
        );

        return new self(
            sprintf(
                'Cover letter tailoring (task [%s]) failed after %d attempt(s): %s',
                $taskType,
                count($attempts),
                $reasons === [] ? 'no attempts recorded' : implode('; ', $reasons)
            ),
            $attempts,
            [
                'task_type' => $taskType,
                'attempts' => $attempts,
            ],
            $previous
        );
    }

    /**
     * There was nothing to write a letter from.
     *
     * Raised before any model call, for the same reason as
     * {@see ResumeTailoringException::nothingToTailor()}: no amount of
     * re-prompting invents a posting to apply to or a career to describe, so
     * this is a pipeline-ordering problem (generation reached before enrichment
     * or resume parsing) rather than a model problem.
     */
    public static function nothingToWrite(string $reason): self
    {
        return new self(
            'Cover letter tailoring cannot proceed: '.$reason,
            [[
                'attempt' => 0,
                'parse_failure' => false,
                'reason' => $reason,
            ]],
            ['reason' => $reason]
        );
    }

    /** True when the last attempt failed on the *content* rather than the call. */
    public function failedOnParsing(): bool
    {
        $lastKey = array_key_last($this->attempts);
        $last = $lastKey === null ? null : $this->attempts[$lastKey];

        return is_array($last) && ($last['parse_failure'] ?? false) === true;
    }

    /**
     * The reason to store when recording the failure — bounded, since it is
     * written to a column and shown in the frontend.
     */
    public function reviewReason(): string
    {
        return Str::limit($this->getMessage(), 500);
    }
}
