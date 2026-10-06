<?php

namespace App\Services\Tailoring;

use App\Exceptions\ResumeCompilationException;
use App\Models\JobListing;
use App\Models\UserProfile;
use App\Services\Latex\LatexRenderService;
use App\Services\Latex\RenderedDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tailor, render, and — when the render fails to compile — re-prompt with the
 * engine's complaint and render again, bounded by
 * `config('pipeline.latex_retry_max', 2)` (Requirement 6.4).
 *
 * ## Why this is its own class
 *
 * It is the only object that has to know about both halves, and both halves went
 * out of their way not to know about each other:
 * {@see ResumeTailoringService} "knows nothing about LaTeX except well enough to
 * refuse it", and {@see LatexRenderService} is "deliberately unaware of the
 * other half" and "does not decide whether to retry". A loop that calls the
 * model and the compiler alternately cannot live in either without breaking that
 * claim — put it on the tailoring service and that service acquires a TeX engine
 * dependency, so every one of its tests needs a stubbed binary; put it on the
 * renderer and the renderer acquires a model router, so rendering a document can
 * suddenly cost money.
 *
 * A third class costs one file and keeps both of those properties, and it gives
 * the queued job of task 11.7 a single collaborator to depend on instead of two
 * plus the sequencing rule between them.
 *
 * ## What the corrective prompt can honestly ask for
 *
 * This is the uncomfortable part of Requirement 6.4 and it is better stated than
 * discovered later.
 *
 * The model never emits LaTeX — it returns a summary, a headline, bullet strings
 * and a skill ordering, and every one of those is escaped by the template's echo
 * format ({@see \App\Services\Latex\LatexEscaper}) before it reaches the engine.
 * Which means *most compile failures here are not the model's fault and cannot
 * be fixed by re-prompting*: a missing package, a broken macro, an unbalanced
 * group in the `.tex.blade.php`, or an engine that is not installed properly
 * will fail identically on every attempt no matter what content it is given.
 * "Retry the LLM generation step with a corrective prompt" is, for those, three
 * wasted model calls.
 *
 * The loop is still worth having, because a real minority of failures *are*
 * content-shaped and the model can genuinely act on them:
 *
 *  - **Overfull/dimension failures.** A summary or bullet far longer than its
 *    budget, or a single unbreakable token (a 90-character URL pasted out of a
 *    job description into a bullet) that overflows a box the template sizes
 *    tightly. Asking for shorter values fixes it.
 *  - **Characters the escaper cannot make safe on its own.** Escaping handles
 *    LaTeX's specials; it does not conjure a glyph the engine's font lacks. A
 *    CJK character, an emoji, or an exotic dash arriving in a bullet is a real
 *    "missing character" failure, and asking for plain ASCII wording fixes it.
 *  - **Content that trips a template edge case** — an empty string where the
 *    template expected at least something, a value whose escaped form is
 *    pathological. Different wording routes around it.
 *
 * So the corrective instruction asks for *different content*, in the terms the
 * model actually controls: shorter, plainer, ASCII, no long unbroken tokens. It
 * never asks for corrected LaTeX, and it never shows the model the `.tex`
 * source — a model shown LaTeX starts writing LaTeX, and the response validator
 * would then reject it as a contract violation, spending the response budget to
 * no benefit. What it does show is the engine's error text (already bounded to
 * 8 KB by {@see RenderedDocument}) plus the model's own previous values, because
 * an error naming an overfull hbox is only actionable next to the string that
 * overflowed it.
 *
 * When the errors come back identical every time,
 * {@see ResumeCompilationException::failedIdentically()} says so on the review
 * item, which is the signal that the template is at fault and nobody should
 * re-run the job.
 *
 * ## Budgets, and which one is which
 *
 * Two independent allowances, and confusing them is the easy mistake:
 *
 *  - `latex.tailoring.max_attempts` — spent *inside* {@see ResumeTailoringService}
 *    when a model *response* is unusable. No engine has run.
 *  - `pipeline.latex_retry_max` — spent *here*, when a perfectly usable response
 *    produced a document that would not *compile*. Each retry re-enters
 *    tailoring with a fresh copy of the allowance above.
 *
 * They cannot consume one another: a compile failure never counts against the
 * response budget, and a response failure never counts against the compile
 * budget (it aborts the run outright with a
 * {@see \App\Exceptions\ResumeTailoringException}, since a model that will not
 * follow the content contract will not follow it better with a compile error
 * attached).
 *
 * An environment fault — {@see \App\Exceptions\LatexEngineException} for a
 * missing engine, {@see \InvalidArgumentException} for an unknown template key —
 * propagates untouched and consumes nothing. Every attempt would fail
 * identically, and a run that burned its budget on it would report a compile
 * problem to the user when the truth is a broken deployment.
 *
 * ## Scope
 *
 * Out of scope here: the fabrication guard (Requirement 6.6, task 11.5) and
 * persistence/S3 upload (task 11.7). This class returns a {@see CompiledResume}
 * or throws, so it is testable with a faked router and a stubbed engine binary
 * and touches no database.
 */
