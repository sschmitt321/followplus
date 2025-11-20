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

        $count = $depositService->scanNewDeposits();

        $this->info("Found {$count} new deposit(s).");

        return Command::SUCCESS;
    }
}

