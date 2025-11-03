<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

test('admin can create system announcement', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Cache::forget('system_announcements');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/system/announcement', [
            'title' => '系统维护通知',
            'content' => '系统将于今晚进行维护',
            'type' => 'warning',
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'announcement' => [
                'id',
                'title',
                'content',
                'type',
                'published_at',
            ],
        ]);

    expect($response->json('announcement.title'))->toBe('系统维护通知');
    expect($response->json('announcement.content'))->toBe('系统将于今晚进行维护');
    expect($response->json('announcement.type'))->toBe('warning');
    
    // Verify announcement is cached
    $announcements = Cache::get('system_announcements');
    expect($announcements)->toBeArray();
    expect(count($announcements))->toBeGreaterThan(0);
});

test('admin can create multiple announcements', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Cache::forget('system_announcements');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    // Create first announcement
    $response1 = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/system/announcement', [
            'title' => '公告1',
            'content' => '内容1',
            'type' => 'info',
        ]);

    $response1->assertStatus(201);

    // Create second announcement
    $response2 = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/system/announcement', [
            'title' => '公告2',
            'content' => '内容2',
            'type' => 'success',
        ]);

    $response2->assertStatus(201);
    
    $announcements = Cache::get('system_announcements');
    expect(count($announcements))->toBeGreaterThanOrEqual(2);
});

test('admin cannot create announcement with invalid type', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/system/announcement', [
            'title' => '测试',
            'content' => '内容',
            'type' => 'invalid_type',
        ]);

    $response->assertStatus(422);
});

test('admin cannot create announcement without required fields', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/system/announcement', [
            'title' => '测试',
            // Missing content and type
        ]);

    $response->assertStatus(422);
});

test('admin announcement creation creates audit log', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Cache::forget('system_announcements');

    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($admin);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/system/announcement', [
            'title' => '测试公告',
            'content' => '测试内容',
            'type' => 'info',
        ]);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'announcement_create',
        'resource' => 'system',
    ]);
});

test('non-admin cannot access admin system endpoints', function () {
    $user = User::factory()->create(['role' => 'user']);
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/admin/system/announcement', [
            'title' => '测试',
            'content' => '内容',
            'type' => 'info',
        ]);

    $response->assertStatus(403);
});

test('unauthenticated user cannot access admin system endpoints', function () {
    $response = $this->postJson('/api/v1/admin/system/announcement', [
        'title' => '测试',
        'content' => '内容',
        'type' => 'info',
    ]);

    $response->assertStatus(401);
});

