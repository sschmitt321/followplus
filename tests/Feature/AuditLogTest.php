<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;

test('audit service can log events', function () {
    $user = User::factory()->create();
    $auditService = new AuditService();

    $request = Request::create('/test', 'POST');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');
    $request->headers->set('User-Agent', 'Test Agent');

    $auditLog = $auditService->log(
        $user->id,
        'user.created',
        'User',
        null,
        ['email' => 'test@example.com'],
        $request
    );

    expect($auditLog)->toBeInstanceOf(AuditLog::class);
    expect($auditLog->user_id)->toBe($user->id);
    expect($auditLog->action)->toBe('user.created');
    expect($auditLog->resource)->toBe('User');
    expect($auditLog->after_json)->toBe(['email' => 'test@example.com']);
    expect($auditLog->ip)->toBe('127.0.0.1');
    expect($auditLog->user_agent)->toBe('Test Agent');
});

test('audit log can be created without user', function () {
    $auditService = new AuditService();

    $auditLog = $auditService->log(
        null,
        'system.event',
        'System',
        ['old_value' => 'test'],
        ['new_value' => 'updated'],
    );

    expect($auditLog->user_id)->toBeNull();
    expect($auditLog->action)->toBe('system.event');
});

test('audit log stores before and after json correctly', function () {
    $user = User::factory()->create();
    $auditService = new AuditService();

    $before = ['name' => 'Old Name', 'status' => 'inactive'];
    $after = ['name' => 'New Name', 'status' => 'active'];

    $auditLog = $auditService->log(
        $user->id,
        'user.updated',
        'User',
        $before,
        $after,
    );

    expect($auditLog->before_json)->toBe($before);
    expect($auditLog->after_json)->toBe($after);
});

test('audit log can be queried by user', function () {
    $user = User::factory()->create();
    $auditService = new AuditService();

    $auditService->log($user->id, 'action1', 'Resource1');
    $auditService->log($user->id, 'action2', 'Resource2');

    $logs = AuditLog::where('user_id', $user->id)->get();
    expect($logs)->toHaveCount(2);
});

test('audit log can be queried by action and resource', function () {
    $user = User::factory()->create();
    $auditService = new AuditService();

    $auditService->log($user->id, 'user.created', 'User');
    $auditService->log($user->id, 'user.updated', 'User');
    $auditService->log($user->id, 'order.created', 'Order');

    $userLogs = AuditLog::where('action', 'like', 'user.%')
        ->where('resource', 'User')
        ->get();

    expect($userLogs)->toHaveCount(2);
});

