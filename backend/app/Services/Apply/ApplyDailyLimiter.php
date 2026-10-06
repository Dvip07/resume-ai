<?php

namespace App\Services\Apply;

use App\Models\ProviderUsage;
use App\Models\UserAutomationSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Requirement 9.7: how many applications one user may have submitted on our
 * behalf in a day, in total and on one platform.
 *
 * Two caps, both enforced here, because they answer different questions:
 *
 *  - the **global** cap (`user_automation_settings.daily_apply_cap`, falling
 *    back to `pipeline.daily_apply_cap`) is the user's own appetite: twenty
 *    applications a day is a busy human, two hundred is a bot and gets the
 *    account flagged wherever it lands.
 *  - the **per-platform** cap (`apply.adapters.{key}.daily_cap`) is the ATS's
 *    tolerance, which is not the user's to raise. Thirty Greenhouse boards in a
 *    day is unremarkable; thirty LinkedIn Easy Applies is a rate-limit, so each
 *    adapter carries its own ceiling and LinkedIn's is deliberately the
 *    strictest (Requirement 9.3).
 *
 * A platform cap is optional: an adapter block with no `daily_cap` is governed
 * by the global cap alone.
 *
 * ## Why this reuses the `provider_usage` table
 *
 * The question "how many of X has this user spent today, and is that under the
 * limit" is exactly the one {@see \App\Services\JobSources\ProviderDailyLimiter}
 * already answers against `provider_usage`, down to the unique
 * (provider_key, user_id, window_date) key that makes the reservation atomic
 * and the daily reset free. A second table with the same three columns would
 * buy nothing but a second migration to keep in step, so apply counters live in
 * the same table.
 *
 * What keeps the two from colliding is the key namespace: every counter written
 * here is prefixed `apply:` (`apply:greenhouse`, and `apply:_global` for the
 * user-wide cap), which no job-source provider key can produce. The `_global`
 * leading underscore is likewise not a legal adapter key, so the global counter
 * cannot be shadowed by a platform of the same name.
 *
 * ## Reserve, don't check-then-act
 *
 * {@see attempt()} takes the slot before the caller drives anything, under the
 * same `where('call_count', '<', $limit)` guard the provider limiter uses, so
 * two workers racing on the same user cannot both be told yes. When a cap is
 * spent nothing is counted — a blocked attempt must not consume the budget it
 * was refused, or a capped user would never recover.
 *
 * Both caps are reserved together: if the platform slot is refused after the
 * global slot was taken, the global reservation is handed back, so a refusal
 * costs exactly zero on both counters.
 */
class ApplyDailyLimiter
{
    /**
     * Counter key for the user-wide cap. Not a legal adapter key (adapters are
     * registered under bare vendor names), so it cannot be shadowed.
     */
    public const GLOBAL_KEY = 'apply:_global';

    /** Namespace every apply counter shares, keeping it clear of provider keys. */
    private const PREFIX = 'apply:';

    /**
     * Reserve one application for `$userId` on `$adapterKey`, or refuse.
     *
     * @return bool true when the caller may go ahead and submit; false when
     *              either cap is spent, in which case nothing was counted.
     */
    public function attempt(string $adapterKey, int $userId): bool
    {
        $globalLimit = $this->globalLimitFor($adapterKey, $userId);
        $platformLimit = $this->platformLimitFor($adapterKey);

        // A cap of zero (or a nonsensical negative one) is an off switch, and
        // is answered without touching the counters at all.
        if ($globalLimit <= 0 || ($platformLimit !== null && $platformLimit <= 0)) {
            return false;
        }

        if (! $this->reserve(self::GLOBAL_KEY, $userId, $globalLimit)) {
            return false;
        }

        if ($platformLimit !== null && ! $this->reserve($this->keyFor($adapterKey), $userId, $platformLimit)) {
            // Hand the global slot back: a refused attempt costs nothing.
            $this->release(self::GLOBAL_KEY, $userId);

            return false;
        }

        return true;
    }

