<?php

namespace App\Console\Commands;

use App\Services\Tron\TronDepositService;
use Illuminate\Console\Command;

class TronScanDeposits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tron:scan-deposits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan Tron network for new USDT deposits';

    /**
     * Execute the console command.
     */
    public function handle(TronDepositService $depositService): int
    {
        $this->info('Scanning for new Tron deposits...');
        $this->info('This may take a while if network is slow...');
        $this->info('Check logs for detailed progress: ' . storage_path('logs/laravel.log'));
        $this->newLine();

        $startTime = microtime(true);
        $warningThreshold = 60; // 60秒警告阈值
        $criticalThreshold = 300; // 5分钟严重警告阈值

        try {
            $this->info('⏳ Fetching transfer events from TronGrid...');
            $count = $depositService->scanNewDeposits();
            $duration = round(microtime(true) - $startTime, 2);

            $this->newLine();
            $this->info("✅ Scan completed in {$duration}s");
            $this->info("✅ Found {$count} new deposit(s).");

            // 监控执行时间并记录警告
            if ($duration >= $criticalThreshold) {
                \Log::critical('TronScanDeposits: Task execution time exceeded critical threshold', [
                    'duration' => $duration,
                    'threshold' => $criticalThreshold,
                    'message' => "任务执行时间 {$duration} 秒，超过严重警告阈值 {$criticalThreshold} 秒！这可能导致后续任务被跳过。",
                ]);
                $this->warn("⚠️  WARNING: Task took {$duration}s (critical threshold: {$criticalThreshold}s)");
            } elseif ($duration >= $warningThreshold) {
                \Log::warning('TronScanDeposits: Task execution time exceeded warning threshold', [
                    'duration' => $duration,
                    'threshold' => $warningThreshold,
                    'message' => "任务执行时间 {$duration} 秒，超过警告阈值 {$warningThreshold} 秒。",
                ]);
                $this->warn("⚠️  WARNING: Task took {$duration}s (warning threshold: {$warningThreshold}s)");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);
            $this->newLine();
            $this->error("❌ Error scanning deposits (after {$duration}s): " . $e->getMessage());
            $this->warn("This might be due to network issues. Please try again later.");
            $this->info("Check logs for details: " . storage_path('logs/laravel.log'));
            
            // 记录失败错误
            \Log::error('TronScanDeposits: Task failed', [
                'duration' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return Command::FAILURE;
        }
    }
}

