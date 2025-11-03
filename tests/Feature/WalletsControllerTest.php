<?php

use App\Models\Currency;
use App\Models\User;
use App\Services\Deposit\DepositService;
use App\Services\Ledger\LedgerService;

test('authenticated user can get wallet information', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT', 'enabled' => true]);
    Currency::factory()->create(['name' => 'BTC', 'enabled' => true]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/wallets');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'wallets' => [
                '*' => [
                    'currency',
                    'precision',
                    'spot' => ['available', 'frozen'],
                    'contract' => ['available', 'frozen'],
                    'deposit_address',
                    'deposit_memo',
                ],
            ],
            'summary' => [
                'total_balance',
                'principal_balance',
                'profit_balance',
                'bonus_balance',
            ],
        ]);

    // Should have wallets for all enabled currencies
    expect($response->json('wallets'))->toHaveCount(2);
});

test('wallet shows correct balances after deposit', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT', 'enabled' => true]);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    // Update assets summary to ensure it's current
    $assetsService = new \App\Services\Assets\AssetsService();
    $assetsService->updateSummary($user->id);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/wallets');

    $response->assertStatus(200);

    $usdtWallet = collect($response->json('wallets'))->firstWhere('currency', 'USDT');
    expect($usdtWallet['spot']['available'])->toBe('1000.000000');
    expect($usdtWallet['spot']['frozen'])->toBe('0.000000');
    expect($response->json('summary.total_balance'))->toBe('1000.000000');
});

test('wallet shows frozen balance correctly', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT', 'enabled' => true]);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    // Freeze some balance
    $ledgerService->freeze($user->id, 'spot', 'USDT', '300', 'withdraw');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/wallets');

    $response->assertStatus(200);

    $usdtWallet = collect($response->json('wallets'))->firstWhere('currency', 'USDT');
    expect($usdtWallet['spot']['available'])->toBe('700.000000');
    expect($usdtWallet['spot']['frozen'])->toBe('300.000000');
});

test('wallet shows multiple currencies correctly', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT', 'enabled' => true]);
    Currency::factory()->create(['name' => 'BTC', 'enabled' => true]);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');
    $depositService->manualApply($user->id, 'BTC', '0.5');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/wallets');

    $response->assertStatus(200);

    $usdtWallet = collect($response->json('wallets'))->firstWhere('currency', 'USDT');
    $btcWallet = collect($response->json('wallets'))->firstWhere('currency', 'BTC');

    expect($usdtWallet['spot']['available'])->toBe('1000.000000');
    expect($btcWallet['spot']['available'])->toBe('0.500000');
});

test('unauthenticated user cannot access wallets', function () {
    $response = $this->getJson('/api/v1/wallets');

    $response->assertStatus(401);
});

test('wallet shows zero balances for new user', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT', 'enabled' => true]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/wallets');

    $response->assertStatus(200);

    $usdtWallet = collect($response->json('wallets'))->firstWhere('currency', 'USDT');
    expect($usdtWallet['spot']['available'])->toBe('0.000000');
    expect($usdtWallet['spot']['frozen'])->toBe('0.000000');
    expect($usdtWallet['contract']['available'])->toBe('0.000000');
    expect($usdtWallet['contract']['frozen'])->toBe('0.000000');
    expect($response->json('summary.total_balance'))->toBe('0.000000');
});