class ResumeTailoringPipeline
{
    /**
     * Characters of each previous model-written value quoted back in the
     * corrective prompt.
     *
     * Enough to identify a bullet and to see that it is too long, without
     * re-sending the whole document: the values are already in the conversation's
     * candidate data, and the retry pays for these tokens on top of a full
     * prompt.
     */
    private const CORRECTION_VALUE_CHARS = 160;

    /**
     * Characters of engine output forwarded to the model.
     *
     * Much tighter than {@see RenderedDocument}'s 8 KB cap, which is sized for a
     * log and a debugging session rather than a prompt. The useful part of a TeX
     * failure is the error line and its context, which sit at the *end* of the
     * output — so this keeps the tail, for the same reason RenderedDocument does.
     */
    private const CORRECTION_ERROR_CHARS = 1500;

    public function __construct(
        private readonly ResumeTailoringService $tailoring,
        private readonly LatexRenderService $renderer,
    ) {}

    /**
     * Produce a compiled, tailored resume for `$listing` from `$profile`.
     *
     * @param  ?string  $templateKey  a template from Requirement 6.3; the configured
     *                                default when null
     *
     * @throws ResumeCompilationException when every allowed render failed to compile
     * @throws \App\Exceptions\ResumeTailoringException when the model will not produce
     *                                                  usable content (propagated, not retried here)
     * @throws \App\Exceptions\LatexEngineException when no TeX engine is available
     * @throws \InvalidArgumentException when `$templateKey` is not a known template
     */
    public function tailorAndCompile(
        JobListing $listing,
        UserProfile $profile,
        ?string $templateKey = null,
    ): CompiledResume {
        $templateKey = $templateKey !== null && trim($templateKey) !== ''
            ? trim($templateKey)
            : $this->tailoring->defaultTemplateKey();

        $maxRetries = $this->maxRetries();

        /** @var array<int, array<string, mixed>> $failures */
        $failures = [];
        $correction = null;

        // One render more than the retry budget: "max N retries" (Req 6.4) means
        // the first attempt is not a retry.
        for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
            // A ResumeTailoringException from here propagates. It is the response
            // budget's own terminal failure and has nothing to do with compiling.
            $tailored = $this->tailoring->tailorWithCorrection($listing, $profile, $correction);

            // LatexEngineException and InvalidArgumentException propagate for the
            // same reason, from the other side: they are faults of the host, not
            // of this document.
            $document = $this->renderer->renderResume($templateKey, $tailored->forTemplate());

            if ($document->success) {
                $compiled = new CompiledResume(
                    tailored: $tailored,
                    document: $document,
                    templateKey: $templateKey,
                    compileAttempts: $attempt,
                    compileErrors: array_map(
                        fn (array $failure): string => (string) $failure['compile_error'],
                        $failures
                    ),
                );

                Log::info('Resume tailored and compiled.', [
                    'job_listing_id' => $listing->getKey(),
                    'user_id' => $profile->user_id,
                ] + $compiled->context());

                return $compiled;
            }

            $failures[] = $failure = [
                'attempt' => $attempt,
                'compile_error' => (string) $document->compileError,
                'exit_code' => $document->exitCode,
                'working_directory' => $document->workingDirectory,
            ];

            Log::warning('Tailored resume failed to compile.', [
                'job_listing_id' => $listing->getKey(),
                'template_key' => $templateKey,
                'model_used' => $tailored->modelUsed,
                'retries_remaining' => max(0, $maxRetries + 1 - $attempt),
            ] + $failure);

            if ($attempt > $maxRetries) {
                break;
            }

            $correction = $this->correctiveInstruction($document, $tailored);
        }

