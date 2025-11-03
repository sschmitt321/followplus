<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\FollowWindow;
use App\Models\InviteToken;
use App\Models\Symbol;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminFollowController extends Controller
{
    public function __construct(
        private AuditService $auditService
    ) {
    }

    /**
     * Create a follow window.
     * 
     * Creates a new follow trading window. Windows define when users can place follow orders.
     * Different window types have different quota rules.
     * 
     * @param Request $request
     * @param int $request->symbol_id Required. Trading symbol ID (must exist in symbols table).
     * @param string $request->window_type Required. Window type. Allowed values: "fixed_daily" (daily fixed windows), "newbie_bonus" (bonus for new users), "inviter_bonus" (bonus for inviters).
     * @param string $request->start_at Required. Window start time in YYYY-MM-DD HH:MM:SS format (e.g., "2025-11-06 13:00:00").
     * @param string $request->expire_at Required. Window expiration time in YYYY-MM-DD HH:MM:SS format. Must be after start_at.
     * @param float|null $request->reward_rate_min Optional. Minimum reward rate (0-1, default: 0.5). Represents minimum profit percentage.
     * @param float|null $request->reward_rate_max Optional. Maximum reward rate (0-1, default: 0.6). Must be >= reward_rate_min. Represents maximum profit percentage.
     * 
     * @return JsonResponse Returns created window with ID and details
     * 
     * Request example:
     * {
     *   "symbol_id": 1,
     *   "window_type": "fixed_daily",
     *   "start_at": "2025-11-06 13:00:00",
     *   "expire_at": "2025-11-06 14:00:00",
     *   "reward_rate_min": 0.5,
     *   "reward_rate_max": 0.6
     * }
     */
    public function createWindow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol_id' => 'required|integer|exists:symbols,id', // Trading symbol ID (must exist)
            'window_type' => 'required|in:fixed_daily,newbie_bonus,inviter_bonus', // Window type (fixed_daily, newbie_bonus, or inviter_bonus)
            'start_at' => 'required|date', // Start time (YYYY-MM-DD HH:MM:SS format)
            'expire_at' => 'required|date|after:start_at', // Expiration time (must be after start_at)
            'reward_rate_min' => 'nullable|numeric|min:0|max:1', // Minimum reward rate (0-1, default: 0.5)
            'reward_rate_max' => 'nullable|numeric|min:0|max:1|gte:reward_rate_min', // Maximum reward rate (0-1, must be >= min, default: 0.6)
        ]);

        try {
            $window = FollowWindow::create([
                'symbol_id' => $validated['symbol_id'],
                'window_type' => $validated['window_type'],
                'start_at' => $validated['start_at'],
                'expire_at' => $validated['expire_at'],
                'reward_rate_min' => $validated['reward_rate_min'] ?? 0.5,
                'reward_rate_max' => $validated['reward_rate_max'] ?? 0.6,
                'status' => 'active',
            ]);

            // Log audit
            $this->auditService->log(
                auth()->id(),
                'follow_window_create',
                'follow_window',
                null,
                $window->toArray()
            );

            return response()->json([
                'message' => 'Follow window created successfully',
                'window' => [
                    'id' => $window->id,
                    'symbol_id' => $window->symbol_id,
                    'window_type' => $window->window_type,
                    'start_at' => $window->start_at->toIso8601String(),
                    'expire_at' => $window->expire_at->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create an invite token for a window.
     * 
     * Creates an invite token that users need to provide when placing orders for a window.
     * If token is not provided, a random 8-character uppercase token will be generated.
     * 
     * @param Request $request
     * @param int $request->follow_window_id Required. Follow window ID (must exist).
     * @param string|null $request->token Optional. Custom token string (max 64 characters). If not provided, auto-generated.
     * @param string $request->valid_after Required. Token validity start time in YYYY-MM-DD HH:MM:SS format.
     * @param string $request->valid_before Required. Token validity end time in YYYY-MM-DD HH:MM:SS format. Must be after valid_after.
     * 
     * @return JsonResponse Returns created token with details
     * 
     * Request example:
     * {
     *   "follow_window_id": 1,
     *   "token": "ABCD1234",
     *   "valid_after": "2025-11-06 13:00:00",
     *   "valid_before": "2025-11-06 14:00:00"
     * }
     */
    public function createInviteToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'follow_window_id' => 'required|integer|exists:follow_windows,id', // Follow window ID (must exist)
            'token' => 'nullable|string|max:64', // Custom token (optional, max 64 chars, auto-generated if not provided)
            'valid_after' => 'required|date', // Token validity start time (YYYY-MM-DD HH:MM:SS format)
            'valid_before' => 'required|date|after:valid_after', // Token validity end time (must be after valid_after)
        ]);

        try {
            $window = FollowWindow::findOrFail($validated['follow_window_id']);

            $token = InviteToken::create([
                'follow_window_id' => $validated['follow_window_id'],
                'token' => $validated['token'] ?? strtoupper(Str::random(8)),
                'valid_after' => $validated['valid_after'],
                'valid_before' => $validated['valid_before'],
                'symbol_id' => $window->symbol_id,
            ]);

            // Log audit
            $this->auditService->log(
                auth()->id(),
                'invite_token_create',
                'invite_token',
                null,
                $token->toArray()
            );

            return response()->json([
                'message' => 'Invite token created successfully',
                'token' => [
                    'id' => $token->id,
                    'token' => $token->token,
                    'follow_window_id' => $token->follow_window_id,
                    'valid_after' => $token->valid_after->toIso8601String(),
                    'valid_before' => $token->valid_before->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

