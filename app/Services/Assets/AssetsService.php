<?php

namespace App\Services\Assets;

use App\Models\Account;
use App\Models\UserAssetsSummary;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssetsService
{
    /**
     * Get total balance for user.
     */
    public function getTotalBalance(int $userId): Decimal
    {
        $total = Account::where('user_id', $userId)
            ->get()
            ->reduce(function (Decimal $carry, Account $account) {
                return $carry->add($account->available)->add($account->frozen);
            }, Decimal::zero());

        return $total;
    }

    /**
     * Get contract account balance for user (for follow trading).
     * 
     * Returns only available balance, excluding frozen balance.
     * Frozen balance is already allocated to existing orders and should not be used for new order calculations.
     */
    public function getContractBalance(int $userId, string $currency = 'USDT'): Decimal
    {
        $account = Account::where([
            'user_id' => $userId,
            'type' => 'contract',
            'currency' => $currency,
        ])->first();

        if (!$account) {
            return Decimal::zero();
        }

        // Only return available balance, not frozen balance
        // Frozen balance is already allocated to existing orders
        return $account->available;
    }

    /**
     * Get spot account balance for user (for deposit/withdraw).
     */
    public function getSpotBalance(int $userId, string $currency = 'USDT'): Decimal
    {
        $account = Account::where([
            'user_id' => $userId,
            'type' => 'spot',
            'currency' => $currency,
        ])->first();

        if (!$account) {
            return Decimal::zero();
        }

        return $account->available->add($account->frozen);
    }

    /**
     * Update user assets summary.
     * 
     * Handles deadlocks with retry mechanism.
     */
    public function updateSummary(int $userId): UserAssetsSummary
    {
        $maxRetries = 3;
        $retryCount = 0;

        while ($retryCount < $maxRetries) {
            try {
                return DB::transaction(function () use ($userId) {
                    // Calculate balances first (outside of lock)
            $accounts = Account::where('user_id', $userId)->get();
            
            $totalBalance = Decimal::zero();
            $principalBalance = Decimal::zero();
            $profitBalance = Decimal::zero();
            $bonusBalance = Decimal::zero();

            foreach ($accounts as $account) {
                $balance = $account->available->add($account->frozen);
                $totalBalance = $totalBalance->add($balance);
                
                // TODO: 根据业务类型区分本金、利润、奖励
                // 这里先全部算作本金
                $principalBalance = $principalBalance->add($balance);
            }

                    // Use updateOrCreate which handles concurrency better
                    // It will try to update first, and only create if record doesn't exist
            return UserAssetsSummary::updateOrCreate(
                ['user_id' => $userId],
                [
                    'total_balance' => $totalBalance->toFixed(6),
                    'principal_balance' => $principalBalance->toFixed(6),
                    'profit_balance' => $profitBalance->toFixed(6),
                    'bonus_balance' => $bonusBalance->toFixed(6),
                ]
            );
        });
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle deadlock (1213) or duplicate key (1062)
                $isDeadlock = $e->getCode() === '40001' && str_contains($e->getMessage(), '1213');
                $isDuplicateKey = $e->getCode() === '23000' && str_contains($e->getMessage(), '1062');
                
                if ($isDeadlock || $isDuplicateKey) {
                    $retryCount++;
                    
                    if ($retryCount >= $maxRetries) {
                        // Last retry: if duplicate key, try to get existing record and update
                        if ($isDuplicateKey) {
                            $summary = UserAssetsSummary::where('user_id', $userId)->first();
                            if ($summary) {
                                // Recalculate and update
                                $accounts = Account::where('user_id', $userId)->get();
                                $totalBalance = Decimal::zero();
                                $principalBalance = Decimal::zero();
                                $profitBalance = Decimal::zero();
                                $bonusBalance = Decimal::zero();
                                
                                foreach ($accounts as $account) {
                                    $balance = $account->available->add($account->frozen);
                                    $totalBalance = $totalBalance->add($balance);
                                    $principalBalance = $principalBalance->add($balance);
                                }
                                
                                $summary->update([
                                    'total_balance' => $totalBalance->toFixed(6),
                                    'principal_balance' => $principalBalance->toFixed(6),
                                    'profit_balance' => $profitBalance->toFixed(6),
                                    'bonus_balance' => $bonusBalance->toFixed(6),
                                ]);
                                return $summary->fresh();
                            }
                        }
                        throw new \Exception('Failed to update summary after ' . $maxRetries . ' retries', 0, $e);
                    }
                    
                    // Wait a random amount of time before retrying (exponential backoff)
                    $delay = rand(10000, 50000) * $retryCount;
                    usleep($delay);
                    
                    Log::warning('AssetsService: Deadlock or duplicate key detected, retrying', [
                        'user_id' => $userId,
                        'retry' => $retryCount,
                        'delay_us' => $delay,
                        'error_code' => $e->getCode(),
                    ]);
                    continue;
                }
                
                // Re-throw if it's not a deadlock or duplicate key error
                throw $e;
            }
        }
        
        throw new \Exception('Failed to update summary after retries');
    }

    /**
     * Get user assets summary.
     * 
     * Automatically updates the summary from account balances if needed.
     */
    public function getSummary(int $userId): UserAssetsSummary
    {
        // Always update summary from actual account balances to ensure accuracy
        return $this->updateSummary($userId);
    }
}

