<?php

namespace App\Services\Ledger;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    /**
     * Credit (增加) account balance.
     */
    public function credit(
        int $userId,
        string $accountType,
        string $currency,
        Decimal|string $amount,
        string $bizType,
        ?int $refId = null,
        ?array $meta = null
    ): LedgerEntry {
        return DB::transaction(function () use ($userId, $accountType, $currency, $amount, $bizType, $refId, $meta) {
            $amount = Decimal::of($amount);
            
            // Get or create account
            $account = Account::firstOrCreate(
                [
                    'user_id' => $userId,
                    'type' => $accountType,
                    'currency' => $currency,
                ],
                [
                    'available' => '0',
                    'frozen' => '0',
                ]
            );

            // Update balance
            $newBalance = $account->available->add($amount);
            $account->available = $newBalance->toFixed(6);
            $account->save();

            // Create ledger entry
            $account->refresh(); // Reload to get updated balance
            return LedgerEntry::create([
                'user_id' => $userId,
                'account_id' => $account->id,
                'currency' => $currency,
                'amount' => $amount->toFixed(6),
                'balance_after' => $account->available->toFixed(6),
                'biz_type' => $bizType,
                'ref_id' => $refId,
                'meta_json' => $meta,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Debit (减少) account balance.
     */
    public function debit(
        int $userId,
        string $accountType,
        string $currency,
        Decimal|string $amount,
        string $bizType,
        ?int $refId = null,
        ?array $meta = null
    ): LedgerEntry {
        return DB::transaction(function () use ($userId, $accountType, $currency, $amount, $bizType, $refId, $meta) {
            $amount = Decimal::of($amount);
            
            // Get account
            $account = Account::where([
                'user_id' => $userId,
                'type' => $accountType,
                'currency' => $currency,
            ])->lockForUpdate()->firstOrFail();

            // Check balance
            if ($account->available->lessThan($amount)) {
                throw new \Exception('Insufficient balance');
            }

            // Update balance
            $newBalance = $account->available->subtract($amount);
            $account->available = $newBalance->toFixed(6);
            $account->save();

            // Create ledger entry (negative amount)
            $account->refresh(); // Reload to get updated balance
            return LedgerEntry::create([
                'user_id' => $userId,
                'account_id' => $account->id,
                'currency' => $currency,
                'amount' => $amount->negate()->toFixed(6),
                'balance_after' => $account->available->toFixed(6),
                'biz_type' => $bizType,
                'ref_id' => $refId,
                'meta_json' => $meta,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Freeze balance.
     */
    public function freeze(
        int $userId,
        string $accountType,
        string $currency,
        Decimal|string $amount,
        string $bizType,
        ?int $refId = null
    ): void {
        DB::transaction(function () use ($userId, $accountType, $currency, $amount, $bizType, $refId) {
            $amount = Decimal::of($amount);
            
            $account = Account::where([
                'user_id' => $userId,
                'type' => $accountType,
                'currency' => $currency,
            ])->lockForUpdate()->firstOrFail();

            if ($account->available->lessThan($amount)) {
                throw new \Exception('Insufficient available balance');
            }

            $newAvailable = $account->available->subtract($amount);
            $newFrozen = $account->frozen->add($amount);
            $account->available = $newAvailable->toFixed(6);
            $account->frozen = $newFrozen->toFixed(6);
            $account->save();
        });
    }

    /**
     * Unfreeze balance.
     */
    public function unfreeze(
        int $userId,
        string $accountType,
        string $currency,
        Decimal|string $amount
    ): void {
        DB::transaction(function () use ($userId, $accountType, $currency, $amount) {
            $amount = Decimal::of($amount);
            
            $account = Account::where([
                'user_id' => $userId,
                'type' => $accountType,
                'currency' => $currency,
            ])->lockForUpdate()->firstOrFail();

            if ($account->frozen->lessThan($amount)) {
                throw new \Exception('Insufficient frozen balance');
            }

            $newFrozen = $account->frozen->subtract($amount);
            $newAvailable = $account->available->add($amount);
            $account->frozen = $newFrozen->toFixed(6);
            $account->available = $newAvailable->toFixed(6);
            $account->save();
        });
    }
}

