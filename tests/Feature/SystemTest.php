<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

test('authenticated user can get system announcements', function () {
    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/system/announcements');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'announcements' => [
                '*' => [
                    'id',
                    'title',
                    'content',
                    'type',
                    'published_at',
                ],
            ],
        ]);

    expect($response->json('announcements'))->toBeArray();
});

test('authenticated user can get help content', function () {
    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/system/help');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'faq' => [
                '*' => [
                    'question',
                    'answer',
                ],
            ],
            'contact' => [
                'email',
                'telegram',
            ],
        ]);

    expect($response->json('faq'))->toBeArray();
    expect($response->json('contact.email'))->toBe('support@followplus.com');
});

test('authenticated user can get app version info', function () {
    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/system/version');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'version',
            'build',
            'min_version',
            'update_required',
        ]);

    expect($response->json('version'))->toBe('1.0.0');
    expect($response->json('update_required'))->toBe(false);
});

test('authenticated user can get app download links', function () {
    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/system/download');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'ios' => [
                'url',
                'version',
            ],
            'android' => [
                'url',
                'version',
            ],
        ]);

    expect($response->json('ios.url'))->toContain('apps.apple.com');
    expect($response->json('android.url'))->toContain('play.google.com');
});

test('system announcements are cached', function () {
    Cache::forget('system_announcements');
    
    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    // First request
    $response1 = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/system/announcements');

    $response1->assertStatus(200);
    $firstAnnouncements = $response1->json('announcements');

    // Second request should return cached data
    $response2 = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/system/announcements');

    $response2->assertStatus(200);
    expect($response2->json('announcements'))->toBe($firstAnnouncements);
});

test('unauthenticated user cannot access system endpoints', function () {
    $response = $this->getJson('/api/v1/system/announcements');
    $response->assertStatus(401);

    $response = $this->getJson('/api/v1/system/help');
    $response->assertStatus(401);

    $response = $this->getJson('/api/v1/system/version');
    $response->assertStatus(401);

    $response = $this->getJson('/api/v1/system/download');
    $response->assertStatus(401);
});

