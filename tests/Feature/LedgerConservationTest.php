<?php

use App\Models\Account;
use App\Models\Currency;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Deposit\DepositService;
use App\Services\Ledger\LedgerService;
use App\Services\Transfer\TransferService;
use App\Services\Withdraw\WithdrawService;
use App\Support\Decimal;

test('ledger conservation: sum of all entries equals account balances', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);

    // Create multiple deposits
    $depositService->manualApply($user->id, 'USDT', '1000');
    $depositService->manualApply($user->id, 'USDT', '500');
    $depositService->manualApply($user->id, 'USDT', '200');

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    // Calculate sum of all ledger entries for this account
    $entries = LedgerEntry::where('account_id', $account->id)->get();
    $sumOfEntries = $entries->reduce(function ($carry, $entry) {
        return $carry->add($entry->amount);
    }, Decimal::zero());

    // Account balance should equal sum of entries
    expect($account->available->toString())->toBe($sumOfEntries->toString());
});

test('ledger conservation: transfer maintains total balance', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $transferService = new TransferService($ledgerService);

    // Initial deposit
    $depositService->manualApply($user->id, 'USDT', '1000');

    // Transfer to contract account
    $transferService->transfer($user->id, 'USDT', 'spot', 'contract', '500');

    // Get both accounts
    $spotAccount = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    $contractAccount = Account::where([
        'user_id' => $user->id,
        'type' => 'contract',
        'currency' => 'USDT',
    ])->first();

    // Total balance should remain 1000
    $totalBalance = $spotAccount->available->add($contractAccount->available);
    expect($totalBalance->toString())->toBe('1000.000000');

    // Verify ledger entries: spot should have debit, contract should have credit
    $spotEntries = LedgerEntry::where('account_id', $spotAccount->id)->get();
    $contractEntries = LedgerEntry::where('account_id', $contractAccount->id)->get();

    $spotSum = $spotEntries->reduce(fn($carry, $entry) => $carry->add($entry->amount), Decimal::zero());
    $contractSum = $contractEntries->reduce(fn($carry, $entry) => $carry->add($entry->amount), Decimal::zero());

    expect($spotAccount->available->toString())->toBe($spotSum->toString());
    expect($contractAccount->available->toString())->toBe($contractSum->toString());
});

test('ledger conservation: freeze and unfreeze maintains total balance', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);

    // Initial deposit
    $depositService->manualApply($user->id, 'USDT', '1000');

    // Freeze some balance
    $ledgerService->freeze($user->id, 'spot', 'USDT', '300', 'withdraw');

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    // Total balance (available + frozen) should equal initial deposit
    $totalBalance = $account->available->add($account->frozen);
    expect($totalBalance->toString())->toBe('1000.000000');

    // Unfreeze
    $ledgerService->unfreeze($user->id, 'spot', 'USDT', '300');

    $account->refresh();

    // Total balance should still be 1000
    $totalBalance = $account->available->add($account->frozen);
    expect($totalBalance->toString())->toBe('1000.000000');
    expect($account->frozen->toString())->toBe('0.000000');
});

test('ledger conservation: withdrawal maintains balance conservation', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $withdrawService = new WithdrawService(
        $ledgerService,
        new \App\Services\Assets\AssetsService(),
        new \App\Services\System\ConfigService()
    );

    // Initial deposit
    $depositService->manualApply($user->id, 'USDT', '1000');

    // Apply withdrawal (freezes balance)
    $withdrawal = $withdrawService->apply($user->id, '200', 'Txxx', 'USDT');

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    // Total balance (available + frozen) should still be 1000
    $totalBalance = $account->available->add($account->frozen);
    expect($totalBalance->toString())->toBe('1000.000000');
    expect($account->frozen->toString())->toBe('200.000000');
    expect($account->available->toString())->toBe('800.000000');

    // Approve and mark paid (should debit from frozen)
    $withdrawService->approve($withdrawal->id);
    $withdrawService->markPaid($withdrawal->id, 'txid123');

    $account->refresh();

    // After payment, frozen should be 0, available should be 800
    expect($account->frozen->toString())->toBe('0.000000');
    expect($account->available->toString())->toBe('800.000000');

    // Verify ledger entries sum matches account balance
    $entries = LedgerEntry::where('account_id', $account->id)->get();
    $sumOfEntries = $entries->reduce(fn($carry, $entry) => $carry->add($entry->amount), Decimal::zero());
    expect($account->available->toString())->toBe($sumOfEntries->toString());
});

