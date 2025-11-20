<?php

namespace App\Services\Transfer;

use App\Models\InternalTransfer;
use App\Services\Ledger\LedgerService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class TransferService
{
    public function __construct(
        private LedgerService $ledgerService
    ) {
    }

    /**
     * Transfer between account types.
     */
    public function transfer(
        int $userId,
        string $currency,
        string $fromType,
        string $toType,
        Decimal|string $amount
    ): InternalTransfer {
        if ($fromType === $toType) {
            throw new \Exception('Cannot transfer to same account type');
        }

        return DB::transaction(function () use ($userId, $currency, $fromType, $toType, $amount) {
            $amount = Decimal::of($amount);

            // Validate source account exists and has sufficient balance
            $sourceAccount = \App\Models\Account::where([
                'user_id' => $userId,
                'type' => $fromType,
                'currency' => $currency,
            ])->first();

            if (!$sourceAccount) {
                // Check if user has the opposite account type to provide better error message
                $oppositeType = $fromType === 'spot' ? 'contract' : 'spot';
                $oppositeAccount = \App\Models\Account::where([
                    'user_id' => $userId,
                    'type' => $oppositeType,
                    'currency' => $currency,
                ])->first();
                
                if ($oppositeAccount && $oppositeAccount->available->greaterThan(Decimal::zero())) {
                    throw new \Exception("您没有 {$fromType} 账户，请先从 {$oppositeType} 账户转到 {$fromType} 账户");
                } else {
                    throw new \Exception("{$fromType} 账户不存在，请先充值");
                }
            }

            if ($sourceAccount->available->lessThan($amount)) {
                $availableBalance = $sourceAccount->available->toFixed(6);
                $requiredAmount = $amount->toFixed(6);
                throw new \Exception("账户余额不足。{$fromType} 账户可用余额: {$availableBalance} {$currency}，需要: {$requiredAmount} {$currency}");
            }

            // Debit from source
            $this->ledgerService->debit(
                $userId,
                $fromType,
                $currency,
                $amount,
                'transfer',
                null,
                ['from_type' => $fromType, 'to_type' => $toType]
            );

            // Credit to destination (will auto-create if not exists)
            $this->ledgerService->credit(
                $userId,
                $toType,
                $currency,
                $amount,
                'transfer',
                null,
                ['from_type' => $fromType, 'to_type' => $toType]
            );

            // Create transfer record
            return InternalTransfer::create([
                'user_id' => $userId,
                'currency' => $currency,
                'from_type' => $fromType,
                'to_type' => $toType,
                'amount' => $amount->toFixed(6),
                'status' => 'completed',
            ]);
        });
    }
}

