<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {
    }

    /**
     * Register a new user.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'invite_code' => 'nullable|string|exists:users,invite_code',
        ]);

        try {
            $tokens = $this->authService->register(
                $validated['email'],
                $validated['password'],
                $validated['invite_code'] ?? null
            );

            return response()->json($tokens, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Login user.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        try {
            $tokens = $this->authService->login($validated['email'], $validated['password']);

            return response()->json($tokens, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Invalid credentials',
            ], 401);
        }
    }

    /**
     * Refresh access token.
     */
    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh' => 'required|string',
        ]);

        try {
            $tokens = $this->authService->refresh($validated['refresh']);

            return response()->json($tokens, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Invalid refresh token',
            ], 401);
        }
    }
}

