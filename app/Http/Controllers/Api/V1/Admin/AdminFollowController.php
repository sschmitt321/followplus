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
     */
    public function createWindow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol_id' => 'required|integer|exists:symbols,id',
            'window_type' => 'required|in:fixed_daily,newbie_bonus,inviter_bonus',
            'start_at' => 'required|date',
            'expire_at' => 'required|date|after:start_at',
            'reward_rate_min' => 'nullable|numeric|min:0|max:1',
            'reward_rate_max' => 'nullable|numeric|min:0|max:1|gte:reward_rate_min',
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
     */
    public function createInviteToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'follow_window_id' => 'required|integer|exists:follow_windows,id',
            'token' => 'nullable|string|max:64',
            'valid_after' => 'required|date',
            'valid_before' => 'required|date|after:valid_after',
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

