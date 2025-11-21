<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// // Schedule T+1 newbie rewards (daily at 00:10)
// Schedule::command('rewards:grant-newbie-next-day')
//     ->dailyAt('00:10')
//     ->withoutOverlapping()
//     ->runInBackground();

// // Schedule dividend dispatch (weekly on Monday at 00:00)
// Schedule::command('rewards:dispatch-dividends')
//     ->weeklyOn(1, '00:00')
//     ->withoutOverlapping()
//     ->runInBackground();

// // Schedule follow window generation (daily at 00:05)
// Schedule::command('follow:generate-windows')
//     ->dailyAt('00:05')
//     ->withoutOverlapping()
//     ->runInBackground();

// // Schedule market tick generation (every minute)
// Schedule::command('market:generate-ticks')
//     ->everyMinute()
//     ->withoutOverlapping()
//     ->runInBackground();

// Schedule follow order settlement (every minute)
Schedule::command('follow:settle-orders')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Schedule Tron deposit processing
// Strategy: Separate scan and update for better control

// Scan for new deposits every minute
// This queries the blockchain for new USDT transfers to user addresses
Schedule::command('tron:scan-deposits')
    ->everyMinute()
    ->withoutOverlapping(5) // Auto-release lock after 5 minutes to prevent stuck tasks
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/tron-scan.log'))
    ->onFailure(function () {
        \Log::error('TronScanDeposits: Scheduled task failed', [
            'message' => '定时任务执行失败，请检查日志和网络连接',
        ]);
    });

// Update confirmations and credit confirmed deposits every minute
// This checks pending deposits and credits them once confirmations are sufficient
// More frequent updates ensure faster crediting once confirmations are met
Schedule::command('tron:update-confirms')
    ->everyMinute()
    ->withoutOverlapping(3) // Auto-release lock after 3 minutes
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/tron-confirms.log'))
    ->onFailure(function () {
        \Log::error('TronUpdateDepositConfirms: Scheduled task failed', [
            'message' => '定时任务执行失败，请检查日志和网络连接',
        ]);
    });

// Alternative: Combined approach (scan + update together)
// Uncomment below and comment above if you prefer a single command
// Schedule::command('tron:process-deposits')
//     ->everyTwoMinutes()
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->appendOutputTo(storage_path('logs/tron-deposits.log'));
