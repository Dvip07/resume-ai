<?php

namespace App\Models;

use App\Enums\ModelUsageOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One OpenRouter attempt, written by ModelRouterService::recordUsage() for
 * both successes and failures (Requirement 4.6).
 *
 * Append-only ledger: rows are never updated, so `updated_at` is disabled
 * (`const UPDATED_AT = null`) to match the `created_at`-only schema in
 * design.md.
 */
class ModelUsageLog extends Model
{
    use HasFactory;

    /** Append-only: nothing ever edits a usage row. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'task_type',
        'model_used',
        'tier_used',
        'outcome',
        'attempt',
        'input_tokens',
        'output_tokens',
        'estimated_cost_usd',
        'http_status',
        'error',
        'related_type',
        'related_id',
    ];

    protected $casts = [
        'outcome' => ModelUsageOutcome::class,
        'attempt' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        // String, not float: cost is money and gets summed across thousands of
        // rows, where binary float drift is real. Callers that want arithmetic
        // should use a decimal-aware cast or the DB.
        'estimated_cost_usd' => 'decimal:6',
        'http_status' => 'integer',
    ];

    /**
     * The job listing / resume this call was made for, when the caller knew it.
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    public function succeeded(): bool
    {
        return $this->outcome === ModelUsageOutcome::Success;
    }
}
