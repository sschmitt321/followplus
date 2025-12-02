<?php

namespace App\Console\Commands;

use App\Services\Tron\TronLiquidityService;
use Illuminate\Console\Command;

class LiquiditySyncAddresses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'liquidity:sync-addresses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync addresses from user_tron_wallets table to addresses_liquidity table';

    /**
     * Execute the console command.
     */
    public function handle(TronLiquidityService $liquidityService): int
    {
        $this->info('🔄 Syncing addresses from user_tron_wallets table...');
        $this->newLine();

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

        // Execute sync with output callback
        $stats = $liquidityService->syncAddressesFromWallets($outputCallback);

        // Display results
        $this->newLine();
        $this->info('✅ Address sync completed!');
        $this->newLine();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Synced (new)', $stats['synced']],
                ['Skipped (already exists)', $stats['skipped']],
            ]
        );

        if ($stats['synced'] > 0) {
            $this->info("✓ {$stats['synced']} new address(es) added to addresses_liquidity table");
        } else {
            $this->comment("All addresses from user_tron_wallets are already in addresses_liquidity table");
        }

        return Command::SUCCESS;
    }
}
