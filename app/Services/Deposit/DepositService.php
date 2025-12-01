<?php

namespace App\Services\Deposit;

use App\Models\Deposit;
use App\Services\Ledger\LedgerService;
use App\Services\Referral\ReferralService;
use App\Services\Referral\RewardService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class DepositService
{
    public function __construct(
        private LedgerService $ledgerService,
        private ?RewardService $rewardService = null,
        private ?ReferralService $referralService = null
    ) {
    }

    /**
     * Create deposit record.
     */
    public function create(
        int $userId,
        string $currency,
        Decimal|string $amount,
        ?string $chain = null,
        ?string $address = null
    ): Deposit {
        return Deposit::create([
            'user_id' => $userId,
            'currency' => $currency,
            'chain' => $chain,
            'address' => $address,
            'amount' => Decimal::of($amount)->toFixed(6),
            'status' => 'pending',
        ]);
    }

    /**
     * Confirm deposit and credit account.
     */
    public function confirm(int $depositId, ?string $txid = null): Deposit
    {
        return DB::transaction(function () use ($depositId, $txid) {
            $deposit = Deposit::lockForUpdate()->findOrFail($depositId);
            
            if ($deposit->status !== 'pending') {
                throw new \Exception('Deposit already processed');
            }

            $deposit->update([
                'status' => 'confirmed',
                'txid' => $txid,
                'confirmed_at' => now(),
            ]);

            // Credit to spot account
            $this->ledgerService->credit(
                $deposit->user_id,
                'spot',
                $deposit->currency,
                $deposit->amount,
                'deposit',
                $deposit->id
            );

            // Trigger referral rewards if this is first deposit
            // Use app() to resolve RewardService if not injected (to avoid circular dependency issues)
            $rewardService = $this->rewardService ?? app(RewardService::class);
            if ($rewardService) {
                try {
                    $rewardService->grantReferralOnDeposit(
                        $deposit->user_id,
                        $deposit->amount
                    );
                } catch (\Exception $e) {
                    // Log error but don't fail the deposit
                    \Log::error('Failed to grant referral rewards: ' . $e->getMessage(), [
                        'deposit_id' => $depositId,
                        'user_id' => $deposit->user_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // Check if user just reached activation threshold (cumulative deposits >= 1000 USDT)
            // If so, update referral statistics for inviter and all upline users
            $referralService = $this->referralService ?? app(ReferralService::class);
            if ($referralService) {
                try {
                    // Check if user is now activated (wasn't before, but is now)
                    $wasActivated = $this->wasUserActivatedBefore($deposit->user_id, $depositId);
                    $isNowActivated = $referralService->isUserActivated($deposit->user_id);
                    
                    // If user just became activated, update statistics
                    if (!$wasActivated && $isNowActivated) {
                        // Get user's inviter
                        $user = \App\Models\User::find($deposit->user_id);
                        if ($user && $user->invited_by_user_id) {
                            // Update statistics for inviter and all upline users
                            $referralService->recalcTeamStats($user->invited_by_user_id);
                        }
                    }
                } catch (\Exception $e) {
                    // Log error but don't fail the deposit
                    \Log::error('Failed to update referral statistics on activation: ' . $e->getMessage(), [
                        'deposit_id' => $depositId,
                        'user_id' => $deposit->user_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            return $deposit->fresh();
        });
    }

    /**
     * Manual apply deposit (for testing/admin).
     */
    public function manualApply(int $userId, string $currency, Decimal|string $amount): Deposit
    {
        $deposit = $this->create($userId, $currency, $amount);
        return $this->confirm($deposit->id);
    }

    /**
     * Check if user was activated before this deposit.
     * 
     * @param int $userId User ID
     * @param int $currentDepositId Current deposit ID being confirmed
     * @return bool True if user was already activated before this deposit
     */
    private function wasUserActivatedBefore(int $userId, int $currentDepositId): bool
    {
        $minActivationAmount = Decimal::of(1000); // 1000 USDT
        
        // Get all confirmed deposits except the current one
        $previousDeposits = Deposit::where('user_id', $userId)
            ->where('status', 'confirmed')
            ->where('currency', 'USDT')
            ->where('id', '!=', $currentDepositId) // Exclude current deposit
            ->get();
        
        // Sum previous deposits
        $previousTotal = Decimal::zero();
        foreach ($previousDeposits as $deposit) {
            // Ensure amount is Decimal object
            $amount = $deposit->amount instanceof Decimal ? $deposit->amount : Decimal::of($deposit->amount);
            $previousTotal = $previousTotal->add($amount);
        }
        
        // Check if previous total was >= 1000
        // Use compare method: >= 0 means previousTotal >= minActivationAmount
        return $previousTotal->compare($minActivationAmount) >= 0;
    }
}

