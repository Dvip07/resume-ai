<?php

namespace App\Console\Commands;

use App\Exceptions\LatexEngineException;
use App\Services\Latex\LatexEngine;
use Illuminate\Console\Command;

/**
 * Deployment check for the TeX engine (Requirement 6.2).
 *
 * Requirement 6.2 compiles resumes "via a LaTeX engine available in the
 * deployment environment", which makes engine availability a property of the
 * environment rather than of the code — and therefore something a deployer needs
 * to be able to confirm without queueing a render. This is that confirmation: it
 * reports what {@see LatexEngine} resolves, and nothing else. It compiles no
 * document and writes no files.
 *
 * The exit code is the point. It is 0 only when a binary was actually found, so
 * the command is usable as a container health check or a post-deploy gate, where
 * a human reading the table is not involved.
 *
 * A fresh {@see LatexEngine} is constructed here rather than resolved from the
 * container: lookups are memoised per instance, and a diagnostic that might
 * report a cached answer from earlier in the process would defeat its own
 * purpose.
 */
class CheckLatexEngine extends Command
{
    protected $signature = 'latex:check';

    protected $description = 'Report whether the configured LaTeX engine is installed and runnable in this environment.';

    public function handle(): int
    {
        $engine = new LatexEngine();

        $available = $engine->isAvailable();

        $this->table(['Setting', 'Value'], [
            ['Configured engine', $engine->name()],
            ['Available', $available ? 'yes' : 'no'],
            // The engine returns null for "looked and found nothing"; say that
            // in words rather than printing an empty cell that reads like a
            // rendering bug.
            ['Resolved binary', $engine->binaryPath() ?? '(not found)'],
            // Null here is not a failure — version() is informational and
            // collapses every probe failure to null by design.
            ['Version', $engine->version() ?? '(unknown)'],
        ]);

        if ($available) {
            $this->info(sprintf('LaTeX engine [%s] is available.', $engine->name()));

            return self::SUCCESS;
        }

        try {
            // Deliberately provoke the exception instead of composing a message
            // here: LatexEngineException::notFound() already distinguishes a bad
            // LATEX_BINARY from a failed PATH lookup and names the env vars that
            // fix each. Duplicating that guidance would let the two copies drift.
            $engine->ensureAvailable();
        } catch (LatexEngineException $e) {
            $this->error($e->getMessage());
        }

        return self::FAILURE;
    }
}
