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
     * 
     * Returns system configuration values that are publicly accessible to all authenticated users.
     * This endpoint provides read-only access to system settings such as:
     * - Trading parameters
     * - Fee rates
     * - Feature flags
     * - System limits
     * 
     * Currently returns an empty array as a placeholder. Future implementations will return
     * actual configuration values based on system requirements.
     * 
     * @return JsonResponse Returns system configuration object:
     * - configs: Array of configuration key-value pairs (currently empty)
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
