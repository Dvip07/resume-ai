<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * Leftover from the pre-Laravel-11 skeleton, and NOT the live console kernel.
 *
 * This app bootstraps through `bootstrap/app.php`, which binds the framework's
 * own console kernel; nothing resolves this class, so neither `schedule()` nor
 * `commands()` here ever runs. It is kept only because the legacy
 * `getDeniMax*`/`sendQueryMail` commands reference the old layout.
 *
 * The real schedule lives in {@see ScheduleDefinition}, wired up from
 * `routes/console.php`. Add scheduled commands there — adding them below looks
 * right and silently does nothing. `php artisan schedule:list` is the check.
 */
class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\getDeniMaxPO::class,
    ];

    /**
     * @deprecated Not called. See ScheduleDefinition.
     */
    protected function schedule(Schedule $schedule): void
    {
        //
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
