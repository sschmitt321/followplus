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
    protected $signature = 'rewards:dispatch-dividends {cycle_date?} {--force : Skip date validation for debugging}';

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
        // Default to today's date, but should be 5th, 15th, or 25th when called by scheduler
        $cycleDate = $this->argument('cycle_date') ?? now()->format('Y-m-d');
        $forceMode = $this->option('force');
        
        $dayOfMonth = (int) date('d', strtotime($cycleDate));
        if (!in_array($dayOfMonth, [5, 15, 25])) {
            if ($forceMode) {
                $this->warn("Force mode: Bypassing date validation. Using date: {$cycleDate}");
            } else {
                $this->warn("Warning: Cycle date should be 5th, 15th, or 25th of the month. Got: {$cycleDate}");
            }
        }
        
        $this->info("Starting dividend dispatch for cycle: {$cycleDate}");

        try {
            $rewardService->dispatchDividend($cycleDate, $forceMode);
            $this->info("Completed dividend dispatch for cycle: {$cycleDate}");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to dispatch dividends: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}