        $exception = ResumeCompilationException::retriesExhausted($templateKey, $failures);

        Log::error($exception->getMessage(), $exception->context + [
            'job_listing_id' => $listing->getKey(),
            'user_id' => $profile->user_id,
            'failed_identically' => $exception->failedIdentically(),
        ]);

        throw $exception;
    }

    /**
     * Renders allowed after the first, from config.
     *
     * Floored at 0 rather than 1: zero is a legitimate setting — it turns the
     * first compile failure straight into a review item, which is what an
     * operator who has concluded the loop is not earning its token spend would
     * choose.
     */
    protected function maxRetries(): int
    {
        return max(0, (int) config('pipeline.latex_retry_max', 2));
    }

    /**
     * The corrective instruction carried into the next tailoring attempt.
     *
     * Three parts, in the order a reader of the prompt needs them: what happened,
     * what the model previously said (so the error has something to attach to),
     * and what to do differently — phrased entirely as content changes, because
     * content is the only thing on the other end of this instruction. See the
     * class docblock for why that list is short and why it is honest about not
     * covering every failure.
     *
     * The engine error is included verbatim (bounded). It is machine chatter
     * rather than instructions, and it originates from a compiler rather than
     * from user input, so there is no prompt-injection surface here that the job
     * description itself does not already present far more directly.
     */
    protected function correctiveInstruction(
        RenderedDocument $document,
        TailoredResumeContent $previous,
    ): string {
        return 'The document built from your previous response failed to typeset. The '
            ."typesetting engine reported:\n\n"
            .$this->boundedError((string) $document->compileError)."\n\n"
            ."These were the values you supplied, and one of them is the likely cause:\n"
            .$this->previousValues($previous)."\n\n"
            .'Return the same JSON schema again with different content. The document '
            .'template applies all formatting, so there is no markup for you to fix — what '
            ."you can change is the text itself:\n"
            .'- Shorten anything long. Prefer a summary and bullets well under their limits; '
            ."an overlong value is the most common cause of this failure.\n"
            .'- Break up or drop any single unbroken run of characters longer than about 40 '
            .'characters, such as a URL, a file path or a long identifier. These cannot be '
            ."wrapped and overflow the page.\n"
            .'- Use plain ASCII wording. Replace accented letters, non-Latin scripts, emoji, '
            .'typographic dashes and quotes, and mathematical or currency symbols other than '
            ."a plain dollar sign with plain equivalents.\n"
            .'- Keep every value non-empty, and keep it plain prose: no LaTeX, Markdown, HTML '
            .'or backslash commands.';
    }

    /**
     * The tail of the engine's output, bounded for a prompt.
     *
     * Truncation marker included, and the *tail* kept: TeX prints its routine
     * chatter first and the error it died on last.
     */
    protected function boundedError(string $error): string
    {
        $error = trim($error);

        if ($error === '') {
            return 'The engine failed without reporting a reason.';
        }

        if (mb_strlen($error) <= self::CORRECTION_ERROR_CHARS) {
            return $error;
        }

        return '[...truncated...] '.mb_substr($error, -self::CORRECTION_ERROR_CHARS);
    }

    /**
     * The model's previous contributions, quoted back.
     *
     * Only the fields the model wrote. Employers, titles, dates, education and
     * contact details came from the profile and cannot be changed by a retry, so
     * showing them here would invite a response that tries to — the one thing the
     * whole design forbids.
     */
    protected function previousValues(TailoredResumeContent $previous): string
    {
        $content = $previous->forTemplate();

        $lines = [
            '- headline: '.$this->quote((string) ($content['headline'] ?? '')),
            '- summary ('.mb_strlen((string) ($content['summary'] ?? '')).' characters): '
                .$this->quote((string) ($content['summary'] ?? '')),
        ];

        foreach ($previous->bullets() as $position => $bullet) {
            $lines[] = sprintf(
                '- bullet %d (%d characters): %s',
                $position + 1,
                mb_strlen($bullet),
                $this->quote($bullet)
            );
        }

        return implode("\n", $lines);
    }

    protected function quote(string $value): string
    {
        return $value === '' ? '(empty)' : '"'.Str::limit($value, self::CORRECTION_VALUE_CHARS).'"';
    }
}
