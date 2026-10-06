<?php

namespace App\Services\Tailoring;

/**
 * The output of the tailoring step: the exact array
 * {@see \App\Services\Latex\LatexRenderService::renderResume()} takes, plus the
 * provenance a caller needs to persist and audit the run.
 *
 * The array is deliberately the *template's* data contract, documented at the
 * top of `resources/views/latex/resume.tex.blade.php`, and not a normalized
 * "tailored resume" model of its own. Introducing a second shape here would
 * mean a translation step between this class and the renderer, and a
 * translation step is exactly where a field silently stops being rendered.
 *
 * ## Why the factual fields are carried separately
 *
 * {@see $facts} holds the employer/title/date tokens that came from
 * {@see \App\Models\UserProfile}, in the order they appear in {@see $content}.
 * The fabrication guard (Requirement 6.6, task 11.5) needs the source-of-truth
 * tokens to diff the generated document against; having them here means the
 * guard reads one value object rather than re-deriving the profile projection
 * and hoping it derives it identically. Nothing in {@see $facts} ever passed
 * through a model.
 *
 * Immutable and free of Eloquent, like {@see \App\Services\Scoring\FitAnalysis}:
 * the queued `TailorResume` job (task 11.7) is what persists it, which is what
 * lets the whole tailoring flow be tested against a faked router with no
 * database.
 */
final class TailoredResumeContent
{
    /**
     * @param  array<string, mixed>  $content  the resume template's data contract
     * @param  array<int, array<string, string>>  $facts  per role, the `company`/`position`/`dates`
     *                                                    tokens taken verbatim from the profile
     * @param  array<int, string>  $droppedSkills  skills the model asked to emphasize that the
     *                                             profile does not claim; dropped rather than
     *                                             rendered, and reported for visibility
     * @param  string  $rawOutput  the model's untouched response, for auditing
     * @param  int  $attempts  model calls this took (2 = the stricter-instruction retry was used)
     */
    public function __construct(
        public readonly array $content,
        public readonly array $facts,
        public readonly array $droppedSkills,
        public readonly string $rawOutput,
        public readonly string $modelUsed,
        public readonly string $tierUsed,
        public readonly int $attempts = 1,
    ) {}

    /**
     * The array to hand to `renderResume()`.
     *
     * An accessor rather than only the public property so the call site reads as
     * what it is — `renderResume($key, $tailored->forTemplate())` — and so a
     * future template revision can be adapted here in one place.
     *
     * @return array<string, mixed>
     */
    public function forTemplate(): array
    {
        return $this->content;
    }

    /** @return array<int, string> Every bullet in the document, flattened. */
    public function bullets(): array
    {
        $bullets = [];

        foreach ($this->content['experience'] ?? [] as $role) {
            foreach ($role['bullets'] ?? [] as $bullet) {
                $bullets[] = $bullet;
            }
        }

        return $bullets;
    }

    /** @return array<string, mixed> Log-friendly summary; never the whole raw output. */
    public function context(): array
    {
        return [
            'roles' => count($this->content['experience'] ?? []),
            'bullets' => count($this->bullets()),
            'summary_chars' => mb_strlen((string) ($this->content['summary'] ?? '')),
            'dropped_skills' => $this->droppedSkills,
            'model_used' => $this->modelUsed,
            'tier_used' => $this->tierUsed,
            'attempts' => $this->attempts,
        ];
    }
}
