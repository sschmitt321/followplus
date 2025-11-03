<?php

namespace App\Services\Withdraw;

use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Assets\AssetsService;
use App\Services\Ledger\LedgerService;
use App\Services\Referral\ReferralService;
use App\Services\System\ConfigService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class WithdrawService
{
    private const WITHDRAW_FEE_RATE_OLD = 0.10; // 10%

    public function __construct(
        private LedgerService $ledgerService,
        private AssetsService $assetsService,
        private ConfigService $configService,
        private ?ReferralService $referralService = null
    ) {
    }

    /**
     * Calculate withdrawable amount for user.
     */
    public function calcWithdrawable(int $userId): array
    {
        $user = User::findOrFail($userId);
        $totalBalance = $this->assetsService->getTotalBalance($userId);
        
        $isNewbie = $this->isNewbie($user);
        
        if ($isNewbie) {
            // 新人：仅保留"每日 2 次固定跟单"的奖励，扣除所有彩金/加餐/课程奖励，之后再扣 10% 手续费
            // TODO: 这里需要模块4的跟单数据，暂时简化处理
            $withdrawable = $totalBalance->percentage(90); // 先扣除10%
            $fee = $totalBalance->percentage(10);
            $policy = 'newbie';
        } else {
            // 老人：按配置的手续费率
            $feeRate = $this->configService->get('WITHDRAW_FEE_RATE_OLD', self::WITHDRAW_FEE_RATE_OLD);
            $fee = $totalBalance->percentage($feeRate * 100);
            $withdrawable = $totalBalance->subtract($fee);
            $policy = 'old';
        }

        return [
            'withdrawable' => $withdrawable->toFixed(6),
            'fee' => $fee->toFixed(6),
            'policy' => $policy,
            'total_balance' => $totalBalance->toFixed(6),
        ];
    }

    /**
     * Apply withdrawal.
     */
    public function apply(
        int $userId,
        Decimal|string $amount,
        string $toAddress,
        string $currency = 'USDT',
        ?string $chain = null
    ): Withdrawal {
        return DB::transaction(function () use ($userId, $amount, $toAddress, $currency, $chain) {
            $amount = Decimal::of($amount);
            $user = User::findOrFail($userId);
            
            // Calculate fee
            $calc = $this->calcWithdrawable($userId);
            $totalBalance = Decimal::of($calc['total_balance']);
            
            if ($amount->greaterThan($totalBalance)) {
                throw new \Exception('Insufficient balance');
            }

            $isNewbie = $this->isNewbie($user);
            $feeRate = $isNewbie ? 0.10 : $this->configService->get('WITHDRAW_FEE_RATE_OLD', self::WITHDRAW_FEE_RATE_OLD);
            $fee = $amount->percentage($feeRate * 100);
            $amountActual = $amount->subtract($fee);

            // Check balance
            $account = \App\Models\Account::where([
                'user_id' => $userId,
                'type' => 'spot',
                'currency' => $currency,
            ])->lockForUpdate()->firstOrFail();

            if ($account->available->lessThan($amount)) {
                throw new \Exception('Insufficient available balance');
            }

            // Create withdrawal record
            $withdrawal = Withdrawal::create([
                'user_id' => $userId,
                'currency' => $currency,
                'amount_request' => $amount->toFixed(6),
                'fee' => $fee->toFixed(6),
                'amount_actual' => $amountActual->toFixed(6),
                'status' => 'pending',
                'to_address' => $toAddress,
                'chain' => $chain,
            ]);

            // Freeze balance
            $this->ledgerService->freeze(
                $userId,
                'spot',
                $currency,
                $amount,
                'withdraw',
                $withdrawal->id
            );

            // TODO: 新人提现会没收邀请者的彩金（模块3实现）

            return $withdrawal;
        });
    }

    /**
     * Approve withdrawal.
     */
    public function approve(int $withdrawalId): Withdrawal
    {
        return DB::transaction(function () use ($withdrawalId) {
            $withdrawal = Withdrawal::lockForUpdate()->findOrFail($withdrawalId);
            
            if ($withdrawal->status !== 'pending') {
                throw new \Exception('Withdrawal already processed');
            }

            $withdrawal->update(['status' => 'approved']);
            return $withdrawal->fresh();
        });
    }

    /**
     * Mark withdrawal as paid.
     */
    public function markPaid(int $withdrawalId, ?string $txid = null): Withdrawal
    {
        return DB::transaction(function () use ($withdrawalId, $txid) {
            $withdrawal = Withdrawal::lockForUpdate()->findOrFail($withdrawalId);
            
            if ($withdrawal->status !== 'approved') {
                throw new \Exception('Withdrawal must be approved first');
            }

            // Unfreeze first
            $this->ledgerService->unfreeze(
                $withdrawal->user_id,
                'spot',
                $withdrawal->currency,
                $withdrawal->amount_request
            );

            // Then debit from available balance
            $this->ledgerService->debit(
                $withdrawal->user_id,
                'spot',
                $withdrawal->currency,
                $withdrawal->amount_request,
                'withdraw',
                $withdrawal->id
            );

            $withdrawal->update([
                'status' => 'paid',
                'txid' => $txid,
            ]);

            // Trigger detach logic if user is a direct downline (module 3)
            if ($this->referralService) {
                try {
                    $user = User::findOrFail($withdrawal->user_id);
                    // Check if user is newbie (within 7 days) and has inviter
                    if ($user->invited_by_user_id && $user->first_joined_at && $user->first_joined_at->diffInDays(now()) < 7) {
                        $this->referralService->onDirectDownlineWithdrawPaid($withdrawal->user_id);
                    }
                } catch (\Exception $e) {
                    // Log error but don't fail the withdrawal
                    \Log::error('Failed to trigger detach logic: ' . $e->getMessage());
                }
            }

            return $withdrawal->fresh();
        });
    }

    /**
     * Reject withdrawal.
     */
    public function reject(int $withdrawalId): Withdrawal
    {
        return DB::transaction(function () use ($withdrawalId) {
            $withdrawal = Withdrawal::lockForUpdate()->findOrFail($withdrawalId);
            
            if ($withdrawal->status !== 'pending') {
                throw new \Exception('Withdrawal already processed');
            }

            // Unfreeze balance
            $this->ledgerService->unfreeze(
                $withdrawal->user_id,
                'spot',
                $withdrawal->currency,
                $withdrawal->amount_request
            );

            $withdrawal->update(['status' => 'rejected']);
            return $withdrawal->fresh();
        });
    }

    /**
     * Check if user is newbie (joined within 7 days).
     */
    private function isNewbie(User $user): bool
    {
        if (!$user->first_joined_at) {
            return true;
        }

        return $user->first_joined_at->diffInDays(now()) <= 7;
    }
}

