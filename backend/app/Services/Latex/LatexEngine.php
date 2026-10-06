<?php

namespace App\Services\Latex;

use App\Exceptions\LatexEngineException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Everything the application knows about *where* the TeX engine is and whether
 * it can be run — kept apart from anything that compiles a document
 * (Requirement 6.2).
 *
 * Why this is its own class: locating the binary is environment inspection, not
 * rendering. The render service (task 12.x) needs a path and a yes/no answer; it
 * does not need to know that `tectonic` may be resolved from PATH, that
 * `LATEX_BINARY` can override that, or that pdflatex is an acceptable
 * substitute. Isolating those rules here also means the fail-fast check has a
 * single home: {@see ensureAvailable()} is called once at the top of a render so
 * a deployment with no TeX install fails immediately, with an actionable
 * message, rather than mid-compile after the paid LLM tailoring step has already
 * run.
 *
 * Failure contract, which the rest of the class exists to uphold:
 * {@see binaryPath()}, {@see isAvailable()} and {@see version()} never throw.
 * They are the *diagnostic* surface — used by health checks, by logging around a
 * failed render, and by callers deciding whether to queue work at all — and a
 * diagnostic that explodes when the thing it diagnoses is absent is useless.
 * Only {@see ensureAvailable()} throws, and only because its caller has asked to
 * be stopped.
 *
 * Lookups are memoised per instance. Resolving a binary means touching the
 * filesystem once per PATH entry, and this is registered as a singleton in
 * practice, so a worker that renders many documents pays for it once. The
 * memo is deliberately per-instance rather than static: tests (and an artisan
 * command re-reading config) get a fresh instance and therefore a fresh answer.
 */
class LatexEngine
{
    /**
     * Resolved path, or null for "looked and found nothing".
     *
     * Kept separate from {@see $resolved} because null is a meaningful,
     * cacheable result here — without the flag, a negative lookup would be
     * repeated on every call, which is the common case on a host with no TeX.
     */
    private ?string $binaryPath = null;

    private bool $resolved = false;

    /** Cached `--version` output; null is again a valid cached answer. */
    private ?string $version = null;

    private bool $versionResolved = false;

    /**
     * Seconds allowed for the `--version` probe.
     *
     * Intentionally not `config('latex.timeout')`: that budget is for compiling
     * a document, and a version banner that has not printed in a few seconds
     * means the process is wedged, not busy. A short cap keeps a health check
     * from hanging on it.
     */
    private const VERSION_TIMEOUT = 5;

    /** The configured engine name, e.g. `tectonic` or `pdflatex`. */
    public function name(): string
    {
        return (string) config('latex.engine', 'tectonic');
    }

    /**
     * Absolute path to the engine binary, or null when it cannot be found.
     *
     * An explicit `LATEX_BINARY` wins, but only if it is actually an executable
     * file — a stale or mistyped path silently falling back to a PATH lookup
     * would hide the misconfiguration and make the eventual error describe the
     * wrong problem, so a set-but-unusable value resolves to null and lets
     * {@see LatexEngineException::notFound()} name it.
     *
     * The PATH search goes through Symfony's {@see ExecutableFinder}, which
     * walks PATH itself. No shell is involved and the engine name is never
     * interpolated into a command string, so a hostile config value cannot turn
     * this into command execution.
     */
    public function binaryPath(): ?string
    {
        if ($this->resolved) {
            return $this->binaryPath;
        }

        $this->resolved = true;
        $this->binaryPath = $this->resolveBinaryPath();

        return $this->binaryPath;
    }

    /** Whether a compile could be attempted at all. */
    public function isAvailable(): bool
    {
        return $this->binaryPath() !== null;
    }

    /**
     * The engine's version banner (first line only), or null if it cannot be
     * obtained.
     *
     * Recorded alongside a render so an output that changes between deployments
     * can be traced to an engine upgrade rather than to the template or the
     * model. That makes it purely informational — every failure mode collapses
     * to null, including a missing binary, a non-zero exit, a timeout, and an
     * engine whose flag is spelled differently. It must never be the reason a
     * render or a health check fails.
     */
    public function version(): ?string
    {
        if ($this->versionResolved) {
            return $this->version;
        }

        $this->versionResolved = true;
        $this->version = $this->probeVersion();

        return $this->version;
    }

    /**
     * Assert the engine can be run, or fail with a message a deployer can act
     * on.
     *
     * Called by the render service before it does anything expensive. The point
     * is ordering: a missing engine is knowable up front, so it should surface
     * before tailoring content, not after.
     *
     * @throws LatexEngineException when no usable binary was found
     */
    public function ensureAvailable(): void
    {
        if ($this->isAvailable()) {
            return;
        }

        throw LatexEngineException::notFound($this->name(), $this->configuredBinary());
    }

    /** The explicit override, normalised so a blank env value reads as "unset". */
    private function configuredBinary(): ?string
    {
        $binary = config('latex.binary');

        if (! is_string($binary) || trim($binary) === '') {
            return null;
        }

        return trim($binary);
    }

    private function resolveBinaryPath(): ?string
    {
        $configured = $this->configuredBinary();

        if ($configured !== null) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        $name = trim($this->name());

        // Guard against an empty LATEX_ENGINE: ExecutableFinder would otherwise
        // be asked to find "", and any answer to that is wrong.
        if ($name === '') {
            return null;
        }

        return (new ExecutableFinder())->find($name);
    }

    private function probeVersion(): ?string
    {
        $binary = $this->binaryPath();

        if ($binary === null) {
            return null;
        }

        try {
            // Array form, so the path is passed as a single argv entry and is
            // never parsed by a shell.
            $process = new Process([$binary, '--version']);
            $process->setTimeout(self::VERSION_TIMEOUT);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            // Some engines print the banner to stderr; take whichever produced
            // output. Only the first line is useful — pdflatex follows it with
            // several lines of copyright and format detail.
            $output = trim($process->getOutput());

            if ($output === '') {
                $output = trim($process->getErrorOutput());
            }

            if ($output === '') {
                return null;
            }

            $firstLine = trim(strtok($output, "\n") ?: '');

            return $firstLine === '' ? null : $firstLine;
        } catch (\Throwable) {
            // A probe is not worth failing over: a timeout, a binary that is not
            // really executable, or a platform with process control disabled all
            // mean the same thing here — no version to report.
            return null;
        }
    }
}
