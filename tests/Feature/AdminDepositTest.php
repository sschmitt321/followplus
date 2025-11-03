<?php

use App\Models\Currency;
use App\Models\Deposit;
use App\Models\User;
use App\Services\Deposit\DepositService;
use App\Services\Ledger\LedgerService;

test('admin can get all deposits', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    
    Currency::factory()->create(['name' => 'USDT']);
    
    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    
    $depositService->manualApply($user1->id, 'USDT', '100');
    $depositService->manualApply($user2->id, 'USDT', '200');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/deposits');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'deposits' => [
                '*' => [
                    'id',
                    'user_id',
                    'user_email',
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

test('admin can filter deposits by status', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    
    Currency::factory()->create(['name' => 'USDT']);
    
    // Create pending deposit
    Deposit::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount' => '100',
        'status' => 'pending',
    ]);
    
    // Create confirmed deposit
    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '200');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/deposits?status=pending');

    $response->assertStatus(200);
    expect($response->json('deposits'))->toHaveCount(1);
    expect($response->json('deposits.0.status'))->toBe('pending');
});

test('admin can filter deposits by user_id', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    
    Currency::factory()->create(['name' => 'USDT']);
    
    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    
    $depositService->manualApply($user1->id, 'USDT', '100');
    $depositService->manualApply($user2->id, 'USDT', '200');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/admin/deposits?user_id={$user1->id}");

    $response->assertStatus(200);
    expect($response->json('deposits'))->toHaveCount(1);
    expect($response->json('deposits.0.user_id'))->toBe($user1->id);
});

test('admin can confirm a pending deposit', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    
    $deposit = Deposit::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount' => '100',
        'status' => 'pending',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/deposits/{$deposit->id}/confirm", [
            'txid' => '0x1234567890abcdef',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'deposit' => [
                'id',
                'status',
            ],
        ]);

    expect($response->json('deposit.status'))->toBe('confirmed');
    
    // Verify deposit was updated
    $deposit->refresh();
    expect($deposit->status)->toBe('confirmed');
    expect($deposit->txid)->toBe('0x1234567890abcdef');
    expect($deposit->confirmed_at)->not->toBeNull();
});

test('admin can confirm deposit without txid', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    
    $deposit = Deposit::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount' => '100',
        'status' => 'pending',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/deposits/{$deposit->id}/confirm");

    $response->assertStatus(200);
    
    $deposit->refresh();
    expect($deposit->status)->toBe('confirmed');
});

test('admin cannot confirm already processed deposit', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    
    $deposit = Deposit::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount' => '100',
        'status' => 'confirmed',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/deposits/{$deposit->id}/confirm");

    $response->assertStatus(400)
        ->assertJson([
            'error' => 'Deposit already processed',
        ]);
});

test('non-admin cannot access admin deposit endpoints', function () {
    $user = User::factory()->create(['role' => 'user']);
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/deposits');

    $response->assertStatus(403);
});

test('unauthenticated user cannot access admin deposit endpoints', function () {
    $response = $this->getJson('/api/v1/admin/deposits');
    $response->assertStatus(401);
});

test('admin deposit confirmation creates audit log', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    
    $deposit = Deposit::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount' => '100',
        'status' => 'pending',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/deposits/{$deposit->id}/confirm");

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'deposit_confirm',
        'resource' => 'deposit',
    ]);
});

