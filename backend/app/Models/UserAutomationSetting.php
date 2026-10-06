<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's automation consent and throttle settings: whether the pipeline may
 * apply on their behalf (Requirement 9.1), whether that extends to LinkedIn
 * Easy Apply (Requirement 9.3), and how many submissions a day are allowed
 * (Requirement 9.7). The star threshold is the per-user override of the
 * system-wide auto-apply bar in Requirement 5.2.
 *
 * Rows are optional — a user who has never opened the settings screen has none.
 * Callers should not have to null-check for that, so {@see self::forUser()}
 * always returns a usable instance, falling back to the conservative defaults
 * in config/pipeline.php (everything off).
 *
 * @property int $user_id
 * @property bool $auto_apply_enabled
 * @property int $auto_apply_star_threshold
 * @property bool $linkedin_auto_apply_opt_in
 * @property int $daily_apply_cap
 * @property int $linkedin_daily_apply_cap
 */
class UserAutomationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'auto_apply_enabled',
        'auto_apply_star_threshold',
        'linkedin_auto_apply_opt_in',
        'daily_apply_cap',
        'linkedin_daily_apply_cap',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'auto_apply_enabled' => 'boolean',
        'auto_apply_star_threshold' => 'integer',
        'linkedin_auto_apply_opt_in' => 'boolean',
        'daily_apply_cap' => 'integer',
        'linkedin_daily_apply_cap' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * This user's settings, or an unsaved instance carrying the platform
     * defaults if they have never configured any.
     *
     * The fallback is deliberately not persisted: writing a row here would turn
     * a read (the scoring job checking a flag) into a claim that the user has
     * made a choice, and would race with the settings endpoint. Callers that
     * genuinely need a stored row should use {@see self::defaultsFor()} with
     * `updateOrCreate` on the user's own behalf.
     */
    public static function forUser(int $userId): self
    {
        return static::query()->firstWhere('user_id', $userId)
            ?? new self(self::defaultsFor($userId));
    }

    /**
     * The platform defaults for a user, as an attribute array. Every value
     * comes from config — see config/pipeline.php for the caps and consent
     * flags, and config/scoring.php for the star threshold, which the scoring
     * prompts share so the model and the pipeline judge by the same bar.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(int $userId): array
    {
        return [
            'user_id' => $userId,
            'auto_apply_enabled' => (bool) config('pipeline.auto_apply_enabled', false),
            'auto_apply_star_threshold' => (int) config('scoring.auto_apply_star_threshold', 4),
            'linkedin_auto_apply_opt_in' => (bool) config('pipeline.linkedin_auto_apply_opt_in', false),
            'daily_apply_cap' => (int) config('pipeline.daily_apply_cap', 20),
            'linkedin_daily_apply_cap' => (int) config('pipeline.linkedin_daily_apply_cap', 5),
        ];
    }

    /**
     * May a job with this rating be auto-applied for this user? Both halves of
     * Requirement 5.2's gate: the rating clears the user's bar *and* the user
     * has switched automation on. A `auto_apply` recommendation from the model
     * never substitutes for the toggle.
     */
    public function allowsAutoApplyAt(int $stars): bool
    {
        return $this->auto_apply_enabled && $stars >= $this->auto_apply_star_threshold;
    }

    /**
     * The per-day submission cap for one apply target. LinkedIn is capped
     * separately and more tightly (Requirements 9.3, 9.7).
     */
    public function dailyCapFor(bool $isLinkedIn): int
    {
        return $isLinkedIn ? $this->linkedin_daily_apply_cap : $this->daily_apply_cap;
    }
}
