<?php

use App\Models\Currency;
use App\Models\InternalTransfer;
use App\Models\User;
use App\Services\Deposit\DepositService;
use App\Services\Ledger\LedgerService;
use App\Services\Transfer\TransferService;

test('transfer service can transfer between account types', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    
    // Deposit to spot account
    $depositService->manualApply($user->id, 'USDT', '1000');

    $transferService = new TransferService($ledgerService);

    $transfer = $transferService->transfer(
        $user->id,
        'USDT',
        'spot',
        'contract',
        '500'
    );

    expect($transfer)->toBeInstanceOf(InternalTransfer::class);
    expect($transfer->status)->toBe('completed');
    expect($transfer->amount->toString())->toBe('500.000000');

    // Verify balances
    $spotAccount = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    $contractAccount = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'contract',
        'currency' => 'USDT',
    ])->first();

    expect($spotAccount->available->toString())->toBe('500.000000');
    expect($contractAccount->available->toString())->toBe('500.000000');
});

test('transfer service throws exception when transferring to same account type', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $transferService = new TransferService(new LedgerService());

    expect(fn() => $transferService->transfer($user->id, 'USDT', 'spot', 'spot', '100'))
        ->toThrow(\Exception::class, 'Cannot transfer to same account type');
});

test('transfer service maintains balance conservation', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $transferService = new TransferService($ledgerService);

    // Transfer spot to contract
    $transferService->transfer($user->id, 'USDT', 'spot', 'contract', '300');
    
    // Transfer back contract to spot
    $transferService->transfer($user->id, 'USDT', 'contract', 'spot', '300');

    // Total balance should remain 1000
    $totalBalance = \App\Services\Assets\AssetsService::class;
    $assetsService = app($totalBalance);
    $total = $assetsService->getTotalBalance($user->id);

    expect($total->toString())->toBe('1000.000000');
});

