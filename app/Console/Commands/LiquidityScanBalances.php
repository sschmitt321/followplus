<?php

namespace App\Console\Commands;

use App\Services\Tron\TronLiquidityService;
use Illuminate\Console\Command;

class LiquidityScanBalances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'liquidity:scan-balances 
                            {--limit= : Limit number of addresses to scan}
                            {--status= : Comma-separated list of statuses to scan (default: NEW,TRX_TOPPED_UP,NEED_TRX_TOPUP,SKIP_SMALL_BALANCE)}
                            {--no-sync : Disable auto-sync from user_tron_wallets table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan TRX/USDT/DT balances for addresses and update their status';

    /**
     * Execute the console command.
     */
    public function handle(TronLiquidityService $liquidityService): int
    {
        $this->info('🔄 Starting balance scan...');
        $this->newLine();

        // Parse options
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $statuses = null;
        $autoSync = !$this->option('no-sync');
        
        if ($this->option('status')) {
            $statuses = array_map('trim', explode(',', $this->option('status')));
        }

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

        if ($autoSync) {
            $this->info("Syncing addresses from user_tron_wallets table...");
            $syncStats = $liquidityService->syncAddressesFromWallets($outputCallback);
            $this->newLine();
            $this->info("✓ Sync completed: {$syncStats['synced']} synced, {$syncStats['skipped']} skipped");
            $this->newLine();
        }

        $this->info("Scanning addresses..." . ($limit ? " (limit: {$limit})" : ""));
        $this->newLine();

        // Execute scan with output callback
        $stats = $liquidityService->scanBalances($statuses, $limit, false, $outputCallback); // autoSync already done above

        // Display results
        $this->newLine();
        $this->info('✅ Balance scan completed!');
        $this->newLine();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $stats['scanned']],
                ['Updated', $stats['updated']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($stats['errors'] > 0) {
            $this->warn("⚠️  {$stats['errors']} error(s) occurred. Check logs for details.");
        }

        return Command::SUCCESS;
    }
}
