<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/**
 * The application's command schedule (Requirements 3.1, 11.1).
 *
 * A class rather than inline closures in `routes/console.php` for one reason:
 * the cadence is config-driven, and the console routes file is loaded once
 * during console bootstrap — before a test can set config. Keeping the
 * definition behind an invokable lets it be re-run against a fresh Schedule
 * with different config, which is what makes "the cadence really does come
 * from config" testable rather than assumed.
 *
 * Note this is *not* `App\Console\Kernel`. This app is on the Laravel 11+
 * bootstrap (`bootstrap/app.php`), where the framework's own console kernel is
 * used and an application `Kernel` class is never resolved — a `schedule()`
 * method there silently never runs.
 */
class ScheduleDefinition
{
    public function __invoke(Schedule $schedule): void
    {
        $this->scheduleDiscovery($schedule);
    }

    /**
     * Discovery fan-out. `jobs:discover` only queues one DiscoverJobsForUser
     * per candidate user and returns, so the tick is cheap; the per-source
     * daily budgets (ProviderDailyLimiter) are what bound API spend, which is
     * why hourly is a safe default.
     */
    private function scheduleDiscovery(Schedule $schedule): void
    {
        if (! config('pipeline.discovery.enabled', true)) {
            return;
        }

        $cron = (string) config('pipeline.discovery.cron', '0 * * * *');

        $schedule->command('jobs:discover')
            ->cron($cron)
            ->timezone((string) config('pipeline.discovery.timezone', config('app.timezone', 'UTC')))
            // Guards the dispatch loop: a run still enumerating users when the
            // next tick arrives is skipped, not doubled. Overlapping
            // *discovery* is separately impossible — DiscoverJobsForUser is
            // ShouldBeUnique per user.
            ->withoutOverlapping()
            // Multi-host deployments run schedule:run everywhere; without this
            // every host fans out the same users on the same tick.
            ->onOneServer()
            // The scheduler's minute shouldn't be spent waiting on the loop.
            ->runInBackground()
            ->onFailure(function () use ($cron) {
                Log::error('Scheduled job discovery failed to dispatch.', [
                    'command' => 'jobs:discover',
                    'cron' => $cron,
                ]);
            });
    }
}
