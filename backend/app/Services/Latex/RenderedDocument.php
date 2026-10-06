<?php

namespace App\Services\Latex;

/**
 * The outcome of one render-and-compile pass (Requirements 6.1, 6.2).
 *
 * Two things are true at once here, and the shape of this class exists to hold
 * both: a compile failure is an *expected* result, not an exception (Req 6.2
 * asks that errors be captured and logged rather than crash the worker), and
 * the caller that has to act on the failure — the retry loop of Req 6.4, which
 * feeds the compiler's complaint back to the model as a corrective prompt —
 * needs the failure in a form it can put in a prompt and in a log line.
 *
 * So `texSource` is always present, on success and on failure alike. On failure
 * it is the primary evidence: the .tex that the engine rejected is what the
 * corrective prompt has to reason about, and without it the error message alone
 * is unactionable.
 *
 * Immutable, and constructed only through {@see compiled()} or {@see failed()}.
 * The private constructor is the point: it makes the two states mutually
 * exclusive by construction, so there is no way to produce a `success = true`
 * with no PDF path, or a failure that forgot to say why. A caller can therefore
 * trust `$success` and dereference `$pdfPath` behind it without re-checking.
 */
class RenderedDocument
{
    /**
     * Cap on {@see $compileError}.
     *
     * A LaTeX run that goes wrong is verbose — a missing package or a runaway
     * argument can produce tens of kilobytes of engine chatter. This value is
     * headed for a log line, a `failed_jobs` record, and possibly an LLM prompt
     * where it costs tokens, so it is bounded. 8 KB comfortably holds the
     * several-line error block TeX prints around the offending input while
     * ruling out a pathological case filling the log.
     */
    private const MAX_COMPILE_ERROR = 8000;

    /**
     * @param  string  $texSource  the generated LaTeX source, present in both outcomes
     * @param  ?string  $pdfPath  local filesystem path to the compiled PDF (pre-S3-upload); null on failure
     * @param  bool  $success  whether a PDF was produced
     * @param  ?string  $compileError  bounded engine output explaining the failure; null on success
     * @param  ?int  $exitCode  the engine's exit status; null when it never ran to completion (e.g. a timeout)
     * @param  ?string  $workingDirectory  the retained render directory on failure, where the .log file sits
     */
    private function __construct(
        public readonly string $texSource,
        public readonly ?string $pdfPath,
        public readonly bool $success,
        public readonly ?string $compileError = null,
        public readonly ?int $exitCode = null,
        public readonly ?string $workingDirectory = null,
    ) {}

    /** A PDF was produced at `$pdfPath`. */
    public static function compiled(string $texSource, string $pdfPath): self
    {
        return new self(
            texSource: $texSource,
            pdfPath: $pdfPath,
            success: true,
            compileError: null,
            exitCode: 0,
            workingDirectory: null,
        );
    }

    /**
     * The document did not compile.
     *
     * `$compileError` is normalised rather than stored as given: it is trimmed,
     * truncated to {@see MAX_COMPILE_ERROR}, and an empty message is replaced
     * with a stand-in. That last part matters more than it looks — an engine can
     * fail with no output at all (killed, or exiting non-zero silently), and a
     * null `compileError` on a failed document would read as "no failure" to
     * every caller that checks it, including the log line that is supposed to
     * explain what happened.
     *
     * Truncation keeps the *tail*. TeX prints its error, the offending line, and
     * its context at the point of failure, after however much routine chatter
     * preceded it, so the end of the output is the part with diagnostic value.
     */
    public static function failed(
        string $texSource,
        string $compileError,
        ?int $exitCode = null,
        ?string $workingDirectory = null,
    ): self {
        return new self(
            texSource: $texSource,
            pdfPath: null,
            success: false,
            compileError: self::boundedError($compileError),
            exitCode: $exitCode,
            workingDirectory: $workingDirectory,
        );
    }

    private static function boundedError(string $error): string
    {
        $error = trim($error);

        if ($error === '') {
            return 'The LaTeX engine failed without producing any output.';
        }

        if (mb_strlen($error) <= self::MAX_COMPILE_ERROR) {
            return $error;
        }

        return '[...truncated...] '.mb_substr($error, -self::MAX_COMPILE_ERROR);
    }
}
