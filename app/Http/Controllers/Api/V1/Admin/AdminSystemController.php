<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditService;
use App\Services\System\ConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AdminSystemController extends Controller
{
    public function __construct(
        private ConfigService $configService,
        private AuditService $auditService
    ) {
    }

    /**
     * Create or update system announcement.
     */
    public function announcement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|in:info,warning,error,success',
        ]);

        try {
            // Get existing announcements
            $announcements = Cache::get('system_announcements', []);
            
            // Add new announcement
            $announcements[] = [
                'id' => count($announcements) + 1,
                'title' => $validated['title'],
                'content' => $validated['content'],
                'type' => $validated['type'],
                'published_at' => now()->toIso8601String(),
            ];

            // Update cache
            Cache::put('system_announcements', $announcements, now()->addDays(30));

            // Log audit
            $this->auditService->log(
                auth()->id(),
                'announcement_create',
                'system',
                null,
                ['title' => $validated['title']]
            );

            return response()->json([
                'message' => 'Announcement created successfully',
                'announcement' => end($announcements),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

