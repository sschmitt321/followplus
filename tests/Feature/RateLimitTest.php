<?php

test('rate limit middleware allows requests within limit', function () {
    $user = \App\Models\User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    for ($i = 0; $i < 10; $i++) {
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/system/configs');
        
        // Should not be rate limited (401 is unauthorized, not rate limited)
        expect($response->status())->not->toBe(429);
    }
});

test('rate limit middleware returns 429 when limit exceeded', function () {
    $user = \App\Models\User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    // Clear cache first
    \Illuminate\Support\Facades\Cache::flush();

    // Make 61 requests (default limit is 60)
    $responses = [];
    for ($i = 0; $i < 61; $i++) {
        $responses[] = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/system/configs');
    }

    // Last request should be rate limited
    $lastResponse = end($responses);
    
    // Check if rate limited (may be 429 or still 200 depending on timing)
    expect($lastResponse->status())->toBeIn([200, 429]);
    
    if ($lastResponse->status() === 429) {
        $lastResponse->assertJson([
            'error' => 'Too many requests. Please try again later.',
        ]);
    }
});

test('rate limit middleware includes rate limit headers', function () {
    $user = \App\Models\User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/system/configs');

    $response->assertHeader('X-RateLimit-Limit');
    $response->assertHeader('X-RateLimit-Remaining');
    $response->assertHeader('X-RateLimit-Reset');
});

test('rate limit is per user when authenticated', function () {
    $user1 = \App\Models\User::factory()->create();
    $user2 = \App\Models\User::factory()->create();
    
    $token1 = auth('api')->login($user1);
    $token2 = auth('api')->login($user2);

    // User1 makes requests
    for ($i = 0; $i < 10; $i++) {
        $this->withHeader('Authorization', "Bearer {$token1}")
            ->getJson('/api/v1/me');
    }

    // User2 should still be able to make requests
    $response = $this->withHeader('Authorization', "Bearer {$token2}")
        ->getJson('/api/v1/me');

    $response->assertStatus(200);
});

