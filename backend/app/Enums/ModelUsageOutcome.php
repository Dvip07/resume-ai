<?php

namespace App\Enums;

/**
 * Whether a single OpenRouter attempt recorded in `model_usage_logs` answered
 * or failed (Requirements 4.5, 4.6).
 *
 * The DB column is a portable `string` (see
 * 2026_09_04_110928_create_model_usage_logs_table), so this enum is the single
 * place the allowed values live.
 */
enum ModelUsageOutcome: string
{
    /** The model returned usable content; tokens and cost are real figures. */
    case Success = 'success';

    /** The attempt failed; `error`/`http_status` carry the reason. */
    case Failure = 'failure';
}
