<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditService
{
    /**
     * Log an audit event.
     */
    public function log(
        ?int $userId,
        string $action,
        string $resource,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'resource' => $resource,
            'before_json' => $before,
            'after_json' => $after,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}