    /**
     * The user's global cap for an attempt on `$adapterKey`.
     *
     * LinkedIn gets its own, lower per-user number
     * ({@see UserAutomationSetting::dailyCapFor()}) — it is the account most at
     * risk and the one we cannot replace for the user.
     */
    public function globalLimitFor(string $adapterKey, int $userId): int
    {
        return UserAutomationSetting::forUser($userId)->dailyCapFor($this->isLinkedIn($adapterKey));
    }

    /**
     * The platform's own ceiling, or null when the adapter declares none.
     */
    public function platformLimitFor(string $adapterKey): ?int
    {
        $limit = config('apply.adapters.'.$adapterKey.'.daily_cap');

        return is_numeric($limit) ? (int) $limit : null;
    }

    /**
     * Applications counted against `$adapterKey` today, or against the global
     * cap when `$adapterKey` is null.
     */
    public function usedToday(?string $adapterKey, int $userId): int
    {
        $key = $adapterKey === null ? self::GLOBAL_KEY : $this->keyFor($adapterKey);

        return (int) ProviderUsage::query()
            ->where('provider_key', $key)
            ->where('user_id', $userId)
            ->whereDate('window_date', $this->today())
            ->value('call_count');
    }

    /**
     * How many more applications this user may submit on `$adapterKey` today —
     * the lower of what the two caps allow, never below zero.
     */
    public function remaining(string $adapterKey, int $userId): int
    {
        $remaining = max(0, $this->globalLimitFor($adapterKey, $userId) - $this->usedToday(null, $userId));

        $platformLimit = $this->platformLimitFor($adapterKey);

        if ($platformLimit !== null) {
            $remaining = min($remaining, max(0, $platformLimit - $this->usedToday($adapterKey, $userId)));
        }

        return $remaining;
    }

    /** The counter key for one adapter. */
    public function keyFor(string $adapterKey): string
    {
        return self::PREFIX.$adapterKey;
    }

    /**
     * Take one slot on a single counter, atomically. False means the limit was
     * already reached and nothing was written.
     */
    protected function reserve(string $key, int $userId, int $limit): bool
    {
        $counter = $this->counter($key, $userId);

        return ProviderUsage::query()
            ->whereKey($counter->getKey())
            ->where('call_count', '<', $limit)
            ->update(['call_count' => DB::raw('call_count + 1')]) === 1;
    }

    /** Give a reserved slot back. Guarded so it can never go negative. */
    protected function release(string $key, int $userId): void
    {
        ProviderUsage::query()
            ->where('provider_key', $key)
            ->where('user_id', $userId)
            ->whereDate('window_date', $this->today())
            ->where('call_count', '>', 0)
            ->update(['call_count' => DB::raw('call_count - 1')]);
    }

    /**
     * Today's counter row, created at zero if this is the user's first attempt.
     *
     * The unique (provider_key, user_id, window_date) index is what makes this
     * safe under concurrency: two workers may both try to create the row, one
     * loses on the index, and the loser re-reads the winner's row rather than
     * inventing a second counter.
     */
    protected function counter(string $key, int $userId): ProviderUsage
    {
        $attributes = [
            'provider_key' => $key,
            'user_id' => $userId,
            'window_date' => $this->today(),
        ];

        try {
            return ProviderUsage::query()->firstOrCreate($attributes, ['call_count' => 0]);
        } catch (QueryException $e) {
            $existing = ProviderUsage::query()->where($attributes)->first();

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * LinkedIn is recognised from the adapter key rather than from a class
     * check, so the stricter cap is already in force when task 15.9 registers
     * the adapter.
     */
    protected function isLinkedIn(string $adapterKey): bool
    {
        return str_contains(strtolower($adapterKey), 'linkedin');
    }

    /**
     * The current window, as the start of the local day — so it resets at the
     * operator's midnight.
     *
     * A Carbon instance rather than a `Y-m-d` string on purpose: `window_date`
     * is cast to a datetime, so a bare date string binds as `2026-10-03` and
     * never equals the stored `2026-10-03 00:00:00`. The row would be missed on
     * read and re-inserted on write, straight into the unique index.
     */
    protected function today(): Carbon
    {
        return Carbon::now()->startOfDay();
    }
}
