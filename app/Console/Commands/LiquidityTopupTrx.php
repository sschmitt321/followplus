<?php

namespace App\Console\Commands;

use App\Services\Tron\TronTopupService;
use Illuminate\Console\Command;

class LiquidityTopupTrx extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'liquidity:topup-trx 
                            {--limit= : Limit number of addresses to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Topup TRX for addresses that need gas';

    /**
     * Execute the console command.
     */
    public function handle(TronTopupService $topupService): int
    {
        $this->info('⛽ Starting TRX topups...');
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
            $this->info("Processing all addresses that need TRX topup...");
        }

        $this->newLine();

        // Execute topups with output callback
        $stats = $topupService->processTopups($limit, $outputCallback);

        // Display results
        $this->newLine();
        $this->info('✅ TRX topups completed!');
        $this->newLine();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                ['Topped Up', $stats['topped_up']],
                ['Failed', $stats['failed']],
            ]
        );

        if ($stats['failed'] > 0) {
            $this->warn("⚠️  {$stats['failed']} topup(s) failed. Check logs for details.");
        }

        return Command::SUCCESS;
    }
}
