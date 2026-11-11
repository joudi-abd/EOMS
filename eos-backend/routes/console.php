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

// Uses each office policy date and is safe to retry.
Schedule::command('leaves:process')->everyFiveMinutes()->withoutOverlapping();

// Recurring task cards: created once the office day is about to start (an hour before the shift, office time);
// hourly runs before that do nothing, and re-runs the same day do not duplicate.
Schedule::command('tasks:recurring')->hourly()->withoutOverlapping();

// Due-soon and overdue reminders; each is sent once per due date.
Schedule::command('tasks:reminders')->hourly()->withoutOverlapping();
