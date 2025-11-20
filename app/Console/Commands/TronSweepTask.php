<?php

namespace App\Console\Commands;

use App\Services\Tron\TronSweepService;
use Illuminate\Console\Command;

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
        $this->info('Starting sweep operation...');

        $swept = $sweepService->sweepAll();

        $this->info("Swept {$swept} address(es).");

        return Command::SUCCESS;
    }
}

