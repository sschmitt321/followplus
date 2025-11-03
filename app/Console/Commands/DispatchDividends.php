<?php

namespace App\Console\Commands;

use App\Services\Referral\RewardService;
use Illuminate\Console\Command;

class DispatchDividends extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rewards:dispatch-dividends {cycle_date?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch cycle dividends to ambassadors';

    /**
     * Execute the console command.
     */
    public function handle(RewardService $rewardService): int
    {
        $cycleDate = $this->argument('cycle_date') ?? now()->format('Y-m-d');
        
        $this->info("Starting dividend dispatch for cycle: {$cycleDate}");

        try {
            $rewardService->dispatchDividend($cycleDate);
            $this->info("Completed dividend dispatch for cycle: {$cycleDate}");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to dispatch dividends: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}

