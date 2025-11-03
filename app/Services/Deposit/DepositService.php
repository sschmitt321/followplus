<?php

namespace App\Services\Deposit;

use App\Models\Deposit;
use App\Services\Ledger\LedgerService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class DepositService
{
    public function __construct(
        private LedgerService $ledgerService
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
}

