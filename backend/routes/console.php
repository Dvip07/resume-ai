<?php

use App\Console\ScheduleDefinition;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// The scheduled pipeline triggers (Requirements 3.1, 11.1). Defined in a class
// so the config-driven cadence is testable; see App\Console\ScheduleDefinition.
(new ScheduleDefinition)(Schedule::getFacadeRoot());
