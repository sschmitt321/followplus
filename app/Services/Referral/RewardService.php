<?php

namespace App\Services\Referral;

use App\Models\RefEvent;
use App\Models\RefReward;
use App\Models\RefStat;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class RewardService
{
    public function __construct(
        private LedgerService $ledgerService,
        private ReferralService $referralService
    ) {
    }

    /**
     * Grant referral rewards on first deposit.
     * 
     * Rules:
     * - 10% to direct inviter
     * - 5% to notifier (if provided) or upline (if no notifier)
     * - 5% to upline (second level)
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

            // Create event
            $event = RefEvent::create([
                'trigger_user_id' => $triggerUserId,
                'event_type' => 'first_deposit',
                'amount' => $amount,
                'meta_json' => [
                    'notifier_user_id' => $notifierUserId,
                ],
            ]);

            // Get upline chain (up to 3 levels)
            $uplineChain = $this->referralService->getUplineChain($triggerUserId, 3);
            
            if (empty($uplineChain)) {
                return; // No inviter
            }

            // Level 1: Direct inviter gets 10%
            $directInviterId = $uplineChain[0];
            $reward10pct = $amount->percentage(10);
            $this->createReward(
                $directInviterId,
                $triggerUserId,
                'referral_10pct',
                $reward10pct,
                $event->id,
                $bizId
            );

            // Level 2: Notifier or upline gets 5%
            if ($notifierUserId && $notifierUserId !== $directInviterId) {
                // Notifier gets 5%
                $reward5pct = $amount->percentage(5);
                $this->createReward(
                    $notifierUserId,
                    $triggerUserId,
                    'notifier_5pct',
                    $reward5pct,
                    $event->id,
                    "notifier_5pct_{$triggerUserId}"
                );
            } elseif (isset($uplineChain[1])) {
                // Upline gets 5%
                $reward5pct = $amount->percentage(5);
                $this->createReward(
                    $uplineChain[1],
                    $triggerUserId,
                    'upline_5pct',
                    $reward5pct,
                    $event->id,
                    "upline_5pct_{$triggerUserId}"
                );
            }

            // Level 3: Second upline gets 5% (if exists)
            if (isset($uplineChain[2])) {
                $reward5pct = $amount->percentage(5);
                $this->createReward(
                    $uplineChain[2],
                    $triggerUserId,
                    'upline_5pct',
                    $reward5pct,
                    $event->id,
                    "upline2_5pct_{$triggerUserId}"
                );
            }
        });
    }

    /**
     * Grant newbie next day reward (10% of first deposit).
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

            // Create event
            $event = RefEvent::create([
                'trigger_user_id' => $triggerUserId,
                'event_type' => 'newbie_next_day',
                'amount' => $firstDepositEvent->amount,
                'meta_json' => [],
            ]);

            // Grant 10% reward
            $rewardAmount = Decimal::of($firstDepositEvent->amount)->percentage(10);
            $this->createReward(
                $triggerUserId,
                null,
                'newbie_next_day',
                $rewardAmount,
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
            
            // Check if already granted for this level
            if ($stat->ambassador_level === $level) {
                // Check if reward already granted
                $existingReward = RefReward::where('user_id', $userId)
                    ->where('type', 'ambassador_oneoff')
                    ->where('status', 'confirmed')
                    ->whereJsonContains('meta_json->level', $level)
                    ->first();
                
                if ($existingReward) {
                    return; // Already granted
                }
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
            $bizId = "ambassador_{$level}_{$userId}";
            $this->createReward(
                $userId,
                null,
                'ambassador_oneoff',
                $rewardAmount,
                $event->id,
                $bizId,
                ['level' => $level]
            );

            // Update stat
            $stat->increment('ambassador_reward_total', $rewardAmount->toString());
        });
    }

    /**
     * Get ambassador reward amount by level.
     */
    private function getAmbassadorRewardAmount(string $level): Decimal
    {
        return match ($level) {
            'L1' => Decimal::of('100'),
            'L2' => Decimal::of('500'),
            'L3' => Decimal::of('2000'),
            'L4' => Decimal::of('10000'),
            'L5' => Decimal::of('50000'),
            default => Decimal::zero(),
        };
    }

    /**
     * Dispatch dividend for a cycle date.
     */
    public function dispatchDividend(string $cycleDate): void
    {
        // Get all users with dividend_rate > 0
        $stats = RefStat::where('dividend_rate', '>', 0)->get();
        
        foreach ($stats as $stat) {
            // Calculate dividend based on platform revenue (placeholder)
            // In real implementation, this would be based on actual platform revenue
            $platformRevenue = Decimal::of('1000000'); // Placeholder
            $dividendAmount = $platformRevenue->multiply($stat->dividend_rate);
            
            if ($dividendAmount->isZero()) {
                continue;
            }

            // Create event
            $event = RefEvent::create([
                'trigger_user_id' => $stat->user_id,
                'event_type' => 'dividend',
                'amount' => $dividendAmount,
                'meta_json' => ['cycle_date' => $cycleDate],
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
                ['cycle_date' => $cycleDate]
            );
        }
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
        $stat->increment('total_rewards', $amount->toString());

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
                $stat->decrement('total_rewards', $reward->amount->toString());
            }
        });
    }
}

