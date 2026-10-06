<?php

namespace App\Services\Latex;

use App\Enums\TailoredDocumentType;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Turns structured content into a `.tex` document and that document into a PDF
 * (Requirements 6.1, 6.2, 7.1).
 *
 * Both tailored document kinds go through here — resumes
 * ({@see renderResume()}) and cover letters ({@see renderCoverLetter()}) — and
 * they share everything from {@see compile()} down. The two differ only in which
 * whitelist resolves the template and what the output file is called; the
 * process handling, output capture, cleanup rule and failure contract below are
 * one implementation, not two.
 *
 * This is the mechanical half of tailoring, and it is deliberately unaware of
 * the other half. It does not call a model, does not know what a job listing is,
 * and does not decide whether to retry — it takes a template key and an array,
 * and hands back a {@see RenderedDocument}. The LLM step (task 11.3, second
 * half) and the corrective-prompt retry loop (Req 6.4, task 11.4) sit *above*
 * this class and call it repeatedly. Keeping the boundary there is what makes
 * the retry loop testable without a model and this class testable without one.
 *
 * ## The failure contract, which is the interesting part
 *
 * Two kinds of thing can go wrong, and they are reported differently on purpose:
 *
 *  - **The document is wrong** — the engine exits non-zero, hangs until the
 *    timeout, or exits cleanly without writing a PDF. These are *results*, not
 *    exceptions: Req 6.2 requires them captured and logged rather than crashing
 *    the worker, and Req 6.4 requires the error text be available to feed back
 *    into a corrective prompt. They return `success = false`.
 *  - **The environment is wrong** — no TeX engine installed, or a template key
 *    that does not exist. Retrying the model against either is pointless, since
 *    every attempt fails identically. These throw
 *    ({@see \App\Exceptions\LatexEngineException},
 *    {@see \InvalidArgumentException}) so they surface as a failed job with a
 *    deployer-readable message instead of being mistaken for bad LLM output and
 *    burning the retry budget.
 *
 * {@see \App\Services\Latex\LatexEngine::ensureAvailable()} is called first, before
 * the view is rendered and before any directory is created, so the knowable
 * environment fault is raised at the cheapest possible moment.
 */
class LatexRenderService
{
    /**
     * Template key -> view name, and the only way a view name is produced here.
     *
     * A caller-supplied string is never interpolated into a view name. The key
     * is looked up in this map and the *value* — a literal written in this file —
     * is what reaches the view factory. That closes the whole class of problem
     * at once: `../../../etc/passwd`, `latex::../mail/welcome`, and a key naming
     * a real-but-unintended template all miss the map and throw. Validating the
     * string with a pattern instead would leave the question of what the pattern
     * missed permanently open; a whitelist has no such question.
     *
     * Req 6.3 lets a user pick a template per tailoring run, so this map is
     * where a second design gets added. Keys are the identifiers persisted
     * alongside the output, which is why they are stable, human-meaningful
     * strings rather than view paths.
     */
    private const RESUME_TEMPLATES = [
        'default' => 'latex::resume',
    ];

    /**
     * The same thing for cover letters (Requirement 7.1), and deliberately a
     * *second* map rather than more entries in the first.
     *
     * Merging them would make one namespace of keys serve two document kinds,
     * and the key does not travel alone: it is persisted on
     * `tailored_documents.template_key` next to a `type`
     * ({@see \App\Enums\TailoredDocumentType}). With one map, `type = cover_letter`
     * plus `template_key = 'default'` could resolve `latex::resume` — the row
     * would look valid, the render would succeed, and the user would get a resume
     * where their cover letter should be. There is no exception to catch in that
     * story, which is exactly what makes it worth designing out.
     *
     * Two maps make the type and the key agree by construction: `renderResume()`
     * consults only the resume map and `renderCoverLetter()` only this one, so a
     * key from the wrong kind is an unknown key and throws. It also lets the two
     * sets of designs evolve independently — a 'modern' resume and a 'modern'
     * cover letter are unrelated files and need not be added in step.
     */
    private const COVER_LETTER_TEMPLATES = [
        'default' => 'latex::cover-letter',
    ];

    /**
     * The engine name that takes tectonic-style arguments. Everything else is
     * assumed to take TeX Live-style arguments — see {@see compileCommand()}.
     */
    private const ENGINE_TECTONIC = 'tectonic';

