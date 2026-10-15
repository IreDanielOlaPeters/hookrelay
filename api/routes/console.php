<?php

use Illuminate\Support\Facades\Schedule;

// Long-running relay processes are artisan commands, not scheduled tasks:
//   php artisan relay:dispatch   (scheduler service)
//   php artisan queue:work redis (worker service)
// Scheduled tasks below run in the `cron` service (php artisan schedule:work).

Schedule::command('relay:demo-reset')
    ->dailyAt(config('relay.demo.reset_at'))
    ->when(fn () => (bool) config('relay.demo.enabled'))
    ->withoutOverlapping()
    ->onOneServer();

// Proves the scheduler is alive; checked by the cron container's healthcheck.
Schedule::command('relay:heartbeat cron')->everyMinute();

Schedule::command('relay:prune')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();