test('ledger conservation: multiple accounts total balance matches sum of deposits', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    Currency::factory()->create(['name' => 'BTC']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);

    // Deposit multiple currencies
    $depositService->manualApply($user->id, 'USDT', '1000');
    $depositService->manualApply($user->id, 'USDT', '500');
    $depositService->manualApply($user->id, 'BTC', '0.5');

    // Transfer some USDT to contract
    $transferService = new TransferService($ledgerService);
    $transferService->transfer($user->id, 'USDT', 'spot', 'contract', '300');

    // Get all accounts
    $accounts = Account::where('user_id', $user->id)->get();

    // Calculate total balance from accounts
    $totalFromAccounts = $accounts->reduce(function ($carry, $account) {
        return $carry->add($account->available)->add($account->frozen);
    }, Decimal::zero());

    // Expected: 1000 + 500 + 0.5 BTC (we can't convert BTC to USDT in this test, so just verify USDT)
    $usdtAccounts = $accounts->where('currency', 'USDT');
    $usdtTotal = $usdtAccounts->reduce(function ($carry, $account) {
        return $carry->add($account->available)->add($account->frozen);
    }, Decimal::zero());

    // USDT total should be 1500 (1000 + 500)
    expect($usdtTotal->toString())->toBe('1500.000000');
});

test('ledger conservation: balance_after in entries matches actual balance', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();

    // Perform sequence of operations (without freeze to keep it simple)
    $ledgerService->credit($user->id, 'spot', 'USDT', '1000', 'deposit', 1);
    $ledgerService->credit($user->id, 'spot', 'USDT', '500', 'deposit', 2);
    $ledgerService->debit($user->id, 'spot', 'USDT', '300', 'withdraw', 3);
    $ledgerService->credit($user->id, 'spot', 'USDT', '100', 'bonus', 4);

    $account = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    // Get entries in order
    $entries = LedgerEntry::where('account_id', $account->id)
        ->orderBy('created_at')
        ->get();

    // Last entry's balance_after should match current available balance
    $lastEntry = $entries->last();
    expect($account->available->toString())->toBe($lastEntry->balance_after->toString());

    // Verify each entry's balance_after is correct
    // Note: freeze/unfreeze operations don't create ledger entries, only credit/debit do
    $runningBalance = Decimal::zero();
    foreach ($entries as $entry) {
        $runningBalance = $runningBalance->add($entry->amount);
        // balance_after should match running balance
        expect($entry->balance_after->toString())->toBe($runningBalance->toString());
    }
    
    // Final running balance should match account available balance
    expect($account->available->toString())->toBe($runningBalance->toString());
});

test('ledger conservation: complex transaction sequence maintains conservation', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $transferService = new TransferService($ledgerService);
    $withdrawService = new WithdrawService(
        $ledgerService,
        new \App\Services\Assets\AssetsService(),
        new \App\Services\System\ConfigService()
    );

    // Sequence of operations
    $depositService->manualApply($user->id, 'USDT', '1000'); // +1000
    $transferService->transfer($user->id, 'USDT', 'spot', 'contract', '300'); // spot: 700, contract: 300
    $transferService->transfer($user->id, 'USDT', 'contract', 'spot', '100'); // spot: 800, contract: 200
    $withdrawal = $withdrawService->apply($user->id, '200', 'Txxx', 'USDT'); // spot: 600 available, 200 frozen
    $withdrawService->approve($withdrawal->id);
    $withdrawService->markPaid($withdrawal->id, 'txid'); // spot: 600 available, 0 frozen

    // Verify spot account
    $spotAccount = Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    // Verify contract account
    $contractAccount = Account::where([
        'user_id' => $user->id,
        'type' => 'contract',
        'currency' => 'USDT',
    ])->first();

    // Total balance should be 1000 - 200 (withdrawal) = 800
    $totalBalance = $spotAccount->available
        ->add($spotAccount->frozen)
        ->add($contractAccount->available)
        ->add($contractAccount->frozen);

    expect($totalBalance->toString())->toBe('800.000000');

    // Verify ledger entries sum matches balances
    $spotEntries = LedgerEntry::where('account_id', $spotAccount->id)->get();
    $contractEntries = LedgerEntry::where('account_id', $contractAccount->id)->get();

    $spotSum = $spotEntries->reduce(fn($carry, $entry) => $carry->add($entry->amount), Decimal::zero());
    $contractSum = $contractEntries->reduce(fn($carry, $entry) => $carry->add($entry->amount), Decimal::zero());

    expect($spotAccount->available->toString())->toBe($spotSum->toString());
    expect($contractAccount->available->toString())->toBe($contractSum->toString());
});

