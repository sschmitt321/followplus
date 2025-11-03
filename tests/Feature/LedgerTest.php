<?php

use App\Models\Account;
use App\Models\Currency;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Decimal;

test('ledger service can credit account', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    $entry = $ledgerService->credit(
        $user->id,
        'spot',
        'USDT',
        '100.50',
        'deposit',
        1
    );

    expect($entry)->toBeInstanceOf(LedgerEntry::class);
    expect($entry->amount->toString())->toBe('100.500000');

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    expect($account)->not->toBeNull();
    expect($account->available->toString())->toBe('100.500000');
    expect($entry->balance_after->toString())->toBe($account->available->toString());
});

test('ledger service can debit account', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    // Credit first
    $ledgerService->credit($user->id, 'spot', 'USDT', '100', 'deposit');
    
    // Then debit
    $entry = $ledgerService->debit(
        $user->id,
        'spot',
        'USDT',
        '50.25',
        'withdraw'
    );

    expect($entry->amount->toString())->toBe('-50.250000');

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    expect($account->available->toString())->toBe('49.750000');
});

test('ledger service throws exception on insufficient balance', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    // Create account with zero balance first
    $ledgerService->credit($user->id, 'spot', 'USDT', '50', 'deposit');

    // Try to debit more than available
    expect(fn() => $ledgerService->debit($user->id, 'spot', 'USDT', '100', 'withdraw'))
        ->toThrow(\Exception::class, 'Insufficient balance');
});

test('ledger service can freeze and unfreeze balance', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    // Credit first
    $ledgerService->credit($user->id, 'spot', 'USDT', '100', 'deposit');

    // Freeze
    $ledgerService->freeze($user->id, 'spot', 'USDT', '50', 'withdraw');

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    expect($account->available->toString())->toBe('50.000000');
    expect($account->frozen->toString())->toBe('50.000000');

    // Unfreeze
    $ledgerService->unfreeze($user->id, 'spot', 'USDT', '50');

    $account->refresh();
    expect($account->available->toString())->toBe('100.000000');
    expect($account->frozen->toString())->toBe('0.000000');
});

test('concurrent ledger entries maintain balance correctness', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    // Initial credit
    $ledgerService->credit($user->id, 'spot', 'USDT', '1000', 'deposit');

    // Simulate concurrent operations
    $operations = [];
    for ($i = 0; $i < 100; $i++) {
        $operations[] = $ledgerService->credit(
            $user->id,
            'spot',
            'USDT',
            '1',
            'test',
            $i
        );
    }

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    // Should be 1000 + 100 = 1100
    expect($account->available->toString())->toBe('1100.000000');

    // Verify ledger entries count
    $entriesCount = LedgerEntry::where('user_id', $user->id)->count();
    expect($entriesCount)->toBe(101); // 1 initial + 100 concurrent
});

test('ledger entries record balance_after correctly', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    // Multiple operations
    $ledgerService->credit($user->id, 'spot', 'USDT', '100', 'deposit', 1);
    $ledgerService->credit($user->id, 'spot', 'USDT', '50', 'deposit', 2);
    $ledgerService->debit($user->id, 'spot', 'USDT', '30', 'withdraw', 3);

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    $entries = LedgerEntry::where('account_id', $account->id)
        ->orderBy('created_at')
        ->get();

    // First entry: balance_after = 100
    expect($entries[0]->balance_after->toString())->toBe('100.000000');
    
    // Second entry: balance_after = 150
    expect($entries[1]->balance_after->toString())->toBe('150.000000');
    
    // Third entry: balance_after = 120
    expect($entries[2]->balance_after->toString())->toBe('120.000000');

    // Final balance should match last entry
    expect($account->available->toString())->toBe('120.000000');
});

