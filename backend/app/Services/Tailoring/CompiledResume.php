<?php

namespace App\Services\Tailoring;

use App\Services\Latex\RenderedDocument;

/**
 * A tailoring run that ended in a PDF (Requirements 6.1, 6.2, 6.4): the content
 * the model produced, the document it compiled to, and what the loop cost to
 * get there.
 *
 * Both halves are carried rather than just the PDF path, because the two callers
 * downstream want different halves and neither wants to re-derive the other. The
 * fabrication guard (task 11.5) diffs {@see $tailored}'s facts against the
 * rendered source; the persistence step (task 11.7) uploads
 * {@see RenderedDocument::$pdfPath} and stores the provenance from
 * {@see $tailored}. Handing back only a path would push both into calling the
 * renderer again.
 *
 * Named for what it is rather than `TailoredDocument`, which is the name of the
 * *table* (design.md §"Data model") and therefore of the Eloquent model task
 * 11.6 introduces. This class is not that: it is immutable, has no identity, and
 * exists before anything has been persisted.
 */
final class CompiledResume
{
    /**
     * @param  int  $compileAttempts  renders this took; > 1 means the corrective loop ran
     * @param  array<int, string>  $compileErrors  the engine's complaint from each failed render
     *                                             that preceded the successful one, in order.
     *                                             Non-empty on a run that recovered, and worth
     *                                             logging even though the outcome was a success —
     *                                             a template that needs two goes at every third
     *                                             resume is a bug somebody should see.
     */
    public function __construct(
        public readonly TailoredResumeContent $tailored,
        public readonly RenderedDocument $document,
        public readonly string $templateKey,
        public readonly int $compileAttempts = 1,
        public readonly array $compileErrors = [],
    ) {}

    /** Local path to the compiled PDF, pre-S3-upload. */
    public function pdfPath(): string
    {
        // Non-null by construction: this class is only built from a successful
        // RenderedDocument, and the failure path throws instead.
        return (string) $this->document->pdfPath;
    }

    /** The LaTeX source that compiled — what task 11.5 scans for fabrication. */
    public function texSource(): string
    {
        return $this->document->texSource;
    }

    /** @return array<string, mixed> Log-friendly summary; never the whole source or raw output. */
    public function context(): array
    {
        return [
            'template_key' => $this->templateKey,
            'compile_attempts' => $this->compileAttempts,
            'recovered_compile_errors' => count($this->compileErrors),
        ] + $this->tailored->context();
    }
}
