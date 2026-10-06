<?php

namespace App\Services\Tailoring;

use App\Enums\FabricationFindingType;

/**
 * The result of one fabrication inspection (Requirement 6.6): every unsupported
 * claim found, plus what the guard was actually able to check.
 *
 * ## Why this reports rather than acts
 *
 * There is no `pipeline_stage` write anywhere in this class or in
 * {@see FabricationGuard}, on purpose. The stage transition belongs to whoever
 * owns the run — the queued `TailorResume` job of task 11.7 — for two reasons:
 * it is the only place that knows the listing, the retry state and whether
 * auto-apply was even in play, and a guard that mutated a row could not be run
 * twice on the same document (from a job, and again from a test or an operator
 * command) without side effects. So this object is inert: it answers
 * {@see isClean()} and hands over {@see flags()} for the caller to store on
 * `tailored_documents.fabrication_flags` and to act on.
 *
 * ## Why {@see $datesVerifiable} is on the report
 *
 * A clean report has two very different meanings and a boolean alone hides the
 * difference. `user_profiles.experience[].duration` is a *month count* whenever
 * the resume parser wrote it, so the date string the document carries is often
 * "2 yr 6 mo" and pins no calendar year at all. Against such a profile the guard
 * has nothing to diff a year against and says so here instead of silently
 * reporting "no date problems found" — which a reviewer would read as "the dates
 * were checked and are fine".
 */
final class FabricationReport
{
    /**
     * @param  array<int, FabricationFinding>  $findings  in the order found: document-level
     *                                                    facts first, then the summary, the headline
     *                                                    and the bullets
     * @param  bool  $datesVerifiable  whether the profile supplied any calendar year for
     *                                 the year checks to be diffed against. False means the
     *                                 date detector was inert, not that it passed.
     * @param  bool  $texScanned  whether the rendered LaTeX source was available and
     *                            checked for missing profile facts
     * @param  int  $truncated  findings dropped by `pipeline.fabrication.max_findings`
     */
    public function __construct(
        public readonly array $findings = [],
        public readonly bool $datesVerifiable = false,
        public readonly bool $texScanned = false,
        public readonly int $truncated = 0,
    ) {}

    /** Nothing to review: the document claims nothing the profile does not. */
    public function isClean(): bool
    {
        return $this->findings === [];
    }

    /**
     * Should the caller move the run to
     * {@see \App\Enums\PipelineStage::NeedsReview}?
     *
     * Currently "any finding at all", which is what Requirement 6.6 asks for and
     * what the design's structural constraint earns: because employer, title and
     * date tokens have exactly one source, a finding is a real mismatch rather
     * than a low-confidence score, so there is no threshold to tune. Named
     * separately from {@see isClean()} anyway, so that if a class of advisory
     * finding is ever added, the callers of task 11.7 do not each have to be
     * taught which types block.
     */
    public function requiresReview(): bool
    {
        return ! $this->isClean();
    }

    /** @return array<int, FabricationFinding> */
    public function ofType(FabricationFindingType $type): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (FabricationFinding $finding): bool => $finding->type === $type,
        ));
    }

    public function has(FabricationFindingType $type): bool
    {
        return $this->ofType($type) !== [];
    }

    /** @return array<int, string> The offending tokens, in order found. */
    public function tokens(): array
    {
        return array_map(fn (FabricationFinding $finding): string => $finding->token, $this->findings);
    }

    /**
     * The JSON stored on `tailored_documents.fabrication_flags`.
     *
     * The metadata travels with the findings rather than being left in a log,
     * because `"findings": []` on its own is ambiguous for the reason given in
     * the class docblock — six months later nobody can tell whether the dates
     * were checked.
     *
     * @return array<string, mixed>
     */
    public function flags(): array
    {
        return [
            'clean' => $this->isClean(),
            'dates_verifiable' => $this->datesVerifiable,
            'tex_scanned' => $this->texScanned,
            'truncated' => $this->truncated,
            'findings' => array_map(
                fn (FabricationFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }

    /** @return array<string, mixed> Log-friendly: counts and tokens, not whole documents. */
    public function context(): array
    {
        $counts = [];

        foreach (FabricationFindingType::cases() as $type) {
            $counts[$type->value] = count($this->ofType($type));
        }

        return [
            'fabrication_clean' => $this->isClean(),
            'fabrication_findings' => count($this->findings),
            'fabrication_counts' => $counts,
            'fabrication_tokens' => $this->tokens(),
            'dates_verifiable' => $this->datesVerifiable,
            'tex_scanned' => $this->texScanned,
        ];
    }

    /**
     * One sentence for the review item's call-to-action (Requirement 9.4.3).
     *
     * Empty string on a clean report, so a caller can use it as the reason
     * without first asking whether there is one.
     */
    public function reviewReason(): string
    {
        if ($this->isClean()) {
            return '';
        }

        $parts = [];

        foreach ($this->findings as $finding) {
            $parts[] = sprintf('%s ("%s" in %s)', $finding->type->label(), $finding->token, $finding->location);
        }

        return 'The tailored resume needs review: '.implode('; ', $parts).'.';
    }
}
