<?php

use App\Models\Currency;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Deposit\DepositService;
use App\Services\Ledger\LedgerService;

test('authenticated user can get withdrawal history', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $withdrawService = new \App\Services\Withdraw\WithdrawService(
        $ledgerService,
        new \App\Services\Assets\AssetsService(),
        new \App\Services\System\ConfigService()
    );

    $withdrawService->apply($user->id, '100', 'Txxx', 'USDT');
    $withdrawService->apply($user->id, '200', 'Txxx', 'USDT');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/withdrawals');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'withdrawals' => [
                '*' => [
                    'id',
                    'currency',
                    'amount_request',
                    'fee',
                    'amount_actual',
                    'status',
                    'to_address',
                    'txid',
                    'created_at',
                ],
            ],
            'pagination' => [
                'current_page',
                'total_pages',
                'total',
            ],
        ]);

    expect($response->json('withdrawals'))->toHaveCount(2);
});

test('authenticated user can calculate withdrawable amount', function () {
    $user = User::factory()->create([
        'first_joined_at' => now()->subDays(10), // Old user
    ]);
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/withdrawals/calc-withdrawable');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'policy',
            'total_balance',
            'fee',
            'withdrawable',
        ]);

    expect($response->json('policy'))->toBe('old');
    expect($response->json('total_balance'))->toBe('1000.000000');
    expect($response->json('withdrawable'))->toBe('900.000000'); // 1000 - 10% fee
});

test('authenticated user can apply withdrawal', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-withdraw-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/withdrawals/apply', [
            'amount' => '100',
            'to_address' => 'Txxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'currency' => 'USDT',
            'withdraw_password' => '123456',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'withdrawal' => [
                'id',
                'currency',
                'amount_request',
                'fee',
                'amount_actual',
                'status',
            ],
        ]);

    expect($response->json('withdrawal.status'))->toBe('pending');
    expect($response->json('withdrawal.currency'))->toBe('USDT');

    // Verify withdrawal was created
    $this->assertDatabaseHas('withdrawals', [
        'user_id' => $user->id,
        'currency' => 'USDT',
        'status' => 'pending',
    ]);

    // Verify balance is frozen
    $account = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    expect($account->frozen->toString())->toBe('100.000000');
});

test('apply withdrawal validates required fields', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-withdraw-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/withdrawals/apply', [
            'amount' => '100',
            // Missing to_address and withdraw_password
        ]);

    $response->assertStatus(422);
});

test('apply withdrawal requires idempotency key', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/withdrawals/apply', [
            'amount' => '100',
            'to_address' => 'Txxx',
            'withdraw_password' => '123456',
        ]);

    $response->assertStatus(400);
});

test('apply withdrawal throws error on insufficient balance', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '100');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-withdraw-' . uniqid();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/withdrawals/apply', [
            'amount' => '200',
            'to_address' => 'Txxx',
            'withdraw_password' => '123456',
        ]);

    $response->assertStatus(400)
        ->assertJson([
            'error' => 'Insufficient balance',
        ]);
});

test('idempotency key prevents duplicate withdrawals', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);
    $idempotencyKey = 'test-withdraw-duplicate-' . uniqid();

    // First request
    $response1 = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/withdrawals/apply', [
            'amount' => '100',
            'to_address' => 'Txxx',
            'withdraw_password' => '123456',
        ]);

    $response1->assertStatus(200);
    $firstWithdrawalId = $response1->json('withdrawal.id');

    // Duplicate request
    $response2 = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/withdrawals/apply', [
            'amount' => '100',
            'to_address' => 'Txxx',
            'withdraw_password' => '123456',
        ]);

    $response2->assertStatus(200);
    expect($response2->json('withdrawal.id'))->toBe($firstWithdrawalId);

    // Should only have one withdrawal
    $withdrawalCount = Withdrawal::where('user_id', $user->id)->count();
    expect($withdrawalCount)->toBe(1);
});

test('calc withdrawable shows correct policy for newbie', function () {
    $user = User::factory()->create([
        'first_joined_at' => now()->subDays(3), // Newbie (within 7 days)
    ]);
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/withdrawals/calc-withdrawable');

    $response->assertStatus(200);
    expect($response->json('policy'))->toBe('newbie');
    expect($response->json('total_balance'))->toBe('1000.000000');
    expect($response->json('withdrawable'))->toBe('900.000000'); // Newbie: 1000 - 10% fee
});

test('withdrawal history pagination works correctly', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '10000');

    $withdrawService = new \App\Services\Withdraw\WithdrawService(
        $ledgerService,
        new \App\Services\Assets\AssetsService(),
        new \App\Services\System\ConfigService()
    );

    // Create 25 withdrawals
    for ($i = 0; $i < 25; $i++) {
        $withdrawService->apply($user->id, '10', 'Txxx', 'USDT');
    }

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/withdrawals?page=1');

    $response->assertStatus(200);
    expect($response->json('withdrawals'))->toHaveCount(20);
    expect($response->json('pagination.total'))->toBe(25);
});

test('unauthenticated user cannot access withdrawals', function () {
    $response = $this->getJson('/api/v1/withdrawals');
    $response->assertStatus(401);

    $response = $this->getJson('/api/v1/withdrawals/calc-withdrawable');
    $response->assertStatus(401);

    $response = $this->postJson('/api/v1/withdrawals/apply', [
        'amount' => '100',
        'to_address' => 'Txxx',
    ]);
    $response->assertStatus(401);
});

