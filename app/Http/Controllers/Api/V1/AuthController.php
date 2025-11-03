<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {
    }

    /**
     * Register a new user.
     * 
     * This endpoint allows users to create a new account. After successful registration,
     * the user will receive JWT access and refresh tokens.
     * 
     * @param Request $request
     * @param string $request->email Required. User email address. Must be valid email format and unique in the system.
     * @param string $request->password Required. User password. Must be at least 8 characters long.
     * @param string|null $request->invite_code Optional. Invite code from an existing user. If provided, establishes referral relationship.
     * 
     * @return JsonResponse Returns access and refresh tokens on success.
     * 
     * @example {
     *   "email": "user@example.com",
     *   "password": "password123",
     *   "invite_code": "ABC12345"
     * }
     */
    public function register(Request $request): JsonResponse
    {
        // Convert empty string to null for invite_code
        if ($request->has('invite_code') && $request->input('invite_code') === '') {
            $request->merge(['invite_code' => null]);
        }

        try {
            $validated = $request->validate([
                'email' => 'required|email|unique:users,email', // User email address (must be unique)
                'password' => 'required|string|min:8', // Password (minimum 8 characters)
                'invite_code' => 'nullable|string|exists:users,invite_code', // Optional invite code from existing user
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

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
     * 
     * Authenticates a user with email and password, returns JWT tokens for API access.
     * 
     * @param Request $request
     * @param string $request->email Required. User registered email address.
     * @param string $request->password Required. User password.
     * 
     * @return JsonResponse Returns access and refresh tokens on successful authentication.
     * 
     * @example {
     *   "email": "user@example.com",
     *   "password": "password123"
     * }
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email', // User email address
            'password' => 'required|string', // User password
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
     * 
     * Uses a valid refresh token to obtain a new access token. Refresh tokens are valid for 30 days.
     * 
     * @param Request $request
     * @param string $request->refresh Required. Valid refresh token obtained from login or register.
     * 
     * @return JsonResponse Returns new access token.
     * 
     * @example {
     *   "refresh": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
     * }
     */
    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh' => 'required|string', // Valid refresh token
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

