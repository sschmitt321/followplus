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
     * 
     * Creates a new system announcement that will be displayed to all users.
     * Announcements are cached for 30 days. Admin only endpoint.
     * 
     * @param Request $request
     * @param string $request->title Required. Announcement title (max 255 characters).
     * @param string $request->content Required. Announcement content (full text).
     * @param string $request->type Required. Announcement type. Allowed values: "info" (blue), "warning" (yellow), "error" (red), "success" (green).
     * 
     * @return JsonResponse Returns created announcement with ID and timestamp
     * 
     * Request example:
     * {
     *   "title": "系统维护通知",
     *   "content": "系统将于今晚进行维护，预计持续2小时",
     *   "type": "warning"
     * }
     */
    public function announcement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255', // Announcement title (max 255 characters)
            'content' => 'required|string', // Announcement content (full text)
            'type' => 'required|in:info,warning,error,success', // Announcement type (info, warning, error, or success)
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

