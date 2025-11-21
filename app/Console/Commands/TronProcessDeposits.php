<?php

namespace App\Console\Commands;

use App\Services\Tron\TronDepositService;
use Illuminate\Console\Command;

class TronProcessDeposits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tron:process-deposits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan for new deposits and update confirmations (combines scan + update)';

    /**
     * Execute the console command.
     */
    public function handle(TronDepositService $depositService): int
    {
        $this->info('🔄 Processing Tron deposits...');
        $this->newLine();

        // Step 1: Scan for new deposits
        $this->info('Step 1: Scanning for new deposits...');
        $scannedCount = $depositService->scanNewDeposits();
        $this->info("✅ Found {$scannedCount} new deposit(s).");
        $this->newLine();

        // Step 2: Update confirmations and credit
        $this->info('Step 2: Updating confirmations and crediting...');
        $creditedCount = $depositService->updateConfirmationsAndCredit();
        $this->info("✅ Credited {$creditedCount} deposit(s).");
        $this->newLine();

        $this->info('✅ Deposit processing completed!');

        return Command::SUCCESS;
    }
}

