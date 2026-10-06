<?php

namespace App\Enums;

/**
 * Where a `job_listings` row sits in the automation pipeline (design.md
 * "Modified existing tables", Requirement 3.3).
 *
 * discovered → enriching → scored → tailoring → tailored → applying → applied
 *                                                                   ↘ failed
 * any stage → needs_review (human input required)
 * scored    → store_only  (kept, but not auto-applied to)
 *
 * The DB column is a portable `string` (see
 * 2026_09_05_002808_add_source_key_dedupe_hash_pipeline_stage_salary_to_job_listings_table),
 * so this enum is the single place the allowed values live; JobListing casts
 * `pipeline_stage` to it. Transitions themselves are enforced by the jobs that
 * own each stage (tasks 8.7, 9.4, 10.3) — this enum only defines the vocabulary
 * and which values are terminal.
 */
enum PipelineStage: string
{
    /** Row upserted by JobDiscoveryOrchestrator; nothing done to it yet. */
    case Discovered = 'discovered';

    /** Description fetch/extraction queued or running. */
    case Enriching = 'enriching';

    /** Fit analysis + rating persisted to `job_scores`. */
    case Scored = 'scored';

    /** Resume/cover-letter generation queued or running. */
    case Tailoring = 'tailoring';

    /** Documents rendered and stored. */
    case Tailored = 'tailored';

    /** An apply adapter is driving the submission. */
    case Applying = 'applying';

    /** Submission confirmed. */
    case Applied = 'applied';

    /** A stage gave up after its retries; the stage's log carries the reason. */
    case Failed = 'failed';

    /**
     * Paused for a human: an unanswered application question, a fabrication
     * flag, a CAPTCHA/login wall, or no adapter for the domain.
     */
    case NeedsReview = 'needs_review';

    /** Scored below the auto-apply threshold (or auto-apply off): kept only. */
    case StoreOnly = 'store_only';

    /**
     * No further work is queued for a row in this stage, so the pipeline will
     * not pick it up again and the frontend can stop polling it.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Applied,
            self::Failed,
            self::NeedsReview,
            self::StoreOnly,
        ], true);
    }
}
