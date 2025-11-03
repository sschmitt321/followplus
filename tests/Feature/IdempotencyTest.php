<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('idempotency middleware returns cached response for duplicate requests', function () {
    $idempotencyKey = 'test-key-' . uniqid();

    // First request
    $response1 = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/register', [
            'email' => 'test1@example.com',
            'password' => 'password123',
        ]);

    $response1->assertStatus(200);
    $accessToken1 = $response1->json('access');

    // Second request with same idempotency key
    $response2 = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/register', [
            'email' => 'test2@example.com',
            'password' => 'password123',
        ]);

    $response2->assertStatus(200);
    $accessToken2 = $response2->json('access');

    // Should return same response
    expect($accessToken1)->toBe($accessToken2);

    // Should only create one user (first email)
    expect(User::where('email', 'test1@example.com')->exists())->toBeTrue();
    expect(User::where('email', 'test2@example.com')->exists())->toBeFalse();
});

test('idempotency middleware requires idempotency key for POST requests', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(400)
        ->assertJson([
            'error' => 'Idempotency-Key header is required',
        ]);
});

test('idempotency middleware allows GET requests without key', function () {
    $user = User::factory()->create();
    $token = auth('api')->login($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me');

    $response->assertStatus(200);
});

test('idempotency middleware caches response for 24 hours', function () {
    $idempotencyKey = 'test-key-' . uniqid();
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password_hash' => Hash::make('password123'),
    ]);

    // First request
    $response1 = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

    $response1->assertStatus(200);

    // Check cache exists
    $cacheKey = "idempotency:{$idempotencyKey}";
    expect(cache()->has($cacheKey))->toBeTrue();

    // Second request should return cached response
    $response2 = $this->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

    $response2->assertStatus(200);
    expect($response1->json('access'))->toBe($response2->json('access'));
});

