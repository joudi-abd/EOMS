<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Process administrative termination decisions first.
Schedule::command('contracts:process-scheduled-terminations')
    ->dailyAt('00:05')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

// Then process contracts that have reached natural expiration.
Schedule::command('contracts:process-expired')
    ->dailyAt('00:10')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

// Retry cleanup of employee documents when private storage was unavailable.
Schedule::command('documents:process-cleanups')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Refresh recent attendance; approved days retain their values and expose pending changes.
Schedule::command('attendance:recalculate')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
