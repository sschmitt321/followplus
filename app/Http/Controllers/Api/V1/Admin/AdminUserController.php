<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private AuditService $auditService
    ) {
    }

    /**
     * Admin reset user password.
     * 
     * Allows administrators to directly reset a user's password without requiring
     * a reset token. This bypasses the normal password reset flow.
     * 
     * Admin only endpoint. All password resets are logged for audit purposes.
     * 
     * @param Request $request
     * @param string $request->email Required. User email address.
     * @param string $request->password Required. New password (minimum 8 characters).
     * 
     * @return JsonResponse Returns success message.
     * 
     * @example {
     *   "email": "user@example.com",
     *   "password": "newpassword123"
     * }
     */
    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email|exists:users,email', // User email address (must exist)
                'password' => 'required|string|min:8', // New password (minimum 8 characters)
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            $this->authService->adminResetPassword(
                $validated['email'],
                $validated['password']
            );

            // Log audit
            $this->auditService->log(
                auth()->id(),
                'admin_password_reset',
                'user',
                null,
                ['email' => $validated['email']]
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
}

