<?php

use App\Models\Currency;
use App\Models\User;
use App\Services\Deposit\DepositService;
use App\Services\Ledger\LedgerService;

test('authenticated user can transfer between account types', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-transfer-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/transfer', [
            'from' => 'spot',
            'to' => 'contract',
            'amount' => '500',
            'currency' => 'USDT',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'transfer' => [
                'id',
                'currency',
                'from_type',
                'to_type',
                'amount',
                'status',
            ],
        ]);

    expect($response->json('transfer.status'))->toBe('completed');
    expect($response->json('transfer.amount'))->toBe('500.000000');
    expect($response->json('transfer.from_type'))->toBe('spot');
    expect($response->json('transfer.to_type'))->toBe('contract');

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

test('transfer validates required fields', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-transfer-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/transfer', [
            'from' => 'spot',
            // Missing to, amount, currency
        ]);

    $response->assertStatus(422);
});

test('transfer requires idempotency key', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/transfer', [
            'from' => 'spot',
            'to' => 'contract',
            'amount' => '100',
            'currency' => 'USDT',
        ]);

    $response->assertStatus(400);
});

test('transfer throws error when from and to are same', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-transfer-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/transfer', [
            'from' => 'spot',
            'to' => 'spot',
            'amount' => '100',
            'currency' => 'USDT',
        ]);

    $response->assertStatus(400)
        ->assertJson([
            'error' => 'Cannot transfer to same account type',
        ]);
});

test('transfer throws error on insufficient balance', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '100');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-transfer-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/transfer', [
            'from' => 'spot',
            'to' => 'contract',
            'amount' => '200',
            'currency' => 'USDT',
        ]);

    $response->assertStatus(400)
        ->assertJson([
            'error' => 'Insufficient balance',
        ]);
});

test('idempotency key prevents duplicate transfers', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-transfer-duplicate-' . uniqid();

    // First request
    $response1 = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/transfer', [
            'from' => 'spot',
            'to' => 'contract',
            'amount' => '500',
            'currency' => 'USDT',
        ]);

    $response1->assertStatus(200);
    $firstTransferId = $response1->json('transfer.id');

    // Duplicate request
    $response2 = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/transfer', [
            'from' => 'spot',
            'to' => 'contract',
            'amount' => '500',
            'currency' => 'USDT',
        ]);

    $response2->assertStatus(200);
    expect($response2->json('transfer.id'))->toBe($firstTransferId);

    // Verify balances didn't change twice
    $spotAccount = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    expect($spotAccount->available->toString())->toBe('500.000000');
});

test('transfer can reverse direction', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    // Transfer spot to contract
    $response1 = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', 'test-transfer-1-' . uniqid())
        ->postJson('/api/v1/transfer', [
            'from' => 'spot',
            'to' => 'contract',
            'amount' => '500',
            'currency' => 'USDT',
        ]);

    $response1->assertStatus(200);

    // Transfer back contract to spot
    $response2 = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', 'test-transfer-2-' . uniqid())
        ->postJson('/api/v1/transfer', [
            'from' => 'contract',
            'to' => 'spot',
            'amount' => '500',
            'currency' => 'USDT',
        ]);

    $response2->assertStatus(200);

    // Verify balances are back to original
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

    expect($spotAccount->available->toString())->toBe('1000.000000');
    expect($contractAccount->available->toString())->toBe('0.000000');
});

test('unauthenticated user cannot transfer', function () {
    $response = $this->postJson('/api/v1/transfer', [
        'from' => 'spot',
        'to' => 'contract',
        'amount' => '100',
        'currency' => 'USDT',
    ]);

    $response->assertStatus(401);
});

