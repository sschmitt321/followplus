<?php

use App\Models\Account;
use App\Models\Currency;
use App\Models\User;
use App\Models\UserAssetsSummary;
use App\Services\Assets\AssetsService;
use App\Services\Ledger\LedgerService;

test('assets service calculates total balance correctly', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    Currency::factory()->create(['name' => 'BTC']);

    $ledgerService = new LedgerService();
    $assetsService = new AssetsService();

    // Credit multiple currencies
    $ledgerService->credit($user->id, 'spot', 'USDT', '1000', 'deposit');
    $ledgerService->credit($user->id, 'spot', 'BTC', '0.5', 'deposit');
    $ledgerService->credit($user->id, 'contract', 'USDT', '500', 'deposit');

    $totalBalance = $assetsService->getTotalBalance($user->id);

    // Total should be sum of all accounts
    expect($totalBalance->toString())->toBe('1500.500000');
});

test('assets service updates summary correctly', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $assetsService = new AssetsService();

    // Credit account
    $ledgerService->credit($user->id, 'spot', 'USDT', '1000', 'deposit');

    // Update summary
    $summary = $assetsService->updateSummary($user->id);

    expect($summary)->toBeInstanceOf(UserAssetsSummary::class);
    expect($summary->total_balance->toString())->toBe('1000.000000');
    expect($summary->principal_balance->toString())->toBe('1000.000000');
});

test('assets service creates summary if not exists', function () {
    $user = User::factory()->create();

    $assetsService = new AssetsService();
    $summary = $assetsService->getSummary($user->id);

    expect($summary)->toBeInstanceOf(UserAssetsSummary::class);
    expect($summary->total_balance->toString())->toBe('0.000000');
});

test('assets summary matches account balances', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $assetsService = new AssetsService();

    // Create accounts with balances
    $ledgerService->credit($user->id, 'spot', 'USDT', '1000', 'deposit');
    $ledgerService->credit($user->id, 'contract', 'USDT', '500', 'deposit');

    // Update summary
    $summary = $assetsService->updateSummary($user->id);

    // Verify total balance matches sum of accounts
    $totalFromAccounts = Account::where('user_id', $user->id)
        ->get()
        ->reduce(function ($carry, $account) {
            return $carry->add($account->available)->add($account->frozen);
        }, \App\Support\Decimal::zero());

    expect($summary->total_balance->toString())->toBe($totalFromAccounts->toString());
});

