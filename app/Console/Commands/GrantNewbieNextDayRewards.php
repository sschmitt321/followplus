<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Referral\RewardService;
use Illuminate\Console\Command;

class GrantNewbieNextDayRewards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rewards:grant-newbie-next-day';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grant T+1 next day rewards to newbies who deposited yesterday';

    /**
     * Execute the console command.
     */
    public function handle(RewardService $rewardService): int
    {
        $this->info('Starting T+1 newbie reward grant...');

        // Get users who joined yesterday and had first deposit
        $yesterday = now()->subDay()->startOfDay();
        $today = now()->startOfDay();

        $newbies = User::whereBetween('first_joined_at', [$yesterday, $today])
            ->get();

        $count = 0;
        foreach ($newbies as $newbie) {
            try {
                $rewardService->grantNewbieNextDay($newbie->id);
                $count++;
                $this->line("Granted reward to user {$newbie->id}");
            } catch (\Exception $e) {
                $this->error("Failed to grant reward to user {$newbie->id}: {$e->getMessage()}");
            }
        }

        $this->info("Completed. Granted rewards to {$count} users.");

        return Command::SUCCESS;
    }
}

