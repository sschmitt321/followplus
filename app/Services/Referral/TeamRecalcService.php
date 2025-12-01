<?php

namespace App\Services\Referral;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class TeamRecalcService
{
    public function __construct(
        private ReferralService $referralService
    ) {
    }

    /**
     * Recalculate team stats for all users or a specific user.
     */
    public function recalcAll(?int $userId = null): void
    {
        if ($userId) {
            // Recalculate for specific user and upline
            $this->referralService->recalcTeamStats($userId);
        } else {
            // Recalculate for all users
            $users = User::all();
            foreach ($users as $user) {
                $this->referralService->recalcTeamStats($user->id);
            }
        }
    }

    /**
     * Recalculate ambassador levels and dividend rates for all users.
     */
    public function recalcAmbassadorLevels(): void
    {
        $users = User::all();
        
        foreach ($users as $user) {
            DB::transaction(function () use ($user) {
                $this->referralService->recalcTeamStats($user->id);
                
                // Update dividend rate based on level
                $stat = \App\Models\RefStat::where('user_id', $user->id)->first();
                if ($stat) {
                    $dividendRate = $this->getDividendRateForLevel($stat->ambassador_level);
                    $stat->update(['dividend_rate' => $dividendRate]);
                }
            });
        }
    }

    /**
     * Get dividend rate for ambassador level.
     * 
     * Trading volume dividend rates:
     * Level 1: 0.5%
     * Level 2: 1.0%
     * Level 3: 1.5%
     * Level 4: 2.0%
     * Level 5 (Company Ambassador): 2.5%
     */
    private function getDividendRateForLevel(string $level): float
    {
        return match ($level) {
            'L1' => 0.0050, // 0.5%
            'L2' => 0.0100, // 1.0%
            'L3' => 0.0150, // 1.5%
            'L4' => 0.0200, // 2.0%
            'L5' => 0.0250, // 2.5%
            default => 0.0,
        };
    }
}

