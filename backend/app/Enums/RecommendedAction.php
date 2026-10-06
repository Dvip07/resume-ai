<?php

namespace App\Enums;

/**
 * What prompt 2 of the scoring pipeline concluded should happen to a listing
 * (Requirements 5.2, 5.3).
 *
 * The DB column is a portable `string` (see
 * 2026_09_10_080633_create_job_scores_table), so this enum is the single place
 * the allowed values live; JobScore casts `recommended_action` to it.
 *
 * This is the model's recommendation, not the final decision: the pipeline
 * still gates auto-apply on the star threshold and the user's
 * `auto_apply_enabled` setting before acting on it.
 */
enum RecommendedAction: string
{
    /** Strong enough fit to submit an application without human review. */
    case AutoApply = 'auto_apply';

    /** Keep the listing and its score, but don't apply. */
    case StoreOnly = 'store_only';

    /** The pipeline stage a listing moves to when this action is honoured. */
    public function toPipelineStage(): PipelineStage
    {
        return match ($this) {
            self::AutoApply => PipelineStage::Tailoring,
            self::StoreOnly => PipelineStage::StoreOnly,
        };
    }
}