    public function __construct(private readonly LatexEngine $engine) {}

    /**
     * Render `$content` through the named resume template and compile it.
     *
     * @param  string  $templateKey  a key of {@see RESUME_TEMPLATES}
     * @param  array<string, mixed>  $content  the template's data contract, documented at the
     *                                         top of `resources/views/latex/resume.tex.blade.php`.
     *                                         Every value is LaTeX-escaped by the template's echo
     *                                         format ({@see LatexEscaper}), so untrusted strings —
     *                                         profile fields, model output — are safe to pass
     *                                         through unmodified.
     *
     * @throws \App\Exceptions\LatexEngineException when no TeX engine is available
     * @throws InvalidArgumentException when `$templateKey` is not a known template
     */
    public function renderResume(string $templateKey, array $content): RenderedDocument
    {
        // Order matters: cheapest fatal check first, so a misconfigured host or a
        // bad key costs nothing.
        $view = $this->resolveView($templateKey, self::RESUME_TEMPLATES, 'resume');
        $this->engine->ensureAvailable();

        $texSource = View::make($view, $content)->render();

        return $this->compile($texSource, $this->documentBaseName($content, TailoredDocumentType::Resume));
    }

    /**
     * Render `$content` through the named cover-letter template and compile it
     * (Requirement 7.1).
     *
     * Identical in shape to {@see renderResume()}, including the ordering of the
     * two fatal checks and the whole failure contract described in the class
     * docblock — a caller that handles one handles the other. What it is *not* is
     * interchangeable with it: `$templateKey` is looked up in
     * {@see COVER_LETTER_TEMPLATES} only, so a resume key throws here.
     *
     * @param  string  $templateKey  a key of {@see COVER_LETTER_TEMPLATES}
     * @param  array<string, mixed>  $content  the template's data contract, documented at the top
     *                                         of `resources/views/latex/cover-letter.tex.blade.php`.
     *                                         As with resumes, every value is LaTeX-escaped by the
     *                                         template's echo format ({@see LatexEscaper}), so model
     *                                         prose and profile fields pass through unmodified.
     *
     * @throws \App\Exceptions\LatexEngineException when no TeX engine is available
     * @throws InvalidArgumentException when `$templateKey` is not a known template
     */
    public function renderCoverLetter(string $templateKey, array $content): RenderedDocument
    {
        $view = $this->resolveView($templateKey, self::COVER_LETTER_TEMPLATES, 'cover letter');
        $this->engine->ensureAvailable();

        $texSource = View::make($view, $content)->render();

        return $this->compile($texSource, $this->documentBaseName($content, TailoredDocumentType::CoverLetter));
    }

