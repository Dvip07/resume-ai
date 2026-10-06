<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day's call count for one job source (Requirement 3.6), written by
 * {@see \App\Services\JobSources\ProviderDailyLimiter} before each provider
 * call so an exhausted source can be skipped rather than billed.
 *
 * `user_id` is nullable: a row with a user is that user's daily allowance, a
 * row without one is a platform-wide counter for a quota shared by everybody.
 *
 * @property string $provider_key
 * @property int|null $user_id
 * @property int $call_count
 * @property \Illuminate\Support\Carbon $window_date
 */
class ProviderUsage extends Model
{
    use HasFactory;

    /** Singular, per design.md's data model. */
    protected $table = 'provider_usage';

    protected $fillable = [
        'provider_key',
        'user_id',
        'call_count',
        'window_date',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'call_count' => 'integer',
        'window_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The counter row for one provider/subject/day. `$userId` null means the
     * platform-wide counter, and is matched with `whereNull` — not `where(...,
     * null)`, which no row satisfies.
     */
    public function scopeForWindow(
        Builder $query,
        string $providerKey,
        ?int $userId,
        Carbon|string $windowDate
    ): Builder {
        return $query
            ->where('provider_key', $providerKey)
            ->when(
                $userId === null,
                static fn (Builder $q) => $q->whereNull('user_id'),
                static fn (Builder $q) => $q->where('user_id', $userId)
            )
            ->whereDate('window_date', $windowDate instanceof Carbon ? $windowDate->toDateString() : $windowDate);
    }
}
