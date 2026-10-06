<?php

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Every allowed render of a tailoring run failed to compile (Requirement 6.4).
 *
 * The end of the corrective loop in
 * {@see \App\Services\Tailoring\ResumeTailoringPipeline}, and the boundary
 * between it and the queued `TailorResume` job (task 11.7) that turns this into
 * a `needs_review` flag rather than shipping nothing into an automated
 * application.
 *
 * Deliberately separate from {@see ResumeTailoringException}, even though both
 * end the same tailoring run and both end it in review. They answer different
 * questions and the answer changes what an operator should do:
 *
 *  - {@see ResumeTailoringException} — the model would not produce usable
 *    *content*. Look at the prompt, the schema, or the tier.
 *  - this — the model produced content the template could not *typeset*.
 *    Almost always the template or the engine, not the model (see the loop's
 *    own docblock on why). Look at the retained render directories.
 *
 * Also separate from {@see LatexEngineException}, which means there is no engine
 * to run at all: that one is a deployment fault, affects every run on the host,
 * and never consumes retry budget.
 *
 * Every attempt's compile error is carried rather than only the last. The errors
 * are usually near-identical, and *that* is the diagnostic: three different
 * failures mean the model kept breaking the document in new ways, while three
 * identical ones say the content was never the problem.
 */
class ResumeCompilationException extends RuntimeException
{
    /** Cap on the stored review reason, which is written to a column and shown in the UI. */
    private const MAX_REVIEW_REASON = 500;

    /**
     * @param  array<int, array<string, mixed>>  $attempts  one entry per failed render, in order,
     *                                                      each with `attempt`, `compile_error`,
     *                                                      `exit_code` and `working_directory`
     * @param  array<string, mixed>  $context  diagnostics for logs and the stored reason
     */
    public function __construct(
        string $message,
        public readonly array $attempts = [],
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The compile retry budget is spent.
     *
     * @param  array<int, array<string, mixed>>  $attempts
     */
    public static function retriesExhausted(string $templateKey, array $attempts): self
    {
        $summaries = array_map(
            fn (array $attempt): string => sprintf(
                '#%s %s',
                $attempt['attempt'] ?? '?',
                // Bounded per attempt: compile errors run to 8 KB each
                // (RenderedDocument::MAX_COMPILE_ERROR) and this message is
                // headed for a log line and a column.
                Str::limit((string) ($attempt['compile_error'] ?? 'no engine output'), 300)
            ),
            $attempts
        );

        return new self(
            sprintf(
                'Resume tailoring produced a document that would not compile with template [%s] '
                .'after %d render(s): %s',
                $templateKey,
                count($attempts),
                $summaries === [] ? 'no renders recorded' : implode('; ', $summaries)
            ),
            $attempts,
            [
                'template_key' => $templateKey,
                'compile_attempts' => count($attempts),
                'attempts' => $attempts,
            ]
        );
    }

    /**
     * Every compile error, full length, in attempt order.
     *
     * The untruncated versions the message cannot carry — for a log context or a
     * debugging session, not for a column.
     *
     * @return array<int, string>
     */
    public function compileErrors(): array
    {
        return array_values(array_map(
            fn (array $attempt): string => (string) ($attempt['compile_error'] ?? ''),
            $this->attempts
        ));
    }

    /**
     * Render directories retained by the failed compiles, where the `.log` files
     * sit.
     *
     * @return array<int, string>
     */
    public function renderDirectories(): array
    {
        $directories = [];

        foreach ($this->attempts as $attempt) {
            $directory = $attempt['working_directory'] ?? null;

            if (is_string($directory) && $directory !== '') {
                $directories[] = $directory;
            }
        }

        return $directories;
    }

    /**
     * True when every attempt failed with the same engine error.
     *
     * The signal that re-prompting was never going to help, which is what
     * distinguishes a template bug from a content problem. Worth surfacing on
     * the review item: it tells whoever picks it up not to bother re-running.
     */
    public function failedIdentically(): bool
    {
        $errors = $this->compileErrors();

        return count($errors) > 1 && count(array_unique($errors)) === 1;
    }

    /** The bounded reason to store when flagging the run for manual review. */
    public function reviewReason(): string
    {
        return Str::limit($this->getMessage(), self::MAX_REVIEW_REASON);
    }
}
