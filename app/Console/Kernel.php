<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

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
   * Define the application's command schedule.
   */
  protected function schedule(Schedule $schedule): void
  {
    $schedule->command('jobs:update-descriptions')->everyThirtySeconds();
    // $schedule->command('sendQueryMail:daily')->dailyAt('10:30')->timezone( 'Asia/Kolkata');
  }

  /**
   * Register the commands for the application.
   */
  protected function commands(): void
  {
    $this->load(__DIR__ . '/Commands');

    require base_path('routes/console.php');
  }
}
