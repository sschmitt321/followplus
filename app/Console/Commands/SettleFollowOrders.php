<?php

namespace App\Console\Commands;

use App\Services\Follow\FollowService;
use Illuminate\Console\Command;

class SettleFollowOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'follow:settle-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Settle expired follow windows and calculate profits';

    /**
     * Execute the console command.
     */
    public function handle(FollowService $followService): int
    {
        $this->info('Starting settlement process...');

        try {
            $settledCount = $followService->settleExpiredWindows();
            
            $this->info("Settled {$settledCount} windows successfully");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to settle orders: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}

