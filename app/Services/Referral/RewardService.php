<?php

namespace App\Services\Referral;

use App\Models\RefEvent;
use App\Models\RefReward;
use App\Models\RefStat;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RewardService
{
    public function __construct(
        private LedgerService $ledgerService,
        private ReferralService $referralService
    ) {
    }

    /**
     * Get reward amounts based on deposit amount (tiered system).
     * 
     * Tier structure:
     * - 1000 USD: Inviter 100, Newbie 100, Upline Assistance 50
     * - 2000 USD: Inviter 200, Newbie 200, Upline Assistance 100
     * - 3000 USD: Inviter 300, Newbie 300, Upline Assistance 150
     * - 5000 USD: Inviter 500, Newbie 500, Upline Assistance 250
     * - 8000 USD: Inviter 800, Newbie 800, Upline Assistance 400
     * - 10000 USD: Inviter 1000, Newbie 1000, Upline Assistance 500
     * 
     * @return array ['inviter' => Decimal, 'newbie' => Decimal, 'upline_assistance' => Decimal] or null if amount doesn't match any tier
     */
    private function getTieredRewardAmounts(Decimal $depositAmount): ?array
    {
        $amount = $depositAmount->toFloat();
        
        // Match deposit amount to tier
        if ($amount >= 10000) {
            return [
                'inviter' => Decimal::of('1000'),
                'newbie' => Decimal::of('1000'),
                'upline_assistance' => Decimal::of('500'),
            ];
        } elseif ($amount >= 8000) {
            return [
                'inviter' => Decimal::of('800'),
                'newbie' => Decimal::of('800'),
                'upline_assistance' => Decimal::of('400'),
            ];
        } elseif ($amount >= 5000) {
            return [
                'inviter' => Decimal::of('500'),
                'newbie' => Decimal::of('500'),
                'upline_assistance' => Decimal::of('250'),
            ];
        } elseif ($amount >= 3000) {
            return [
                'inviter' => Decimal::of('300'),
                'newbie' => Decimal::of('300'),
                'upline_assistance' => Decimal::of('150'),
            ];
        } elseif ($amount >= 2000) {
            return [
                'inviter' => Decimal::of('200'),
                'newbie' => Decimal::of('200'),
                'upline_assistance' => Decimal::of('100'),
            ];
        } elseif ($amount >= 1000) {
            return [
                'inviter' => Decimal::of('100'),
                'newbie' => Decimal::of('100'),
                'upline_assistance' => Decimal::of('50'),
            ];
        }
        
        return null; // Amount doesn't match any tier
    }

    /**
     * Grant referral rewards on first deposit.
     * 
     * Rules (tiered system based on deposit amount):
     * - Inviter reward: Based on deposit tier (100/200/300/500/800/1000 USD)
     * - Newbie reward: Same as inviter reward (granted on T+1)
     * - Upline assistance reward: Half of inviter reward (if notifier provided and inviter decides to grant)
     * 
     * Note: Deposits >= 5000 USD require approval (handled separately)
     */
    public function grantReferralOnDeposit(
        int $triggerUserId,
        Decimal|string $depositAmount,
        ?int $notifierUserId = null
    ): void {
        DB::transaction(function () use ($triggerUserId, $depositAmount, $notifierUserId) {
            $amount = Decimal::of($depositAmount);
            $user = User::findOrFail($triggerUserId);
            
            // Check if this is first deposit (idempotency check)
            $bizId = "first_deposit_{$triggerUserId}";
            $existingReward = RefReward::where('biz_id', $bizId)->first();
            if ($existingReward) {
                return; // Already processed
            }

            // Get tiered reward amounts
            $rewardAmounts = $this->getTieredRewardAmounts($amount);
            if (!$rewardAmounts) {
                // Deposit amount doesn't match any tier, skip rewards
                Log::info("Deposit amount {$amount->toFixed(2)} doesn't match any reward tier, skipping rewards", [
                    'user_id' => $triggerUserId,
                ]);
                return;
            }

            // Create event
            $event = RefEvent::create([
                'trigger_user_id' => $triggerUserId,
                'event_type' => 'first_deposit',
                'amount' => $amount,
                'meta_json' => [
                    'notifier_user_id' => $notifierUserId,
                    'reward_tier' => [
                        'inviter' => $rewardAmounts['inviter']->toFixed(2),
                        'newbie' => $rewardAmounts['newbie']->toFixed(2),
                        'upline_assistance' => $rewardAmounts['upline_assistance']->toFixed(2),
                    ],
                ],
            ]);

            // Get upline chain (up to 3 levels)
            $uplineChain = $this->referralService->getUplineChain($triggerUserId, 3);
            
            if (empty($uplineChain)) {
                return; // No inviter
            }

            // Level 1: Direct inviter gets tiered reward
            $directInviterId = $uplineChain[0];
            $this->createReward(
                $directInviterId,
                $triggerUserId,
                'referral_10pct',
                $rewardAmounts['inviter'],
                $event->id,
                $bizId
            );

            // Level 2: Upline assistance reward
            // Rule: "上级辅助彩金由邀请人决定,有辅助才赠送"
            // - If inviter has an upline (uplineChain[1] exists):
            //   - If notifier provided and notifier is not the inviter: notifier gets assistance reward
            //   - If no notifier: upline gets assistance reward
            // - If inviter has no upline: assistance reward is not granted (no one to assist)
            if (isset($uplineChain[1])) {
                // Inviter has an upline, grant assistance reward
                if ($notifierUserId && $notifierUserId !== $directInviterId) {
                    // Notifier gets upline assistance reward (half of inviter reward)
                    $this->createReward(
                        $notifierUserId,
                        $triggerUserId,
                        'notifier_5pct',
                        $rewardAmounts['upline_assistance'],
                        $event->id,
                        "notifier_5pct_{$triggerUserId}"
                    );
                } else {
                    // If no notifier, upline gets assistance reward
                    $this->createReward(
                        $uplineChain[1],
                        $triggerUserId,
                        'upline_5pct',
                        $rewardAmounts['upline_assistance'],
                        $event->id,
                        "upline_5pct_{$triggerUserId}"
                    );
                }
            }
            // Note: If inviter has no upline (uplineChain[1] doesn't exist),
            // the assistance reward is not granted as there's no one to assist.

            // Note: Newbie reward is granted on T+1 via grantNewbieNextDay()
            // Note: Statistics update is now handled in DepositService::confirm()
            // when user reaches activation threshold (cumulative deposits >= 1000 USDT)
        });
    }

    /**
     * Grant newbie next day reward (tiered based on first deposit amount).
     * 
     * Reward amount matches the inviter reward tier:
     * - 1000 USD deposit: 100 USD reward
     * - 2000 USD deposit: 200 USD reward
     * - 3000 USD deposit: 300 USD reward
     * - 5000 USD deposit: 500 USD reward
     * - 8000 USD deposit: 800 USD reward
     * - 10000 USD deposit: 1000 USD reward
     */
    public function grantNewbieNextDay(int $triggerUserId): void
    {
        DB::transaction(function () use ($triggerUserId) {
            $user = User::findOrFail($triggerUserId);
            
            // Check if user is still newbie (within 7 days)
            if (!$user->first_joined_at || $user->first_joined_at->diffInDays(now()) >= 7) {
                return; // Not a newbie anymore
            }

            // Check if already granted (idempotency)
            $bizId = "newbie_next_day_{$triggerUserId}";
            $existingReward = RefReward::where('biz_id', $bizId)->first();
            if ($existingReward) {
                return;
            }

            // Get first deposit amount
            $firstDepositEvent = RefEvent::where('trigger_user_id', $triggerUserId)
                ->where('event_type', 'first_deposit')
                ->first();
            
            if (!$firstDepositEvent) {
                return; // No first deposit found
            }

            $depositAmount = Decimal::of($firstDepositEvent->amount);
            
            // Get tiered reward amount (newbie reward matches inviter reward)
            $rewardAmounts = $this->getTieredRewardAmounts($depositAmount);
            if (!$rewardAmounts) {
                // Deposit amount doesn't match any tier, skip reward
                Log::info("Deposit amount {$depositAmount->toFixed(2)} doesn't match any reward tier, skipping newbie reward", [
                    'user_id' => $triggerUserId,
                ]);
                return;
            }

            // Create event
            $event = RefEvent::create([
                'trigger_user_id' => $triggerUserId,
                'event_type' => 'newbie_next_day',
                'amount' => $depositAmount,
                'meta_json' => [
                    'reward_tier' => [
                        'newbie' => $rewardAmounts['newbie']->toFixed(2),
                    ],
                ],
            ]);

            // Grant tiered reward (same as inviter reward)
            $this->createReward(
                $triggerUserId,
                null,
                'newbie_next_day',
                $rewardAmounts['newbie'],
                $event->id,
                $bizId
            );
        });
    }

    /**
     * Grant ambassador one-off reward when level up.
     */
    public function grantAmbassadorOneOff(int $userId, string $level): void
    {
        DB::transaction(function () use ($userId, $level) {
            $stat = RefStat::lockForUpdate()->where('user_id', $userId)->firstOrFail();
            
            // Check if already granted for this level using biz_id (which is unique per level)
            $bizId = "ambassador_{$level}_{$userId}";
            $existingReward = RefReward::where('user_id', $userId)
                ->where('type', 'ambassador_oneoff')
                ->where('status', 'confirmed')
                ->where('biz_id', $bizId)
                ->first();
            
            if ($existingReward) {
                return; // Already granted for this level
            }

            // Calculate reward amount based on level
            $rewardAmount = $this->getAmbassadorRewardAmount($level);
            
            if ($rewardAmount->isZero()) {
                return; // No reward for this level
            }

            // Create event
            $event = RefEvent::create([
                'trigger_user_id' => $userId,
                'event_type' => 'ambassador_level_up',
                'amount' => $rewardAmount,
                'meta_json' => ['level' => $level],
            ]);

            // Create reward
            $this->createReward(
                $userId,
                null,
                'ambassador_oneoff',
                $rewardAmount,
                $event->id,
                $bizId,
                ['level' => $level]
            );

            // Update stat manually to avoid type issues with MoneyCast
            $currentTotal = $stat->ambassador_reward_total instanceof Decimal ? $stat->ambassador_reward_total : Decimal::of($stat->ambassador_reward_total ?? 0);
            $newTotal = $currentTotal->add($rewardAmount);
            $stat->update(['ambassador_reward_total' => $newTotal]);
        });
    }

    /**
     * Get ambassador reward amount by level.
     * 
     * Level 1: 50 USD
     * Level 2: 200 USD
     * Level 3: 500 USD
     * Level 4: 1500 USD
     * Level 5 (Company Ambassador): 3000 USD
     */
    private function getAmbassadorRewardAmount(string $level): Decimal
    {
        return match ($level) {
            'L1' => Decimal::of('50'),
            'L2' => Decimal::of('200'),
            'L3' => Decimal::of('500'),
            'L4' => Decimal::of('1500'),
            'L5' => Decimal::of('3000'),
            default => Decimal::zero(),
        };
    }

    /**
     * Dispatch dividend for a cycle date.
     * 
     * Calculates platform revenue from withdrawal fees for the specified cycle period
     * and distributes dividends to ambassadors based on their dividend rates.
     * 
     * Cycle periods:
     * - 1st cycle: 1st to 4th of the month
     * - 2nd cycle: 5th to 14th of the month
     * - 3rd cycle: 15th to 24th of the month
     * - 4th cycle: 25th to end of the month
     * 
     * Dividend dispatch dates: 5th, 15th, 25th of each month
     * 
     * @param string $cycleDate Cycle date in Y-m-d format (e.g., '2025-11-05')
     *                          Should be one of: 5th, 15th, or 25th of the month
     */
    public function dispatchDividend(string $cycleDate): void
    {
        $date = \Carbon\Carbon::parse($cycleDate);
        $dayOfMonth = $date->day;
        
        // Determine cycle period based on dispatch date
        if ($dayOfMonth == 5) {
            // 1st cycle: 1st to 4th
            $cycleStart = $date->copy()->startOfMonth();
            $cycleEnd = $date->copy()->subDay()->endOfDay(); // 4th
        } elseif ($dayOfMonth == 15) {
            // 2nd cycle: 5th to 14th
            $cycleStart = $date->copy()->startOfMonth()->addDays(4); // 5th
            $cycleEnd = $date->copy()->subDay()->endOfDay(); // 14th
        } elseif ($dayOfMonth == 25) {
            // 3rd cycle: 15th to 24th
            $cycleStart = $date->copy()->startOfMonth()->addDays(14); // 15th
            $cycleEnd = $date->copy()->subDay()->endOfDay(); // 24th
        } else {
            throw new \Exception("Invalid cycle date. Must be 5th, 15th, or 25th of the month. Got: {$cycleDate}");
        }
        
        // Calculate platform revenue from withdrawal fees for this cycle
        $platformRevenue = $this->calculatePlatformRevenue($cycleStart, $cycleEnd);
        
        if ($platformRevenue->isZero()) {
            Log::info("No platform revenue for cycle {$cycleDate}, skipping dividend dispatch");
            return;
        }
        
        Log::info("Dispatching dividends for cycle {$cycleDate}", [
            'cycle_start' => $cycleStart->format('Y-m-d'),
            'cycle_end' => $cycleEnd->format('Y-m-d'),
            'platform_revenue' => $platformRevenue->toFixed(6),
        ]);
        
        // Get all users with dividend_rate > 0
        $stats = RefStat::where('dividend_rate', '>', 0)->get();
        
        foreach ($stats as $stat) {
            // Calculate dividend based on platform revenue and user's dividend rate
            $dividendAmount = $platformRevenue->multiply($stat->dividend_rate);
            
            if ($dividendAmount->isZero()) {
                continue;
            }

            // Create event
            $event = RefEvent::create([
                'trigger_user_id' => $stat->user_id,
                'event_type' => 'dividend',
                'amount' => $dividendAmount,
                'meta_json' => [
                    'cycle_date' => $cycleDate,
                    'cycle_start' => $cycleStart->format('Y-m-d'),
                    'cycle_end' => $cycleEnd->format('Y-m-d'),
                    'platform_revenue' => $platformRevenue->toFixed(6),
                ],
            ]);

            // Create reward
            $bizId = "dividend_{$cycleDate}_{$stat->user_id}";
            $this->createReward(
                $stat->user_id,
                null,
                'dividend',
                $dividendAmount,
                $event->id,
                $bizId,
                [
                    'cycle_date' => $cycleDate,
                    'cycle_start' => $cycleStart->format('Y-m-d'),
                    'cycle_end' => $cycleEnd->format('Y-m-d'),
                    'platform_revenue' => $platformRevenue->toFixed(6),
                ]
            );
        }
    }
    
    /**
     * Calculate platform revenue from withdrawal fees for a given period.
     * 
     * Platform revenue = sum of all withdrawal fees from completed withdrawals (status = 'paid')
     * 
     * @param \Carbon\Carbon $startDate Start date (inclusive)
     * @param \Carbon\Carbon $endDate End date (inclusive)
     * @return Decimal Total platform revenue
     */
    private function calculatePlatformRevenue(\Carbon\Carbon $startDate, \Carbon\Carbon $endDate): Decimal
    {
        $withdrawals = Withdrawal::where('status', 'paid')
            ->whereBetween('updated_at', [
                $startDate->startOfDay()->utc(),
                $endDate->endOfDay()->utc(),
            ])
            ->get();
        
        $totalRevenue = $withdrawals->reduce(function (Decimal $carry, Withdrawal $withdrawal) {
            return $carry->add($withdrawal->fee ?? Decimal::zero());
        }, Decimal::zero());
        
        return $totalRevenue;
    }

    /**
     * Create reward record and credit account.
     */
    private function createReward(
        int $userId,
        ?int $sourceUserId,
        string $type,
        Decimal $amount,
        int $eventId,
        string $bizId,
        array $meta = []
    ): RefReward {
        // Create reward record
        $reward = RefReward::create([
            'user_id' => $userId,
            'source_user_id' => $sourceUserId,
            'type' => $type,
            'amount' => $amount,
            'status' => 'pending',
            'ref_event_id' => $eventId,
            'biz_id' => $bizId,
        ]);

        // Credit to user's account
        $this->ledgerService->credit(
            $userId,
            'spot',
            'USDT', // Default currency, should be configurable
            $amount,
            'reward',
            $reward->id
        );

        // Confirm reward
        $reward->update(['status' => 'confirmed']);

        // Update ref_stat total_rewards
        $stat = RefStat::firstOrCreate(
            ['user_id' => $userId],
            [
                'direct_count' => 0,
                'team_count' => 0,
                'ambassador_level' => 'L0',
                'dividend_rate' => 0,
            ]
        );
        // Update total_rewards manually to avoid type issues with MoneyCast
        $currentTotal = $stat->total_rewards instanceof Decimal ? $stat->total_rewards : Decimal::of($stat->total_rewards ?? 0);
        $newTotal = $currentTotal->add($amount);
        $stat->update(['total_rewards' => $newTotal]);

        return $reward;
    }

    /**
     * Reverse a reward (cancel it).
     */
    public function reverseReward(int $rewardId): void
    {
        DB::transaction(function () use ($rewardId) {
            $reward = RefReward::lockForUpdate()->findOrFail($rewardId);
            
            if ($reward->status !== 'confirmed') {
                throw new \Exception('Reward not confirmed, cannot reverse');
            }

            // Debit from account
            $this->ledgerService->debit(
                $reward->user_id,
                'spot',
                'USDT',
                $reward->amount,
                'reward_reverse',
                $reward->id
            );

            // Update status
            $reward->update(['status' => 'cancelled']);

            // Update ref_stat total_rewards
            $stat = RefStat::where('user_id', $reward->user_id)->first();
            if ($stat) {
                // Update total_rewards manually to avoid type issues with MoneyCast
                $currentTotal = $stat->total_rewards instanceof Decimal ? $stat->total_rewards : Decimal::of($stat->total_rewards ?? 0);
                $rewardAmount = $reward->amount instanceof Decimal ? $reward->amount : Decimal::of($reward->amount);
                $newTotal = $currentTotal->subtract($rewardAmount);
                $stat->update(['total_rewards' => $newTotal]);
            }
        });
    }
}

