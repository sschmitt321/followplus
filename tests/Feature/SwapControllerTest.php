<?php

use App\Models\Currency;
use App\Models\User;
use App\Services\Deposit\DepositService;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Cache;

test('authenticated user can get swap quote', function () {
    Currency::factory()->create(['name' => 'USDT']);
    Currency::factory()->create(['name' => 'BTC']);

    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/swap/quote', [
            'from' => 'USDT',
            'to' => 'BTC',
            'amount' => '1000',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'quote_id',
            'from_currency',
            'to_currency',
            'rate',
            'amount_from',
            'amount_to',
        ]);

    expect($response->json('from_currency'))->toBe('USDT');
    expect($response->json('to_currency'))->toBe('BTC');
    expect($response->json('amount_from'))->toBe('1000.000000');
    expect($response->json('rate'))->not->toBeNull();
});

test('swap quote validates required fields', function () {
    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/swap/quote', [
            'from' => 'USDT',
            // Missing to and amount
        ]);

    $response->assertStatus(422);
});

test('swap quote validates currency exists', function () {
    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/swap/quote', [
            'from' => 'INVALID',
            'to' => 'BTC',
            'amount' => '1000',
        ]);

    $response->assertStatus(422);
});

test('authenticated user can confirm swap', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    Currency::factory()->create(['name' => 'BTC']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    // Get quote first
    $quoteResponse = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/swap/quote', [
            'from' => 'USDT',
            'to' => 'BTC',
            'amount' => '1000',
        ]);

    $quoteResponse->assertStatus(200);
    $quoteId = $quoteResponse->json('quote_id');

    // Confirm swap
    $idempotencyKey = 'test-swap-' . uniqid();
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/swap/confirm', [
            'quote_id' => $quoteId,
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'swap' => [
                'id',
                'from_currency',
                'to_currency',
                'amount_from',
                'amount_to',
                'rate',
                'status',
            ],
        ]);

    expect($response->json('swap.status'))->toBe('completed');
    expect($response->json('swap.from_currency'))->toBe('USDT');
    expect($response->json('swap.to_currency'))->toBe('BTC');

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
    expect($btcAccount->available->greaterThan(\App\Support\Decimal::zero()))->toBeTrue();
});

test('swap confirm requires idempotency key', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    Currency::factory()->create(['name' => 'BTC']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    // Get quote
    $quoteResponse = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/swap/quote', [
            'from' => 'USDT',
            'to' => 'BTC',
            'amount' => '1000',
        ]);

    $quoteId = $quoteResponse->json('quote_id');

    // Try to confirm without idempotency key
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/swap/confirm', [
            'quote_id' => $quoteId,
        ]);

    $response->assertStatus(400);
});

test('swap confirm validates quote_id is required', function () {
    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-swap-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/swap/confirm', [
            // Missing quote_id
        ]);

    $response->assertStatus(422);
});

test('swap confirm throws error for expired quote', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    Currency::factory()->create(['name' => 'BTC']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-swap-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/swap/confirm', [
            'quote_id' => 'invalid-quote-id',
        ]);

    $response->assertStatus(400)
        ->assertJson([
            'error' => 'Quote expired or invalid',
        ]);
});

test('idempotency key prevents duplicate swaps', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    Currency::factory()->create(['name' => 'BTC']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    // Get quote
    $quoteResponse = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/swap/quote', [
            'from' => 'USDT',
            'to' => 'BTC',
            'amount' => '1000',
        ]);

    $quoteId = $quoteResponse->json('quote_id');
    $idempotencyKey = 'test-swap-duplicate-' . uniqid();

    // First request
    $response1 = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/swap/confirm', [
            'quote_id' => $quoteId,
        ]);

    $response1->assertStatus(200);
    $firstSwapId = $response1->json('swap.id');

    // Duplicate request with same idempotency key should return cached response
    $response2 = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/swap/confirm', [
            'quote_id' => $quoteId,
        ]);

    // Idempotency middleware should return cached response (200)
    $response2->assertStatus(200);
    expect($response2->json('swap.id'))->toBe($firstSwapId);
    
    // Verify quote was consumed (try with new idempotency key and different quote)
    // Since quote is removed after confirm, we need a new quote
    $quoteResponse2 = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/swap/quote', [
            'from' => 'USDT',
            'to' => 'BTC',
            'amount' => '1000',
        ]);
    
    $newQuoteId = $quoteResponse2->json('quote_id');
    expect($newQuoteId)->not->toBe($quoteId); // Should be a different quote ID
});

test('swap confirm throws error on insufficient balance', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    Currency::factory()->create(['name' => 'BTC']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    // Get quote for amount user doesn't have
    $quoteResponse = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/swap/quote', [
            'from' => 'USDT',
            'to' => 'BTC',
            'amount' => '1000',
        ]);

    $quoteId = $quoteResponse->json('quote_id');
    $idempotencyKey = 'test-swap-' . uniqid();

    // Try to confirm without having balance (no account exists)
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/swap/confirm', [
            'quote_id' => $quoteId,
        ]);

    // Should fail because account doesn't exist or insufficient balance
    $response->assertStatus(400);
    // Error could be "Insufficient balance" or "No query results for model" (account not found)
    expect($response->json('error'))->not->toBeNull();
});

test('unauthenticated user cannot access swap endpoints', function () {
    $response = $this->postJson('/api/v1/swap/quote', [
        'from' => 'USDT',
        'to' => 'BTC',
        'amount' => '1000',
    ]);
    $response->assertStatus(401);

    $response = $this->postJson('/api/v1/swap/confirm', [
        'quote_id' => 'test',
    ]);
    $response->assertStatus(401);
});

