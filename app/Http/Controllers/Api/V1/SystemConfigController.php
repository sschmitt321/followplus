<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\System\ConfigService;
use Illuminate\Http\JsonResponse;

class SystemConfigController extends Controller
{
    public function __construct(
        private ConfigService $configService
    ) {
    }

    /**
     * Get all system configs (read-only).
     */
    public function index(): JsonResponse
    {
        // In a real implementation, you might want to return specific configs
        // For now, return empty array as placeholder
        return response()->json([
            'configs' => [],
        ]);
    }
}
