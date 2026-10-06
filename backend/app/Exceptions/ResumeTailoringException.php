<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The tailoring prompt could not produce usable structured content, even after
 * the stricter-format retry.
 *
 * The boundary between {@see \App\Services\Tailoring\ResumeTailoringService}
 * and its caller, and it exists for the same reason
 * {@see JobScoringException} does: the service never decides what a failure
 * means for a listing. The queued `TailorResume` job (task 11.7) catches this
 * and flags the run for manual review rather than shipping an untailored — or
 * half-tailored — resume into an automated application.
 *
 * Deliberately *not* a subclass of {@see ModelRouterException}: the common case
 * is a billed, successful call that returned something unusable (a blank
 * summary, bullets for a role the candidate does not have, LaTeX where prose
 * was asked for). A caller must be able to tell "the model will not follow the
 * content contract" apart from "OpenRouter is down", because only the first is
 * worth surfacing to the user as a review item.
 *
 * Everything a reviewer or a later debugging session needs is carried here
 * rather than only logged: the task type and one entry per attempt with its
 * reason.
 */
class ResumeTailoringException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $attempts  one entry per attempt, in order
     * @param  array<string, mixed>  $context  diagnostics for logs and the stored
     *                                         `needs_review` reason
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
     * Every attempt at the tailoring prompt failed.
     *
     * The message names the count and each attempt's reason, because that
     * string is what ends up in a `needs_review` note where the structured
     * context is not visible.
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
                'Resume tailoring (task [%s]) failed after %d attempt(s): %s',
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
     * The service was asked to tailor a profile with nothing to tailor.
     *
     * Separate from a prompt failure and raised before any model call: no
     * amount of re-prompting invents an employment history, so this is a
     * pipeline-ordering problem (a listing reached tailoring before the resume
     * was parsed) rather than a model problem.
     */
    public static function nothingToTailor(string $reason): self
    {
        return new self(
            'Resume tailoring cannot proceed: '.$reason,
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
     * The reason to store when flagging the run for manual review — bounded,
     * since it is written to a column and shown in the frontend.
     */
    public function reviewReason(): string
    {
        return \Illuminate\Support\Str::limit($this->getMessage(), 500);
    }
}
