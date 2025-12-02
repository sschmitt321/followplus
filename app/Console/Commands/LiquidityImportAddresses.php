<?php

namespace App\Console\Commands;

use App\Services\Tron\TronLiquidityService;
use Illuminate\Console\Command;

class LiquidityImportAddresses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'liquidity:import-addresses 
                            {addresses?* : Space-separated list of addresses to import}
                            {--file= : Path to file containing addresses (one per line)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import addresses for batch transfer management';

    /**
     * Execute the console command.
     */
    public function handle(TronLiquidityService $liquidityService): int
    {
        $this->info('📥 Importing addresses...');
        $this->newLine();

        $addresses = [];

        // Get addresses from command arguments
        $argAddresses = $this->argument('addresses');
        if (!empty($argAddresses)) {
            $addresses = array_merge($addresses, $argAddresses);
        }

        // Get addresses from file
        $filePath = $this->option('file');
        if ($filePath) {
            if (!file_exists($filePath)) {
                $this->error("File not found: {$filePath}");
                return Command::FAILURE;
            }

            $fileContent = file_get_contents($filePath);
            $fileAddresses = array_filter(
                array_map('trim', explode("\n", $fileContent)),
                fn($addr) => !empty($addr) && !str_starts_with($addr, '#')
            );
            
            $addresses = array_merge($addresses, $fileAddresses);
        }

        if (empty($addresses)) {
            $this->error('No addresses provided. Use arguments or --file option.');
            return Command::FAILURE;
        }

        $this->info("Found " . count($addresses) . " address(es) to import");
        $this->newLine();

        // Import addresses
        $stats = $liquidityService->importAddresses($addresses);

        // Display results
        $this->info('✅ Address import completed!');
        $this->newLine();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Imported', $stats['imported']],
                ['Skipped (already exists)', $stats['skipped']],
            ]
        );

        return Command::SUCCESS;
    }
}
