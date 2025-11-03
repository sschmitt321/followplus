<?php

namespace App\Services\Assets;

use App\Models\Account;
use App\Models\UserAssetsSummary;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

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
     * Update user assets summary.
     */
    public function updateSummary(int $userId): UserAssetsSummary
    {
        return DB::transaction(function () use ($userId) {
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
    }

    /**
     * Get user assets summary.
     */
    public function getSummary(int $userId): UserAssetsSummary
    {
        return UserAssetsSummary::firstOrCreate(
            ['user_id' => $userId],
            [
                'total_balance' => '0',
                'principal_balance' => '0',
                'profit_balance' => '0',
                'bonus_balance' => '0',
            ]
        );
    }
}

