<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A scoring prompt could not be completed, even after the stricter-format retry
 * Requirement 5.5 allows.
 *
 * This is the boundary between {@see \App\Services\Scoring\JobScoringService}
 * and its caller: the service never decides what a failure means for a listing.
 * The queued `ScoreJobListing` job (task 10.3) catches this and does what
 * Requirement 5.5 mandates — fall back to `store_only` and flag the job for
 * manual review rather than silently dropping it. Anything the reviewer or a
 * later debugging session needs is therefore carried here rather than only
 * logged: which prompt failed, its task type, and one entry per attempt with
 * its reason and an excerpt of whatever the model did return.
 *
 * Not a subclass of {@see ModelRouterException}: a scoring failure is often not
 * a router failure at all (a billed, successful call that returned a rating of
 * 9), and callers must be able to distinguish "the model gave us nonsense
 * twice" from "OpenRouter is down".
 */
class JobScoringException extends RuntimeException
{
    /** Prompt 1 of the pipeline — fit analysis (Requirement 5.1). */
    public const PROMPT_FIT_ANALYSIS = 'fit_analysis';

    /** Prompt 2 of the pipeline — rating decision (Requirement 5.1). */
    public const PROMPT_RATING_DECISION = 'rating_decision';

    /**
     * @param string                           $prompt   one of the PROMPT_* constants
     * @param array<int, array<string, mixed>> $attempts one entry per attempt, in order
     * @param array<string, mixed>             $context  diagnostics for logs and the
     *                                                   stored `needs_review` reason
     */
    public function __construct(
        string $message,
        public readonly string $prompt,
        public readonly array $attempts = [],
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Every attempt at one prompt failed.
     *
     * The message names the prompt, the count and each attempt's reason,
     * because that string is what ends up in a `needs_review` note where the
     * structured context is not visible.
     *
     * @param array<int, array<string, mixed>> $attempts
     */
    public static function promptFailed(
        string $prompt,
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
                'Scoring prompt [%s] (task [%s]) failed after %d attempt(s): %s',
                $prompt,
                $taskType,
                count($attempts),
                $reasons === [] ? 'no attempts recorded' : implode('; ', $reasons)
            ),
            $prompt,
            $attempts,
            [
                'prompt' => $prompt,
                'task_type' => $taskType,
                'attempts' => $attempts,
            ],
            $previous
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
     * The reason to store on the listing/score when flagging it for manual
     * review (Requirement 5.5) — bounded, since it is written to a column and
     * shown in the frontend.
     */
    public function reviewReason(): string
    {
        return \Illuminate\Support\Str::limit($this->getMessage(), 500);
    }
}
