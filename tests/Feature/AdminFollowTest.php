<?php

use App\Models\FollowWindow;
use App\Models\InviteToken;
use App\Models\Symbol;
use App\Models\User;

test('admin can create a follow window', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/follow-window', [
            'symbol_id' => $symbol->id,
            'window_type' => 'fixed_daily',
            'start_at' => '2025-11-06 13:00:00',
            'expire_at' => '2025-11-06 14:00:00',
            'reward_rate_min' => 0.5,
            'reward_rate_max' => 0.6,
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'window' => [
                'id',
                'symbol_id',
                'window_type',
                'start_at',
                'expire_at',
            ],
        ]);

    expect($response->json('window.window_type'))->toBe('fixed_daily');
    
    $this->assertDatabaseHas('follow_windows', [
        'symbol_id' => $symbol->id,
        'window_type' => 'fixed_daily',
        'status' => 'active',
    ]);
});

test('admin can create follow window with default reward rates', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/follow-window', [
            'symbol_id' => $symbol->id,
            'window_type' => 'newbie_bonus',
            'start_at' => '2025-11-06 12:00:00',
            'expire_at' => '2025-11-06 13:00:00',
        ]);

    $response->assertStatus(201);
    
    $window = FollowWindow::find($response->json('window.id'));
    expect($window->reward_rate_min)->toBe('0.5000');
    expect($window->reward_rate_max)->toBe('0.6000');
});

test('admin cannot create follow window with invalid symbol', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/follow-window', [
            'symbol_id' => 99999,
            'window_type' => 'fixed_daily',
            'start_at' => '2025-11-06 13:00:00',
            'expire_at' => '2025-11-06 14:00:00',
        ]);

    $response->assertStatus(422);
});

test('admin cannot create follow window with invalid window type', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/follow-window', [
            'symbol_id' => $symbol->id,
            'window_type' => 'invalid_type',
            'start_at' => '2025-11-06 13:00:00',
            'expire_at' => '2025-11-06 14:00:00',
        ]);

    $response->assertStatus(422);
});

test('admin can create an invite token for a window', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    
    $window = FollowWindow::create([
        'symbol_id' => $symbol->id,
        'window_type' => 'fixed_daily',
        'start_at' => now()->addHour(),
        'expire_at' => now()->addHours(2),
        'reward_rate_min' => 0.5,
        'reward_rate_max' => 0.6,
        'status' => 'active',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/invite-token', [
            'follow_window_id' => $window->id,
            'token' => 'ABCD1234',
            'valid_after' => $window->start_at->toDateTimeString(),
            'valid_before' => $window->expire_at->toDateTimeString(),
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'token' => [
                'id',
                'token',
                'follow_window_id',
                'valid_after',
                'valid_before',
            ],
        ]);

    expect($response->json('token.token'))->toBe('ABCD1234');
    expect($response->json('token.follow_window_id'))->toBe($window->id);
    
    $this->assertDatabaseHas('invite_tokens', [
        'follow_window_id' => $window->id,
        'token' => 'ABCD1234',
        'symbol_id' => $symbol->id,
    ]);
});

test('admin can create invite token without specifying token', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    
    $window = FollowWindow::create([
        'symbol_id' => $symbol->id,
        'window_type' => 'fixed_daily',
        'start_at' => now()->addHour(),
        'expire_at' => now()->addHours(2),
        'reward_rate_min' => 0.5,
        'reward_rate_max' => 0.6,
        'status' => 'active',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/invite-token', [
            'follow_window_id' => $window->id,
            'valid_after' => $window->start_at->toDateTimeString(),
            'valid_before' => $window->expire_at->toDateTimeString(),
        ]);

    $response->assertStatus(201);
    
    $inviteToken = InviteToken::find($response->json('token.id'));
    expect($inviteToken->token)->not->toBeEmpty();
    expect(strlen($inviteToken->token))->toBe(8); // Default token length
});

test('admin follow window creation creates audit log', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/follow-window', [
            'symbol_id' => $symbol->id,
            'window_type' => 'fixed_daily',
            'start_at' => '2025-11-06 13:00:00',
            'expire_at' => '2025-11-06 14:00:00',
        ]);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'follow_window_create',
        'resource' => 'follow_window',
    ]);
});

test('admin invite token creation creates audit log', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    
    $window = FollowWindow::create([
        'symbol_id' => $symbol->id,
        'window_type' => 'fixed_daily',
        'start_at' => now()->addHour(),
        'expire_at' => now()->addHours(2),
        'reward_rate_min' => 0.5,
        'reward_rate_max' => 0.6,
        'status' => 'active',
    ]);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/invite-token', [
            'follow_window_id' => $window->id,
            'token' => 'TEST1234',
            'valid_after' => $window->start_at->toDateTimeString(),
            'valid_before' => $window->expire_at->toDateTimeString(),
        ]);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'invite_token_create',
        'resource' => 'invite_token',
    ]);
});

test('non-admin cannot access admin follow endpoints', function () {
    $user = User::factory()->create(['role' => 'user']);
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/follow-window', []);

    $response->assertStatus(403);
});

