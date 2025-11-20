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

    /**
     * Request password reset.
     * 
     * Sends a password reset token to the user's email address. 
     * For security reasons, this endpoint always returns success even if the email doesn't exist.
     * 
     * @param Request $request
     * @param string $request->email Required. User email address.
     * 
     * @return JsonResponse Returns success message.
     * 
     * @example {
     *   "email": "user@example.com"
     * }
     */
    public function requestPasswordReset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email', // User email address
        ]);

        try {
            $this->authService->requestPasswordReset($validated['email']);

            return response()->json([
                'message' => 'If the email exists, a password reset link has been sent.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reset password using token.
     * 
     * Resets the user's password using a valid reset token received via email.
     * The token expires after 60 minutes.
     * 
     * @param Request $request
     * @param string $request->email Required. User email address.
     * @param string $request->token Required. Password reset token from email.
     * @param string $request->password Required. New password (minimum 8 characters).
     * 
     * @return JsonResponse Returns success message.
     * 
     * @example {
     *   "email": "user@example.com",
     *   "token": "abc123...",
     *   "password": "newpassword123"
     * }
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email', // User email address
            'token' => 'required|string', // Password reset token
            'password' => 'required|string|min:8', // New password (minimum 8 characters)
        ]);

        try {
            $this->authService->resetPassword(
                $validated['email'],
                $validated['token'],
                $validated['password']
            );

            return response()->json([
                'message' => 'Password has been reset successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Change password for authenticated user.
     * 
     * Allows authenticated users to change their password by providing the old password
     * and a new password. This endpoint requires the user to be logged in.
     * 
     * @param Request $request
     * @param string $request->old_password Required. Current password for verification.
     * @param string $request->password Required. New password (minimum 8 characters).
     * 
     * @return JsonResponse Returns success message.
     * 
     * @example {
     *   "old_password": "oldpassword123",
     *   "password": "newpassword123"
     * }
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'old_password' => 'required|string', // Current password for verification
            'password' => 'required|string|min:8', // New password (minimum 8 characters)
        ]);

        try {
            $user = auth()->user();

            // Verify old password
            if (!\Illuminate\Support\Facades\Hash::check($validated['old_password'], $user->password_hash)) {
                throw ValidationException::withMessages([
                    'old_password' => ['Current password is incorrect'],
                ]);
            }

            // Update password
            $user->update([
                'password_hash' => \Illuminate\Support\Facades\Hash::make(
                    $validated['password'],
                    ['memory' => 65536, 'time' => 4, 'threads' => 3]
                ),
            ]);

            return response()->json([
                'message' => 'Password has been changed successfully.',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