    /**
     * Look a caller-supplied key up in one of the whitelists.
     *
     * Parameterised over the map rather than duplicated per kind, because the
     * guarantee being made is a property of the lookup itself — the *value* from
     * the map reaches the view factory, never the argument — and one
     * implementation is one place for that to stay true. `$kind` only names the
     * document in the message.
     *
     * @param  array<string, string>  $templates
     *
     * @throws InvalidArgumentException
     */
    private function resolveView(string $templateKey, array $templates, string $kind): string
    {
        if (! array_key_exists($templateKey, $templates)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown %s template [%s]. Known templates: %s.',
                $kind,
                $templateKey,
                implode(', ', array_keys($templates))
            ));
        }

        return $templates[$templateKey];
    }

    /**
     * Write the source to a private per-render directory and run the engine over
     * it.
     *
     * Each render gets its own uuid-named subdirectory rather than sharing one
     * working directory. LaTeX writes `.aux`, `.log` and `.out` files named after
     * the input, so two concurrent queue workers rendering the same base name in
     * a shared directory would overwrite each other's auxiliaries mid-compile —
     * a data race that would show up as an intermittent, unreproducible compile
     * failure. Isolation also makes the cleanup rule trivial: the directory
     * either goes away entirely or is kept entirely.
     */
    private function compile(string $texSource, string $baseName): RenderedDocument
    {
        $renderDirectory = $this->createRenderDirectory();
        $texPath = $renderDirectory.'/'.$baseName.'.tex';
        File::put($texPath, $texSource);

        $timeout = max(1, (int) config('latex.timeout', 120));
        $process = new Process(
            $this->compileCommand($texPath, $renderDirectory),
            $renderDirectory,
            null,
            null,
            $timeout
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            // A compile that has not finished by now is stuck, not slow — most
            // often waiting on stdin after an error in the document. Treat it as
            // a bad document, which it almost always is, and hand the retry loop
            // something it can act on.
            return $this->fail(
                $texSource,
                $renderDirectory,
                sprintf(
                    'LaTeX compilation timed out after %d seconds. Partial engine output: %s',
                    $timeout,
                    $this->engineOutput($process)
                ),
                null
            );
        }

        if (! $process->isSuccessful()) {
            return $this->fail(
                $texSource,
                $renderDirectory,
                $this->engineOutput($process),
                $process->getExitCode()
            );
        }

        $pdfPath = $renderDirectory.'/'.$baseName.'.pdf';

        // A zero exit with no PDF is rare but real: some engines report success
        // after recovering from an error that still left them with nothing to
        // write. Checking the artefact rather than trusting the status code is
        // the only way to be sure a caller's `$pdfPath` is dereferenceable.
        if (! File::isFile($pdfPath)) {
            return $this->fail(
                $texSource,
                $renderDirectory,
                sprintf(
                    'The LaTeX engine exited successfully but produced no PDF at [%s]. Engine output: %s',
                    $pdfPath,
                    $this->engineOutput($process)
                ),
                $process->getExitCode()
            );
        }

        return RenderedDocument::compiled($texSource, $this->keepPdfAndCleanUp($pdfPath, $renderDirectory));
    }

    /**
     * The engine invocation, as an argv array.
     *
     * Never a shell string: the array form is passed to `execve` without a shell,
     * so a filename containing a space, a quote or a `;` is one argument and
     * cannot become a second command. Combined with the base-name sanitising in
     * {@see documentBaseName()}, there is no path from content to command.
     *
     * The two supported engines disagree about almost every flag, which is why
     * this branches rather than parameterising:
     *
     * **tectonic** (`tectonic [flags] <input.tex>`, the "V1" default CLI):
     *  - `--outdir` places output beside the source in our render directory.
     *  - `--keep-logs` is required, not optional: tectonic *discards* the TeX log
     *    by default, and the log is the artefact worth keeping a failed
     *    directory for.
     *  - `--print` forwards the engine's chatter, which is where the actual TeX
     *    error text lives. Without it a failure reports little more than that it
     *    failed.
     *  - `--untrusted` hard-disables shell-escape and friends regardless of other
     *    settings. This document is assembled from model output and user profile
     *    data, which is the definition of untrusted input; `\write18` in a
     *    resume would be arbitrary command execution on the worker. Requires
     *    tectonic >= 0.7.
     *  - Tectonic stops at the first error by default, so it needs no equivalent
     *    of `-halt-on-error`.
     *
     * **pdflatex / xelatex / lualatex** (the fallback branch):
     *  - `-interaction=nonstopmode` stops the engine prompting on stdin, which is
     *    what makes an errored document hang until the timeout instead of failing.
     *  - `-halt-on-error` makes it exit non-zero on the first error rather than
     *    limping to the end and reporting success with a broken PDF.
     *  - `-output-directory` is spelled differently from tectonic's `--outdir`.
     *  - `-no-shell-escape` is the shell-escape lockout, spelled differently again.
     *  - The log is written next to the output unconditionally; there is no
     *    keep-logs flag to pass.
     *
     * @return list<string>
     */
    private function compileCommand(string $texPath, string $outputDirectory): array
    {
        $binary = (string) $this->engine->binaryPath();

        if (strtolower($this->engine->name()) === self::ENGINE_TECTONIC) {
            return [
                $binary,
                '--outdir', $outputDirectory,
                '--keep-logs',
                '--print',
                '--untrusted',
                $texPath,
            ];
        }

        return [
            $binary,
            '-interaction=nonstopmode',
            '-halt-on-error',
            '-no-shell-escape',
            '-output-directory='.$outputDirectory,
            $texPath,
        ];
    }

    /**
     * Whatever the engine said, from wherever it said it.
     *
     * stderr first because that is where tectonic writes its diagnostics, with a
     * fallback to stdout because pdflatex writes its errors to stdout and leaves
     * stderr empty — reading only one stream would silently lose the entire error
     * message for one of the two supported engines.
     */
    private function engineOutput(Process $process): string
    {
        $stderr = trim($process->getErrorOutput());

        return $stderr !== '' ? $stderr : trim($process->getOutput());
    }

    /**
     * Record the failure and keep the evidence.
     *
     * The render directory is deliberately *not* deleted. The `.log` file it
     * holds is more informative than anything on the process's streams — it is
     * where TeX writes the full error context — and a failure that cleans up
     * after itself destroys the only copy. The log line says where it was left
     * so nobody has to guess, and so an operator has something to point `find`
     * at when reclaiming space.
     */
    private function fail(string $texSource, string $renderDirectory, string $error, ?int $exitCode): RenderedDocument
    {
        Log::warning('LaTeX compilation failed; render directory retained for debugging.', [
            'engine' => $this->engine->name(),
            'engine_version' => $this->engine->version(),
            'exit_code' => $exitCode,
            'render_directory' => $renderDirectory,
            'error' => $error,
        ]);

        return RenderedDocument::failed($texSource, $error, $exitCode, $renderDirectory);
    }

    /**
     * Move the PDF out of the render directory, then delete the directory.
     *
     * The PDF has to outlive the cleanup because the caller uploads it to S3
     * afterwards, so it is moved up one level into the configured working
     * directory. The uuid stays in its name to keep concurrent renders of the
     * same person's resume from colliding there.
     *
     * If the move fails the directory is left alone and the original path is
     * returned: an uncleaned directory is untidy, whereas returning a path to a
     * file that was just deleted is a bug in the caller's next step.
     */
    private function keepPdfAndCleanUp(string $pdfPath, string $renderDirectory): string
    {
        $keptPath = dirname($renderDirectory).'/'.basename($renderDirectory).'-'.basename($pdfPath);

        if (! File::move($pdfPath, $keptPath)) {
            return $pdfPath;
        }

        File::deleteDirectory($renderDirectory);

        return $keptPath;
    }

    private function createRenderDirectory(): string
    {
        $base = (string) config('latex.working_directory', storage_path('app/latex'));
        $directory = rtrim($base, '/').'/'.Str::uuid()->toString();

        // 0700: these documents contain a person's full employment history and
        // contact details, and on a shared host the default 0755 would make them
        // world-readable for the life of the render.
        File::ensureDirectoryExists($directory, 0700);

        return $directory;
    }

    /**
     * A filesystem-safe base name for the `.tex`/`.pdf` pair, derived from the
     * candidate's name so a downloaded PDF is recognisable.
     *
     * This is content-derived, therefore hostile until proven otherwise. The
     * value is reduced to an ASCII slug — every separator, dot and shell
     * metacharacter is gone, so `../../etc/cron.d/x` cannot survive it — then
     * `basename()` is applied as a second, independent guard, and an empty result
     * falls back to a literal. The length cap keeps the full path clear of
     * `NAME_MAX` once the uuid directory and extensions are added.
     *
     * The kind is part of the name because both documents are generated for the
     * same person for the same listing, and a file called `ada_lovelace.pdf`
     * holding a cover letter is wrong twice over: it is indistinguishable from
     * the resume in a downloads folder, and it collides with it in the kept-PDF
     * directory for anyone who does not rely on the uuid prefix. Resumes keep the
     * bare slug they have always had — that name is user-visible and there is no
     * reason to churn it — so the suffix is empty for that case rather than
     * `_resume`.
     */
    private function documentBaseName(array $content, TailoredDocumentType $type): string
    {
        $name = is_string($content['name'] ?? null) ? $content['name'] : '';
        $slug = Str::slug(Str::ascii($name), '_');
        $slug = basename(substr($slug, 0, 60));

        // Underscored, unlike TailoredDocumentStorage's hyphenated S3 base names:
        // these are LaTeX input filenames, and the slug is already underscored.
        [$suffix, $fallback] = match ($type) {
            TailoredDocumentType::Resume => ['', 'resume'],
            TailoredDocumentType::CoverLetter => ['_cover_letter', 'cover_letter'],
        };

        return $slug === '' ? $fallback : $slug.$suffix;
    }
}
