<?php

namespace App\Console\Commands;

use App\Services\Tron\TronSweepService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TronSweepTask extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tron:sweep';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sweep USDT from user deposit addresses to hot wallet';

    /**
     * Execute the console command.
     */
    public function handle(TronSweepService $sweepService): int
    {
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('  TRON USDT Sweep Operation');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();
        
        Log::info('TronSweepTask: Command started');

        try {
            // Create output callback for console display
            $outputCallback = function (string $message, string $type = 'info') {
                switch ($type) {
                    case 'error':
                        $this->error($message);
                        break;
                    case 'comment':
                        $this->comment($message);
                        break;
                    case 'warn':
                        $this->warn($message);
                        break;
                    case 'info':
                    default:
                        $this->info($message);
                        break;
                }
            };
            
            $swept = $sweepService->sweepAll($outputCallback);
            
            $this->newLine();
            $this->info('═══════════════════════════════════════════════════════════');
            $this->info("✓ Sweep operation completed: {$swept} address(es) swept");
            $this->info('═══════════════════════════════════════════════════════════');
            
            Log::info('TronSweepTask: Command completed', [
                'swept_count' => $swept,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('═══════════════════════════════════════════════════════════');
            $this->error('✗ Sweep operation failed: ' . $e->getMessage());
            $this->error('═══════════════════════════════════════════════════════════');
            
            Log::error('TronSweepTask: Command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}

