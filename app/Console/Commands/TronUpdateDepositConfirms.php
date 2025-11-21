<?php

namespace App\Console\Commands;

use App\Services\Tron\TronDepositService;
use Illuminate\Console\Command;

class TronUpdateDepositConfirms extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tron:update-confirms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update deposit confirmations and credit confirmed deposits';

    /**
     * Execute the console command.
     */
    public function handle(TronDepositService $depositService): int
    {
        $this->info('Updating deposit confirmations...');

        $startTime = microtime(true);
        $warningThreshold = 30; // 30秒警告阈值
        $criticalThreshold = 120; // 2分钟严重警告阈值

        try {
            $credited = $depositService->updateConfirmationsAndCredit();
            $duration = round(microtime(true) - $startTime, 2);

            $this->info("Credited {$credited} deposit(s).");

            // 监控执行时间并记录警告
            if ($duration >= $criticalThreshold) {
                \Log::critical('TronUpdateDepositConfirms: Task execution time exceeded critical threshold', [
                    'duration' => $duration,
                    'threshold' => $criticalThreshold,
                    'credited' => $credited,
                    'message' => "任务执行时间 {$duration} 秒，超过严重警告阈值 {$criticalThreshold} 秒！这可能导致后续任务被跳过。",
                ]);
                $this->warn("⚠️  WARNING: Task took {$duration}s (critical threshold: {$criticalThreshold}s)");
            } elseif ($duration >= $warningThreshold) {
                \Log::warning('TronUpdateDepositConfirms: Task execution time exceeded warning threshold', [
                    'duration' => $duration,
                    'threshold' => $warningThreshold,
                    'credited' => $credited,
                    'message' => "任务执行时间 {$duration} 秒，超过警告阈值 {$warningThreshold} 秒。",
                ]);
                $this->warn("⚠️  WARNING: Task took {$duration}s (warning threshold: {$warningThreshold}s)");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);
            $this->error("❌ Error updating confirmations (after {$duration}s): " . $e->getMessage());
            
            // 记录失败错误
            \Log::error('TronUpdateDepositConfirms: Task failed', [
                'duration' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return Command::FAILURE;
        }
    }
}

