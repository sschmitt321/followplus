<?php

use App\Models\Currency;
use App\Models\Deposit;
use App\Models\User;
use App\Services\Deposit\DepositService;
use App\Services\Ledger\LedgerService;

test('deposit service can create deposit record', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $depositService = new DepositService(new LedgerService());

    $deposit = $depositService->create(
        $user->id,
        'USDT',
        '100.50',
        'TRC20',
        'Txxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
    );

    expect($deposit)->toBeInstanceOf(Deposit::class);
    expect($deposit->status)->toBe('pending');
    expect($deposit->amount->toString())->toBe('100.500000');
});

test('deposit service can confirm deposit and credit account', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $depositService = new DepositService(new LedgerService());

    // Create deposit
    $deposit = $depositService->create($user->id, 'USDT', '100', 'TRC20');

    // Confirm deposit
    $confirmedDeposit = $depositService->confirm($deposit->id, 'txid123');

    expect($confirmedDeposit->status)->toBe('confirmed');
    expect($confirmedDeposit->txid)->toBe('txid123');
    expect($confirmedDeposit->confirmed_at)->not->toBeNull();

    // Verify account was credited
    $account = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    expect($account)->not->toBeNull();
    expect($account->available->toString())->toBe('100.000000');
});

test('deposit service manual apply creates and confirms deposit', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $depositService = new DepositService(new LedgerService());

    $deposit = $depositService->manualApply($user->id, 'USDT', '500');

    expect($deposit->status)->toBe('confirmed');
    
    // Verify account balance
    $account = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    expect($account->available->toString())->toBe('500.000000');
});

test('deposit service cannot confirm already processed deposit', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $depositService = new DepositService(new LedgerService());

    $deposit = $depositService->create($user->id, 'USDT', '100');
    $depositService->confirm($deposit->id);

    expect(fn() => $depositService->confirm($deposit->id))
        ->toThrow(\Exception::class, 'Deposit already processed');
});

