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

            // Credit to destination
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

