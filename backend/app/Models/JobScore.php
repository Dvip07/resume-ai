<?php

namespace App\Models;

use App\Enums\RecommendedAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

/**
 * One scoring attempt for one (job listing, user) pair, written by the
 * two-prompt pipeline of Requirement 5.4 so a rating is auditable and never
 * has to be recomputed.
 *
 * Attempts are versioned by `attempt_number` and never overwritten
 * (Requirement 5.6) — a re-score inserts a new row, so `latestAttempt()` is
 * how callers get the current verdict.
 *
 * @property int $job_listing_id
 * @property int $user_id
 * @property int $attempt_number
 * @property array<string, mixed> $fit_analysis
 * @property int $stars
 * @property string $rationale
 * @property RecommendedAction $recommended_action
 * @property string $raw_prompt_1_output
 * @property string $raw_prompt_2_output
 */
class JobScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_listing_id',
        'user_id',
        'attempt_number',
        'fit_analysis',
        'stars',
        'rationale',
        'recommended_action',
        'raw_prompt_1_output',
        'raw_prompt_2_output',
    ];

    protected $casts = [
        'job_listing_id' => 'integer',
        'user_id' => 'integer',
        'attempt_number' => 'integer',
        'fit_analysis' => 'array',
        'stars' => 'integer',
        'recommended_action' => RecommendedAction::class,
    ];

    public function jobListing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * All attempts for one (listing, user) pair, newest first — the read side
     * of the versioning in Requirement 5.6.
     */
    public function scopeForPair(Builder $query, int $jobListingId, int $userId): Builder
    {
        return $query
            ->where('job_listing_id', $jobListingId)
            ->where('user_id', $userId)
            ->orderByDesc('attempt_number');
    }

    /** The current verdict for a pair, or null if it has never been scored. */
    public static function latestAttempt(int $jobListingId, int $userId): ?self
    {
        return static::query()->forPair($jobListingId, $userId)->first();
    }

    /**
     * The `attempt_number` a new score for this pair should use. Read by the
     * scoring job; the caller is responsible for not racing itself, since two
     * concurrent scorings of the same pair would both read the same number.
     *
     * Prefer {@see self::createNextAttempt()}, which closes that race.
     */
    public static function nextAttemptNumber(int $jobListingId, int $userId): int
    {
        return (int) static::query()
            ->where('job_listing_id', $jobListingId)
            ->where('user_id', $userId)
            ->max('attempt_number') + 1;
    }

    /**
     * Insert the next attempt for a pair, safe against a concurrent insert.
     *
     * `nextAttemptNumber()` is a read-then-write, so two workers scoring the
     * same pair at once both read N and both try to write attempt N+1. The
     * unique index on (job_listing_id, user_id, attempt_number) turns the loser
     * of that race into a constraint violation instead of a duplicate row, and
     * this method answers it the only way that keeps Requirement 5.6: re-read
     * the highest attempt and insert again one above it. Nothing is discarded —
     * both verdicts end up stored, in the order they landed.
     *
     * Retrying is bounded because each collision means a competing insert
     * *succeeded*, so the number strictly advances; the limit only guards
     * against a violation that is not actually an attempt collision (a broken
     * FK, say), which is rethrown once the attempts run out.
     *
     * @param array<string, mixed> $attributes everything except the three
     *                                         identity columns, which this
     *                                         method owns
     */
    public static function createNextAttempt(int $jobListingId, int $userId, array $attributes, int $attempts = 3): self
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return static::create($attributes + [
                    'job_listing_id' => $jobListingId,
                    'user_id' => $userId,
                    'attempt_number' => static::nextAttemptNumber($jobListingId, $userId),
                ]);
            } catch (QueryException $e) {
                if ($attempt >= $attempts || ! static::isAttemptCollision($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Was this a lost race for an `attempt_number`, rather than some other
     * write failure? Matched on SQLSTATE 23xxx (integrity constraint) plus the
     * driver's own wording, since MySQL, Postgres and SQLite each phrase a
     * unique violation differently and only the SQLSTATE class is portable.
     */
    protected static function isAttemptCollision(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        if (! str_starts_with($state, '23')) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'job_scores_listing_user_attempt_unique');
    }

    /** Did prompt 2 rate this at or above the given auto-apply threshold? */
    public function meetsThreshold(int $threshold): bool
    {
        return $this->stars >= $threshold;
    }
}
