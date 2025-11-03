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
     */
    private function getDividendRateForLevel(string $level): float
    {
        return match ($level) {
            'L1' => 0.0001, // 0.01%
            'L2' => 0.0002, // 0.02%
            'L3' => 0.0005, // 0.05%
            'L4' => 0.0010, // 0.1%
            'L5' => 0.0020, // 0.2%
            default => 0.0,
        };
    }
}

