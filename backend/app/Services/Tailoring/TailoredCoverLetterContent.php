<?php

namespace App\Services\Tailoring;

/**
 * The output of cover-letter tailoring: the exact array
 * {@see \App\Services\Latex\LatexRenderService::renderCoverLetter()} takes, plus
 * the provenance a caller needs to persist and audit the run.
 *
 * Exact counterpart of {@see TailoredResumeContent}, and for the same reasons.
 * The array is the *template's* data contract, documented at the top of
 * `resources/views/latex/cover-letter.tex.blade.php`, rather than a normalized
 * "cover letter" model of its own — a second shape would need a translation step
 * on the way to the renderer, and a translation step is where a field quietly
 * stops being rendered.
 *
 * ## Why the factual fields are carried separately
 *
 * {@see $facts} holds the tokens that came from the listing and the profile
 * rather than from a model: the candidate's name, the company, the job title,
 * the date and any recipient a caller supplied. The fabrication guard
 * (Requirement 6.6, task 11.5) needs source-of-truth tokens to diff a generated
 * document against, and a cover letter is the *more* dangerous of the two
 * documents to leave unchecked — its output is running prose, so a wrong company
 * name reads as a sentence rather than as a field. Nothing in {@see $facts} ever
 * passed through a model.
 *
 * Immutable and free of Eloquent: the queued `TailorCoverLetter` job (task
 * 12.3's final slice) is what persists this, which is what lets the whole flow
 * be tested against a faked router with no database and no TeX engine.
 */
final class TailoredCoverLetterContent
{
    /**
     * @param  array<string, mixed>  $content  the cover-letter template's data contract
     * @param  array<string, mixed>  $facts  the name/company/job-title/date tokens taken
     *                                       verbatim from the listing, the profile and the
     *                                       caller-supplied recipient
     * @param  string  $rawOutput  the model's untouched response, for auditing
     * @param  int  $attempts  model calls this took (2 = the stricter-instruction retry was used)
     */
    public function __construct(
        public readonly array $content,
        public readonly array $facts,
        public readonly string $rawOutput,
        public readonly string $modelUsed,
        public readonly string $tierUsed,
        public readonly int $attempts = 1,
    ) {}

    /**
     * The array to hand to `renderCoverLetter()`.
     *
     * An accessor rather than only the public property, so the call site reads
     * as what it is — `renderCoverLetter($key, $letter->forTemplate())` — and so
     * a future template revision can be adapted in one place.
     *
     * @return array<string, mixed>
     */
    public function forTemplate(): array
    {
        return $this->content;
    }

    /** @return array<int, string> The body paragraphs, in order. */
    public function paragraphs(): array
    {
        $paragraphs = $this->content['paragraphs'] ?? [];

        return is_array($paragraphs) ? array_values($paragraphs) : [];
    }

    /** The whole body as one string — what a fabrication guard reads. */
    public function bodyText(): string
    {
        return implode("\n\n", $this->paragraphs());
    }

    /** @return array<string, mixed> Log-friendly summary; never the whole raw output. */
    public function context(): array
    {
        return [
            'paragraphs' => count($this->paragraphs()),
            'body_chars' => mb_strlen($this->bodyText()),
            'has_recipient_name' => ($this->content['recipient_name'] ?? null) !== null,
            'model_used' => $this->modelUsed,
            'tier_used' => $this->tierUsed,
            'attempts' => $this->attempts,
        ];
    }
}
