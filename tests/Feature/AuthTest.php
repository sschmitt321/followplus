<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('user can register successfully', function () {
    $idempotencyKey = 'test-register-' . uniqid();
    $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/register', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'access',
            'refresh',
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
    ]);

    // Check profile and KYC are created
    $user = User::where('email', 'test@example.com')->first();
    expect($user->profile)->not->toBeNull();
    expect($user->kyc)->not->toBeNull();
});

test('user can register with invite code', function () {
    $inviter = User::factory()->create([
        'invite_code' => 'INVITE01',
        'ref_path' => '/',
        'ref_depth' => 0,
    ]);

    $idempotencyKey = 'test-register-invite-' . uniqid();
    $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/register', [
            'email' => 'invited@example.com',
            'password' => 'password123',
            'invite_code' => 'INVITE01',
        ]);

    $response->assertStatus(200);

    $user = User::where('email', 'invited@example.com')->first();
    expect($user->invited_by_user_id)->toBe($inviter->id);
    expect($user->ref_depth)->toBe(1);
});

test('user can register with empty string invite_code (should be treated as null)', function () {
    $idempotencyKey = 'test-register-empty-invite-' . uniqid();
    $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/register', [
            'email' => 'noinvite@example.com',
            'password' => 'password123',
            'invite_code' => '', // Empty string should be treated as null
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'access',
            'refresh',
        ]);

    $user = User::where('email', 'noinvite@example.com')->first();
    expect($user->invited_by_user_id)->toBeNull();
    expect($user->ref_depth)->toBe(0);
});

test('user cannot register with invalid invite_code', function () {
    $idempotencyKey = 'test-register-invalid-invite-' . uniqid();
    $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/register', [
            'email' => 'invalidinvite@example.com',
            'password' => 'password123',
            'invite_code' => 'INVALID_CODE',
        ]);

    $response->assertStatus(422)
        ->assertJsonStructure([
            'error',
            'errors' => [
                'invite_code',
            ],
        ]);
});

test('user cannot register with duplicate email', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    $idempotencyKey = 'test-register-duplicate-' . uniqid();
    $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/register', [
            'email' => 'existing@example.com',
            'password' => 'password123',
        ]);

    $response->assertStatus(422);
});

test('user can login successfully', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password_hash' => Hash::make('password123', ['memory' => 65536, 'time' => 4, 'threads' => 3]),
    ]);

    $idempotencyKey = 'test-login-' . uniqid();
    $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'access',
            'refresh',
        ]);
});

test('user cannot login with invalid credentials', function () {
    User::factory()->create([
        'email' => 'test@example.com',
        'password_hash' => Hash::make('password123'),
    ]);

    $idempotencyKey = 'test-login-invalid-' . uniqid();
    $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

    $response->assertStatus(401);
});

test('user can refresh access token', function () {
    $user = User::factory()->create();
    $refreshToken = 'test_refresh_token_' . bin2hex(random_bytes(32));
    
    // Store refresh token in cache as AuthService does
    \Illuminate\Support\Facades\Cache::put("refresh_token:{$refreshToken}", $user->id, now()->addDays(30));

    $idempotencyKey = 'test-refresh-' . uniqid();
    $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/refresh', [
            'refresh' => $refreshToken,
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'access',
        ]);
    
    // Verify new access token is returned
    expect($response->json('access'))->not->toBeEmpty();
});

test('user cannot refresh with invalid token', function () {
    $idempotencyKey = 'test-refresh-invalid-' . uniqid();
    $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/refresh', [
            'refresh' => 'invalid_token',
        ]);

    $response->assertStatus(401);
});

test('user can get own information when authenticated', function () {
    $user = User::factory()->create();
    $user->profile()->create(['name' => 'Test User']);
    $user->kyc()->create(['level' => 'basic', 'status' => 'pending']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'user',
            'profile',
            'kyc',
            'role',
        ]);
});

