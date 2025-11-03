<?php

use App\Models\Account;
use App\Models\Currency;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

test('concurrent credits maintain correct balance', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    // Initial balance
    $ledgerService->credit($user->id, 'spot', 'USDT', '1000', 'deposit');

    // Simulate concurrent credits
    $concurrentCount = 50;
    $amountPerCredit = '10';

    // Use queue jobs or parallel processing simulation
    $promises = [];
    for ($i = 0; $i < $concurrentCount; $i++) {
        // In PHP, we can't truly parallelize, but we can test rapid sequential calls
        // which should still maintain correctness due to transactions
        DB::transaction(function () use ($ledgerService, $user, $i, $amountPerCredit) {
            $ledgerService->credit(
                $user->id,
                'spot',
                'USDT',
                $amountPerCredit,
                'test',
                $i
            );
        });
    }

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    // Expected: 1000 + (50 * 10) = 1500
    $expectedBalance = Decimal::of('1000')
        ->add(Decimal::of($amountPerCredit)->multiply($concurrentCount));

    expect($account->available->toString())->toBe($expectedBalance->toString());

    // Verify all entries were created
    $entriesCount = LedgerEntry::where('user_id', $user->id)->count();
    expect($entriesCount)->toBe($concurrentCount + 1); // 1 initial + 50 concurrent
});

test('concurrent debits maintain correct balance and prevent overdraft', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    // Initial balance
    $ledgerService->credit($user->id, 'spot', 'USDT', '1000', 'deposit');

    $debitAmount = '20';
    $maxDebits = 50; // 50 * 20 = 1000, should be exactly the balance

    $successCount = 0;
    $failCount = 0;

    for ($i = 0; $i < $maxDebits; $i++) {
        try {
            DB::transaction(function () use ($ledgerService, $user, $i, $debitAmount) {
                $ledgerService->debit(
                    $user->id,
                    'spot',
                    'USDT',
                    $debitAmount,
                    'test',
                    $i
                );
            });
            $successCount++;
        } catch (\Exception $e) {
            $failCount++;
        }
    }

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    // Should have exactly 50 successful debits
    expect($successCount)->toBe(50);
    expect($failCount)->toBe(0);
    expect($account->available->toString())->toBe('0.000000');

    // If we try one more debit, it should fail
    expect(fn() => $ledgerService->debit($user->id, 'spot', 'USDT', '1', 'test'))
        ->toThrow(\Exception::class, 'Insufficient balance');
});

test('concurrent freeze and unfreeze operations maintain consistency', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    // Initial balance
    $ledgerService->credit($user->id, 'spot', 'USDT', '1000', 'deposit');

    // Concurrent freeze operations
    $freezeAmount = '10';
    $freezeCount = 50; // Total freeze: 500

    for ($i = 0; $i < $freezeCount; $i++) {
        DB::transaction(function () use ($ledgerService, $user, $i, $freezeAmount) {
            $ledgerService->freeze(
                $user->id,
                'spot',
                'USDT',
                $freezeAmount,
                'test',
                $i
            );
        });
    }

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    $expectedFrozen = Decimal::of($freezeAmount)->multiply($freezeCount);
    $expectedAvailable = Decimal::of('1000')->subtract($expectedFrozen);

    expect($account->frozen->toString())->toBe($expectedFrozen->toString());
    expect($account->available->toString())->toBe($expectedAvailable->toString());

    // Concurrent unfreeze operations
    $unfreezeCount = 30; // Unfreeze 300

    for ($i = 0; $i < $unfreezeCount; $i++) {
        DB::transaction(function () use ($ledgerService, $user, $freezeAmount) {
            $ledgerService->unfreeze(
                $user->id,
                'spot',
                'USDT',
                $freezeAmount
            );
        });
    }

    $account->refresh();

    $remainingFrozen = Decimal::of($freezeAmount)->multiply($freezeCount - $unfreezeCount);
    $finalAvailable = Decimal::of('1000')->subtract($remainingFrozen);

    expect($account->frozen->toString())->toBe($remainingFrozen->toString());
    expect($account->available->toString())->toBe($finalAvailable->toString());
});

test('concurrent mixed operations maintain ledger conservation', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    // Initial balance
    $ledgerService->credit($user->id, 'spot', 'USDT', '10000', 'deposit');

    $operations = [];
    $totalCredits = Decimal::zero();
    $totalDebits = Decimal::zero();

    // Mix of credits and debits
    for ($i = 0; $i < 100; $i++) {
        if ($i % 2 === 0) {
            // Credit
            $amount = Decimal::of(rand(10, 100));
            $operations[] = ['type' => 'credit', 'amount' => $amount];
            $totalCredits = $totalCredits->add($amount);
            
            DB::transaction(function () use ($ledgerService, $user, $i, $amount) {
                $ledgerService->credit(
                    $user->id,
                    'spot',
                    'USDT',
                    $amount->toString(),
                    'test',
                    $i
                );
            });
        } else {
            // Debit (only if we have enough balance)
            $account = Account::where([
                'user_id' => $user->id,
                'type' => 'spot',
                'currency' => 'USDT',
            ])->first();
            
            if ($account && $account->available->greaterThan(Decimal::of('10'))) {
                $amount = Decimal::of(rand(10, 100));
                $operations[] = ['type' => 'debit', 'amount' => $amount];
                $totalDebits = $totalDebits->add($amount);
                
                try {
                    DB::transaction(function () use ($ledgerService, $user, $i, $amount) {
                        $ledgerService->debit(
                            $user->id,
                            'spot',
                            'USDT',
                            $amount->toString(),
                            'test',
                            $i
                        );
                    });
                } catch (\Exception $e) {
                    // If insufficient balance, remove from total
                    $totalDebits = $totalDebits->subtract($amount);
                }
            }
        }
    }

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    // Expected balance: 10000 + totalCredits - totalDebits
    $expectedBalance = Decimal::of('10000')
        ->add($totalCredits)
        ->subtract($totalDebits);

    expect($account->available->toString())->toBe($expectedBalance->toString());
});

test('ledger entries sum equals account balance changes', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    // Perform multiple operations
    $ledgerService->credit($user->id, 'spot', 'USDT', '1000', 'deposit', 1);
    $ledgerService->credit($user->id, 'spot', 'USDT', '500', 'deposit', 2);
    $ledgerService->debit($user->id, 'spot', 'USDT', '300', 'withdraw', 3);
    $ledgerService->credit($user->id, 'spot', 'USDT', '200', 'bonus', 4);
    $ledgerService->debit($user->id, 'spot', 'USDT', '100', 'fee', 5);

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    // Calculate sum of all ledger entries
    $entries = LedgerEntry::where('account_id', $account->id)
        ->orderBy('created_at')
        ->get();

    $sumOfEntries = $entries->reduce(function ($carry, $entry) {
        return $carry->add($entry->amount);
    }, Decimal::zero());

    // Sum should equal current balance (since we started from 0)
    expect($account->available->toString())->toBe($sumOfEntries->toString());
});

