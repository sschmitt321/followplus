<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule T+1 newbie rewards (daily at 00:10)
// Grants 10% reward to newbies on the day after their first deposit
Schedule::command('rewards:grant-newbie-next-day')
    ->dailyAt('00:10')
    ->withoutOverlapping(10) // Auto-release lock after 10 minutes
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/rewards-newbie.log'))
    ->onFailure(function () {
        \Log::error('GrantNewbieNextDayRewards: Scheduled task failed', [
            'message' => '定时任务执行失败，请检查日志',
        ]);
    });

// Schedule dividend dispatch (monthly on 5th, 15th, 25th at 00:00)
// Distributes platform revenue (withdrawal fees) to ambassadors based on their dividend rates
// Cycle periods:
// - 1st cycle (dispatched on 5th): 1st to 4th of the month
// - 2nd cycle (dispatched on 15th): 5th to 14th of the month
// - 3rd cycle (dispatched on 25th): 15th to 24th of the month
Schedule::command('rewards:dispatch-dividends')
    ->cron('0 0 5 * *') // 5th of each month at 00:00
    ->withoutOverlapping(30) // Auto-release lock after 30 minutes
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/rewards-dividends.log'))
    ->onFailure(function () {
        \Log::error('DispatchDividends: Scheduled task failed (5th)', [
            'message' => '定时任务执行失败，请检查日志',
        ]);
    });

Schedule::command('rewards:dispatch-dividends')
    ->cron('0 0 15 * *') // 15th of each month at 00:00
    ->withoutOverlapping(30)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/rewards-dividends.log'))
    ->onFailure(function () {
        \Log::error('DispatchDividends: Scheduled task failed (15th)', [
            'message' => '定时任务执行失败，请检查日志',
        ]);
    });

Schedule::command('rewards:dispatch-dividends')
    ->cron('0 0 25 * *') // 25th of each month at 00:00
    ->withoutOverlapping(30)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/rewards-dividends.log'))
    ->onFailure(function () {
        \Log::error('DispatchDividends: Scheduled task failed (25th)', [
            'message' => '定时任务执行失败，请检查日志',
        ]);
    });

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

// Batch transfer liquidity management tasks
// Only run if auto_collection is enabled and required configurations are set
$autoCollectionEnabled = env('TRON_AUTO_COLLECTION', false);
$hasMainTrxWalletKey = !empty(env('TRON_BATCH_MAIN_TRX_WALLET_PRIVATE_KEY', '')) || !empty(env('TRON_GAS_BANK_PRIVATE_KEY', ''));
$hasMainUsdtWallet = !empty(env('TRON_BATCH_MAIN_USDT_WALLET', ''));

if ($autoCollectionEnabled && $hasMainTrxWalletKey && $hasMainUsdtWallet) {
    // Scan balances periodically (every 5 minutes by default)
    Schedule::command('liquidity:scan-balances')
        ->everyFiveMinutes() // Can be adjusted: everyMinute(), everyFiveMinutes(), everyTenMinutes(), etc.
        ->withoutOverlapping(10) // Auto-release lock after 10 minutes
        ->runInBackground()
        ->appendOutputTo(storage_path('logs/liquidity-scan.log'))
        ->onFailure(function () {
            \Log::error('LiquidityScanBalances: Scheduled task failed', [
                'message' => '余额扫描任务执行失败，请检查日志和网络连接',
            ]);
        });

    // Process USDT transfers (every minute)
    Schedule::command('liquidity:transfer-usdt')
        ->everyMinute()
        ->withoutOverlapping(5) // Auto-release lock after 5 minutes
        ->runInBackground()
        ->appendOutputTo(storage_path('logs/liquidity-transfer.log'))
        ->onFailure(function () {
            \Log::error('LiquidityTransferUsdt: Scheduled task failed', [
                'message' => 'USDT 转账任务执行失败，请检查日志和网络连接',
            ]);
        });

    // Process TRX topups (every minute)
    Schedule::command('liquidity:topup-trx')
        ->everyMinute()
        ->withoutOverlapping(5) // Auto-release lock after 5 minutes
        ->runInBackground()
        ->appendOutputTo(storage_path('logs/liquidity-topup.log'))
        ->onFailure(function () {
            \Log::error('LiquidityTopupTrx: Scheduled task failed', [
                'message' => 'TRX 充值任务执行失败，请检查日志和网络连接',
            ]);
        });
} else {
    // Log why auto collection is disabled
    if (!$autoCollectionEnabled) {
        \Log::info('Auto collection disabled: TRON_AUTO_COLLECTION is not enabled');
    } elseif (!$hasMainTrxWalletKey) {
        \Log::warning('Auto collection disabled: TRON_BATCH_MAIN_TRX_WALLET_PRIVATE_KEY or TRON_GAS_BANK_PRIVATE_KEY not configured');
    } elseif (!$hasMainUsdtWallet) {
        \Log::warning('Auto collection disabled: TRON_BATCH_MAIN_USDT_WALLET not configured');
    }
}
