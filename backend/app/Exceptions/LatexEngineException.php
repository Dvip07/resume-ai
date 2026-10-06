<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The configured TeX engine cannot be used, so no compile should be attempted.
 *
 * Requirement 6.2 asks that compilation happen "via a LaTeX engine available in
 * the deployment environment" and that failures be captured rather than crash
 * the worker. A missing binary is the one failure mode that is knowable *before*
 * any work is done, so {@see \App\Services\Latex\LatexEngine::ensureAvailable()}
 * raises this at the top of a render instead of letting the queue discover it
 * halfway through — after the LLM tailoring call has already been paid for.
 *
 * Distinct from a compilation failure (task 12.x): that one means the document
 * was wrong and Requirement 6.4's corrective-prompt retry applies. This one
 * means the *environment* is wrong, and retrying the LLM would be pointless —
 * every attempt would fail identically. Callers must be able to tell the two
 * apart, which is why this is its own type.
 *
 * The message is written for whoever is deploying, not for the code: it names
 * the engine that was looked for and the two env vars that change the outcome,
 * because that string is what shows up in a failed-job record where no
 * structured context is visible.
 */
class LatexEngineException extends RuntimeException
{
    /**
     * @param string  $engine the engine name from `config('latex.engine')`
     * @param ?string $binary the explicit `config('latex.binary')` path, when one was set
     */
    public function __construct(
        string $message,
        public readonly string $engine,
        public readonly ?string $binary = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The engine could not be found at all — neither at an explicit
     * `LATEX_BINARY` path nor anywhere on the process PATH.
     *
     * Both branches are spelled out separately because the fix differs: a bad
     * explicit path is a typo or a stale value, while a failed PATH lookup
     * usually means the binary was never installed in the image, or that the
     * queue worker runs with a different PATH than the shell it was tested from.
     */
    public static function notFound(string $engine, ?string $binary = null): self
    {
        $cause = $binary !== null
            ? sprintf(
                'LATEX_BINARY is set to [%s], but no executable file exists there.',
                $binary
            )
            : sprintf(
                'No [%s] executable was found on PATH. Note that a queue worker '
                    .'may run with a different PATH than an interactive shell.',
                $engine
            );

        return new self(
            sprintf(
                'LaTeX engine [%s] is not available. %s '
                    .'Install the engine in this environment, or set LATEX_BINARY to its '
                    .'absolute path, or set LATEX_ENGINE to an engine that is installed — '
                    .'pdflatex is a supported fallback where tectonic cannot be installed.',
                $engine,
                $cause
            ),
            $engine,
            $binary
        );
    }
}
