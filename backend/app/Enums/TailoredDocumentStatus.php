<?php

namespace App\Enums;

/**
 * Where a `tailored_documents` row sits in the tailoring pipeline (design.md
 * "New tables", Requirement 6.5).
 *
 * pending → rendered
 *         ↘ failed
 *
 * The DB column is a portable `string`, so this enum is the single place the
 * allowed values live; TailoredDocument casts `status` to it.
 */
enum TailoredDocumentStatus: string
{
    /**
     * Row created before or during generation/compilation. `s3_path` is not
     * trustworthy yet.
     */
    case Pending = 'pending';

    /** Compilation succeeded and a PDF exists at `s3_path`. */
    case Rendered = 'rendered';

    /**
     * Generation or LaTeX compilation gave up after its corrective retries —
     * see App\Exceptions\ResumeTailoringException and
     * App\Exceptions\ResumeCompilationException. No PDF was stored.
     */
    case Failed = 'failed';

    /**
     * No further generation or compilation work is queued for a row in this
     * status, so the pipeline will not pick it up again and the frontend can
     * stop polling it. Both outcomes of the compile are terminal; only
     * `pending` is still in flight.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rendered,
            self::Failed,
        ], true);
    }

    /**
     * Whether a PDF can be fetched from `s3_path`. Guards download/attach
     * paths, which must not hand out a half-written or absent object — so
     * being terminal is not enough, `failed` stored nothing.
     */
    public function isUsable(): bool
    {
        return $this === self::Rendered;
    }
}
