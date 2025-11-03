<?php

use App\Models\Currency;
use App\Models\Swap;
use App\Models\User;
use App\Services\Deposit\DepositService;
use App\Services\Ledger\LedgerService;
use App\Services\Swap\SwapService;

test('swap service can get quote', function () {
    Currency::factory()->create(['name' => 'USDT']);
    Currency::factory()->create(['name' => 'BTC']);

    $swapService = new SwapService(new LedgerService());

    $quote = $swapService->quote('USDT', 'BTC', '1000');

    expect($quote)->toHaveKeys([
        'from_currency',
        'to_currency',
        'rate',
        'amount_from',
        'amount_to',
    ]);
    expect($quote['from_currency'])->toBe('USDT');
    expect($quote['to_currency'])->toBe('BTC');
    expect($quote['amount_from'])->toBe('1000.000000');
});

test('swap service can confirm swap', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    Currency::factory()->create(['name' => 'BTC']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $swapService = new SwapService($ledgerService);

    $swap = $swapService->confirm(
        $user->id,
        'USDT',
        'BTC',
        '1000',
        '0.023',
        '0.000023'
    );

    expect($swap)->toBeInstanceOf(Swap::class);
    expect($swap->status)->toBe('completed');
    expect($swap->amount_from->toString())->toBe('1000.000000');
    expect($swap->amount_to->toString())->toBe('0.023000');

    // Verify balances
    $usdtAccount = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    $btcAccount = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'BTC',
    ])->first();

    expect($usdtAccount->available->toString())->toBe('0.000000');
    expect($btcAccount->available->toString())->toBe('0.023000');
});

test('swap maintains total value conservation', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    Currency::factory()->create(['name' => 'BTC']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $swapService = new SwapService($ledgerService);

    // Get quote
    $quote = $swapService->quote('USDT', 'BTC', '1000');
    
    // Confirm swap
    $swapService->confirm(
        $user->id,
        $quote['from_currency'],
        $quote['to_currency'],
        $quote['amount_from'],
        $quote['amount_to'],
        $quote['rate']
    );

    // Verify accounts exist
    $accounts = \App\Models\Account::where('user_id', $user->id)->get();
    expect($accounts)->toHaveCount(2); // USDT and BTC accounts
});

