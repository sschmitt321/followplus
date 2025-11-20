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

        $credited = $depositService->updateConfirmationsAndCredit();

        $this->info("Credited {$credited} deposit(s).");

        return Command::SUCCESS;
    }
}

