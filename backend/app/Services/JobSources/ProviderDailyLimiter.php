<?php

namespace App\Services\JobSources;

use App\Models\ProviderUsage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The per-source daily fetch budget of Requirement 3.6.
 *
 * {@see JobDiscoveryOrchestrator} asks this before every provider call, so a
 * source that has spent its allowance is skipped for the rest of the day rather
 * than firing requests that cost money or earn a throttle/ban. Counters live in
 * `provider_usage`, one row per (provider, user, day).
 *
 * ## Where a limit comes from
 *
 * `config('services.<provider_key>.daily_limit')`, as design.md specifies, so a
 * source's quota sits next to its credentials and endpoint rather than in a
 * second place. A provider whose config block is named something else than its
 * key (`llm_search` reads `services.llm_job_search`) declares the path in
 * `config/job_sources.php` under `limit_config`.
 *
 * A missing, null or non-positive limit means **unlimited**: sources with no
 * quota to manage (the public ATS boards) must not be silenced by an accidental
 * default, and a zero would be indistinguishable from "not configured". Use
 * `enabled => false` to turn a source off.
 *
 * ## Counting attempts, not successes
 *
 * `attempt()` increments before the call and never decrements. A provider that
 * throws still consumed a request as far as the API's quota is concerned, and a
 * source failing in a loop is exactly what a budget should contain.
 */
class ProviderDailyLimiter
{
    /**
     * Reserve one call for `$providerKey` today, returning false if the budget
     * is already spent (in which case nothing is counted — a refused call was
     * never made).
     */
    public function attempt(string $providerKey, ?int $userId = null): bool
    {
        $limit = $this->limitFor($providerKey);

        if ($limit === null) {
            return true;
        }

        $row = $this->counter($providerKey, $userId);

        if ($row->call_count >= $limit) {
            return false;
        }

        // Atomic: two concurrent runs both read the same count, and the SQL
        // increment keeps the total honest instead of one overwriting the
        // other. It can overshoot the limit by the number of racing runs,
        // which is the cheap trade — the alternative is locking a row on
        // every discovery call.
        $affected = ProviderUsage::query()
            ->whereKey($row->getKey())
            ->where('call_count', '<', $limit)
            ->update([
                'call_count' => DB::raw('call_count + 1'),
                'updated_at' => now(),
            ]);

        return $affected > 0;
    }

    /**
     * The configured daily allowance, or null when the source is unlimited.
     */
    public function limitFor(string $providerKey): ?int
    {
        $path = config("job_sources.providers.{$providerKey}.limit_config")
            ?? "services.{$providerKey}.daily_limit";

        $limit = config($path);

        if ($limit === null || $limit === '') {
            return null;
        }

        $limit = (int) $limit;

        return $limit > 0 ? $limit : null;
    }

    /** Calls already made today. */
    public function usedToday(string $providerKey, ?int $userId = null): int
    {
        return (int) ProviderUsage::query()
            ->forWindow($providerKey, $userId, $this->today())
            ->value('call_count');
    }

    /**
     * Calls left today, or null when the source is unlimited. Never negative.
     */
    public function remaining(string $providerKey, ?int $userId = null): ?int
    {
        $limit = $this->limitFor($providerKey);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->usedToday($providerKey, $userId));
    }

    /**
     * Today's counter row, created at zero if this is the day's first call.
     *
     * The unique index makes concurrent creates race; the loser catches its own
     * constraint violation and re-reads rather than inserting a second counter,
     * which would double the allowance.
     */
    private function counter(string $providerKey, ?int $userId): ProviderUsage
    {
        $today = $this->today();

        $existing = ProviderUsage::query()
            ->forWindow($providerKey, $userId, $today)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return ProviderUsage::query()->create([
                'provider_key' => $providerKey,
                'user_id' => $userId,
                'call_count' => 0,
                'window_date' => $today->toDateString(),
            ]);
        } catch (QueryException $e) {
            $raced = ProviderUsage::query()
                ->forWindow($providerKey, $userId, $today)
                ->first();

            if ($raced === null) {
                throw $e;
            }

            return $raced;
        }
    }

    /** The day counters are keyed by, in the app timezone. */
    private function today(): Carbon
    {
        return Carbon::now()->startOfDay();
    }
}
