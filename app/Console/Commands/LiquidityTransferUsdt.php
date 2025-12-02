<?php

namespace App\Console\Commands;

use App\Services\Tron\TronBatchTransferService;
use Illuminate\Console\Command;

class LiquidityTransferUsdt extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'liquidity:transfer-usdt 
                            {--limit= : Limit number of addresses to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Transfer USDT from ready addresses to main wallet';

    /**
     * Execute the console command.
     */
    public function handle(TronBatchTransferService $transferService): int
    {
        $this->info('💰 Starting USDT transfers...');
        $this->newLine();

        // Parse options
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        // Create output callback for detailed logging
        $outputCallback = function (string $message, string $type = 'info') {
            if (empty($message)) {
                $this->newLine();
                return;
            }
            
            match ($type) {
                'error' => $this->error($message),
                'warn' => $this->warn($message),
                'comment' => $this->comment($message),
                default => $this->info($message),
            };
        };

        if ($limit) {
            $this->info("Processing up to {$limit} addresses...");
        } else {
            $this->info("Processing all ready addresses...");
        }

        $this->newLine();

        // Execute transfers with output callback
        $stats = $transferService->processTransfers($limit, $outputCallback);

        // Display results
        $this->newLine();
        $this->info('✅ USDT transfers completed!');
        $this->newLine();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                ['Transferred', $stats['transferred']],
                ['Failed', $stats['failed']],
            ]
        );

        if ($stats['failed'] > 0) {
            $this->warn("⚠️  {$stats['failed']} transfer(s) failed. Check logs for details.");
        }

        return Command::SUCCESS;
    }
}
