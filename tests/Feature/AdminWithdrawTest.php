<?php

use App\Models\Currency;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Decimal;

test('admin can get all withdrawals', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    
    Currency::factory()->create(['name' => 'USDT']);
    
    Withdrawal::create([
        'user_id' => $user1->id,
        'currency' => 'USDT',
        'amount_request' => Decimal::of('100'),
        'fee' => Decimal::of('0'),
        'amount_actual' => Decimal::of('100'),
        'status' => 'pending',
        'to_address' => '0x123',
    ]);
    
    Withdrawal::create([
        'user_id' => $user2->id,
        'currency' => 'USDT',
        'amount_request' => Decimal::of('200'),
        'fee' => Decimal::of('0'),
        'amount_actual' => Decimal::of('200'),
        'status' => 'approved',
        'to_address' => '0x456',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/withdrawals');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'withdrawals' => [
                '*' => [
                    'id',
                    'user_id',
                    'user_email',
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

test('admin can filter withdrawals by status', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    
    Currency::factory()->create(['name' => 'USDT']);
    
    Withdrawal::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount_request' => Decimal::of('100'),
        'fee' => Decimal::of('0'),
        'amount_actual' => Decimal::of('100'),
        'status' => 'pending',
        'to_address' => '0x123',
    ]);
    
    Withdrawal::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount_request' => Decimal::of('200'),
        'fee' => Decimal::of('0'),
        'amount_actual' => Decimal::of('200'),
        'status' => 'approved',
        'to_address' => '0x456',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/withdrawals?status=pending');

    $response->assertStatus(200);
    expect($response->json('withdrawals'))->toHaveCount(1);
    expect($response->json('withdrawals.0.status'))->toBe('pending');
});

test('admin can approve a withdrawal', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    
    $withdrawal = Withdrawal::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount_request' => Decimal::of('100'),
        'fee' => Decimal::of('0'),
        'amount_actual' => Decimal::of('100'),
        'status' => 'pending',
        'to_address' => '0x123',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/approve");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'withdrawal' => [
                'id',
                'status',
            ],
        ]);

    expect($response->json('withdrawal.status'))->toBe('approved');
    
    $withdrawal->refresh();
    expect($withdrawal->status)->toBe('approved');
});

test('admin can reject a withdrawal', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    
    // Create account with balance and freeze for withdrawal
    $account = \App\Models\Account::create([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
        'available' => Decimal::of('200'),
        'frozen' => Decimal::of('100'),
    ]);
    
    $withdrawal = Withdrawal::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount_request' => Decimal::of('100'),
        'fee' => Decimal::of('0'),
        'amount_actual' => Decimal::of('100'),
        'status' => 'pending',
        'to_address' => '0x123',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/reject");

    $response->assertStatus(200);
    
    $withdrawal->refresh();
    expect($withdrawal->status)->toBe('rejected');
});

test('admin can mark withdrawal as paid', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    
    // Create account with balance and freeze for withdrawal
    $account = \App\Models\Account::create([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
        'available' => Decimal::of('200'),
        'frozen' => Decimal::of('100'),
    ]);
    
    $withdrawal = Withdrawal::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount_request' => Decimal::of('100'),
        'fee' => Decimal::of('0'),
        'amount_actual' => Decimal::of('100'),
        'status' => 'approved',
        'to_address' => '0x123',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/mark-paid", [
            'txid' => '0xabcdef123456',
        ]);

    $response->assertStatus(200);
    
    $withdrawal->refresh();
    expect($withdrawal->status)->toBe('paid');
    expect($withdrawal->txid)->toBe('0xabcdef123456');
});

test('admin can mark withdrawal as paid without txid', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    
    // Create account with balance and freeze for withdrawal
    $account = \App\Models\Account::create([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
        'available' => Decimal::of('200'),
        'frozen' => Decimal::of('100'),
    ]);
    
    $withdrawal = Withdrawal::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount_request' => Decimal::of('100'),
        'fee' => Decimal::of('0'),
        'amount_actual' => Decimal::of('100'),
        'status' => 'approved',
        'to_address' => '0x123',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/mark-paid");

    $response->assertStatus(200);
    
    $withdrawal->refresh();
    expect($withdrawal->status)->toBe('paid');
});

test('non-admin cannot access admin withdrawal endpoints', function () {
    $user = User::factory()->create(['role' => 'user']);
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/withdrawals');

    $response->assertStatus(403);
});

test('admin withdrawal operations create audit logs', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);
    
    // Create account with balance
    $account = \App\Models\Account::create([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
        'available' => Decimal::of('200'),
        'frozen' => Decimal::of('0'),
    ]);
    
    $withdrawal = Withdrawal::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount_request' => Decimal::of('100'),
        'fee' => Decimal::of('0'),
        'amount_actual' => Decimal::of('100'),
        'status' => 'pending',
        'to_address' => '0x123',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    // Test approve audit log
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/approve");

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'withdraw_approve',
        'resource' => 'withdrawal',
    ]);

    // Test reject audit log - create new withdrawal
    $withdrawal2 = Withdrawal::create([
        'user_id' => $user->id,
        'currency' => 'USDT',
        'amount_request' => Decimal::of('50'),
        'fee' => Decimal::of('0'),
        'amount_actual' => Decimal::of('50'),
        'status' => 'pending',
        'to_address' => '0x456',
    ]);
    $account->update(['frozen' => Decimal::of('50')]);
    
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/withdrawals/{$withdrawal2->id}/reject");

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'withdraw_reject',
        'resource' => 'withdrawal',
    ]);

    // Test mark paid audit log
    $account->update(['frozen' => Decimal::of('100')]);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/mark-paid");

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'withdraw_paid',
        'resource' => 'withdrawal',
    ]);
});

