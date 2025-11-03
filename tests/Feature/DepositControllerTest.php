<?php

use App\Models\Currency;
use App\Models\Deposit;
use App\Models\User;
use App\Services\Deposit\DepositService;
use App\Services\Ledger\LedgerService;

test('authenticated user can get deposit history', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '100');
    $depositService->manualApply($user->id, 'USDT', '200');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/deposits');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'deposits' => [
                '*' => [
                    'id',
                    'currency',
                    'amount',
                    'status',
                    'txid',
                    'confirmed_at',
                    'created_at',
                ],
            ],
            'pagination' => [
                'current_page',
                'total_pages',
                'total',
            ],
        ]);

    expect($response->json('deposits'))->toHaveCount(2);
    expect($response->json('pagination.total'))->toBe(2);
});

test('authenticated user can manually apply deposit', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-deposit-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/deposits/manual-apply', [
            'amount' => '500.50',
            'currency' => 'USDT',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'deposit' => [
                'id',
                'currency',
                'amount',
                'status',
            ],
        ]);

    expect($response->json('deposit.status'))->toBe('confirmed');
    expect($response->json('deposit.amount'))->toBe('500.500000');
    expect($response->json('deposit.currency'))->toBe('USDT');

    // Verify deposit was created
    $this->assertDatabaseHas('deposits', [
        'user_id' => $user->id,
        'currency' => 'USDT',
        'status' => 'confirmed',
    ]);

    // Verify account balance was updated
    $account = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    expect($account->available->toString())->toBe('500.500000');
});

test('manual apply deposit requires idempotency key', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/deposits/manual-apply', [
            'amount' => '100',
            'currency' => 'USDT',
        ]);

    $response->assertStatus(400);
});

test('manual apply deposit validates required fields', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-deposit-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/deposits/manual-apply', [
            'amount' => '100',
            // Missing currency
        ]);

    $response->assertStatus(422);
});

test('manual apply deposit validates currency exists', function () {
    $user = User::factory()->create();

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-deposit-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/deposits/manual-apply', [
            'amount' => '100',
            'currency' => 'INVALID',
        ]);

    $response->assertStatus(422);
});

test('idempotency key prevents duplicate deposits', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-deposit-duplicate-' . uniqid();

    // First request
    $response1 = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/deposits/manual-apply', [
            'amount' => '100',
            'currency' => 'USDT',
        ]);

    $response1->assertStatus(200);
    $firstDepositId = $response1->json('deposit.id');

    // Duplicate request with same idempotency key
    $response2 = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/deposits/manual-apply', [
            'amount' => '100',
            'currency' => 'USDT',
        ]);

    $response2->assertStatus(200);
    expect($response2->json('deposit.id'))->toBe($firstDepositId);

    // Should only have one deposit
    $depositCount = Deposit::where('user_id', $user->id)->count();
    expect($depositCount)->toBe(1);
});

test('deposit history pagination works correctly', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);

    // Create 25 deposits
    for ($i = 0; $i < 25; $i++) {
        $depositService->manualApply($user->id, 'USDT', '10');
    }

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/deposits?page=1');

    $response->assertStatus(200);
    expect($response->json('deposits'))->toHaveCount(20); // Default per page
    expect($response->json('pagination.total'))->toBe(25);
    expect($response->json('pagination.total_pages'))->toBe(2);
});

test('unauthenticated user cannot access deposits', function () {
    $response = $this->getJson('/api/v1/deposits');
    $response->assertStatus(401);

    $response = $this->postJson('/api/v1/deposits/manual-apply', [
        'amount' => '100',
        'currency' => 'USDT',
    ]);
    $response->assertStatus(401);
});

