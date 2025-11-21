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
     * @param string|null $request->email Optional. User email address. Must be valid email format and unique in the system (required if phone not provided).
     * @param string|null $request->phone Optional. User phone number. Must be unique in the system (required if email not provided).
     * @param string $request->password Required. User password. Must be at least 8 characters long.
     * @param string|null $request->invite_code Optional. Invite code from an existing user. If provided, establishes referral relationship.
     * 
     * @return JsonResponse Returns access and refresh tokens on success.
     * 
     * @example Register with email {
     *   "email": "user@example.com",
     *   "password": "password123",
     *   "invite_code": "ABC12345"
     * }
     * 
     * @example Register with phone {
     *   "phone": "13800138000",
     *   "password": "password123",
     *   "invite_code": "ABC12345"
     * }
     * 
     * @example Register with both {
     *   "email": "user@example.com",
     *   "phone": "13800138000",
     *   "password": "password123"
     * }
     */
    public function register(Request $request): JsonResponse
    {
        // Convert empty string to null for invite_code
        if ($request->has('invite_code') && $request->input('invite_code') === '') {
            $request->merge(['invite_code' => null]);
        }

        try {
            // Support both email and phone registration
            // The 'email' field can accept either email or phone number
            $input = $request->all();
            $emailOrPhone = $input['email'] ?? null;
            
            // Determine if input is email or phone
            $isEmail = $emailOrPhone && filter_var($emailOrPhone, FILTER_VALIDATE_EMAIL) !== false;
            
            // Prepare validation rules based on input type
            $rules = [
                'password' => 'required|string|min:8',
                'invite_code' => 'nullable|string|exists:users,invite_code',
            ];
            
            // Normalize the input: if email field contains phone number, move it to phone field
            $normalizedEmail = null;
            $normalizedPhone = null;
            
            if ($isEmail) {
                // It's an email
                $normalizedEmail = $emailOrPhone;
                $normalizedPhone = $input['phone'] ?? null;
                $rules['email'] = 'required|email|unique:users,email';
                $rules['phone'] = 'nullable|string|unique:users,phone';
            } else if ($emailOrPhone) {
                // It's a phone number in email field
                $normalizedPhone = $emailOrPhone;
                $normalizedEmail = null;
                $rules['email'] = 'nullable';
                $rules['phone'] = 'required|string|unique:users,phone';
            } else {
                // Check if phone field is provided separately
                $normalizedPhone = $input['phone'] ?? null;
                $rules['email'] = 'nullable';
                $rules['phone'] = 'nullable|string|unique:users,phone';
            }
            
            // Update request with normalized values for validation
            $request->merge([
                'email' => $normalizedEmail,
                'phone' => $normalizedPhone,
            ]);
            
            $validated = $request->validate($rules);
            
            // Ensure validated data has correct values
            $validated['email'] = $normalizedEmail;
            $validated['phone'] = $normalizedPhone;

            // Ensure at least one of email or phone is provided
            if (empty($validated['email']) && empty($validated['phone'])) {
                return response()->json([
                    'error' => 'Validation failed',
                    'errors' => ['email' => ['Either email or phone is required']],
                ], 422);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            $tokens = $this->authService->register(
                $validated['email'] ?? null,
                $validated['phone'] ?? null,
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
     * Authenticates a user with email or phone number and password, returns JWT tokens for API access.
     * Supports both email and phone number login.
     * 
     * @param Request $request
     * @param string $request->email Required. User registered email address or phone number.
     * @param string $request->password Required. User password.
     * 
     * @return JsonResponse Returns access and refresh tokens on successful authentication.
     * 
     * @example {
     *   "email": "user@example.com",
     *   "password": "password123"
     * }
     * 
     * @example {
     *   "email": "13800138000",
     *   "password": "password123"
     * }
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string', // User email address or phone number
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

