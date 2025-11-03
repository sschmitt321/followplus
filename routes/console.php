<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule T+1 newbie rewards (daily at 00:10)
Schedule::command('rewards:grant-newbie-next-day')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->runInBackground();

// Schedule dividend dispatch (weekly on Monday at 00:00)
Schedule::command('rewards:dispatch-dividends')
    ->weeklyOn(1, '00:00')
    ->withoutOverlapping()
    ->runInBackground();

// Schedule follow window generation (daily at 00:05)
Schedule::command('follow:generate-windows')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->runInBackground();

// Schedule follow order settlement (every hour)
Schedule::command('follow:settle-orders')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Schedule market tick generation (every minute)
Schedule::command('market:generate-ticks')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
