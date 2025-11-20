<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class WithdrawSettingsController extends Controller
{
    /**
     * Set or update withdrawal password.
     * 
     * Sets or updates the user's withdrawal password. If password already exists,
     * old password must be provided for verification.
     * 
     * @param Request $request
     * @param string $request->password Required. New withdrawal password (minimum 6 characters).
     * @param string|null $request->old_password Optional. Old withdrawal password (required if password already set).
     * 
     * @return JsonResponse Returns success message
     * 
     * Request example (first time):
     * {
     *   "password": "123456"
     * }
     * 
     * Request example (update):
     * {
     *   "old_password": "123456",
     *   "password": "654321"
     * }
     */
    public function setPassword(Request $request): JsonResponse
    {
        $user = auth()->user();
        $hasPassword = !empty($user->withdraw_password_hash);

        $rules = [
            'password' => 'required|string|min:6|max:20',
        ];

        if ($hasPassword) {
            $rules['old_password'] = 'required|string';
        }

        $validated = $request->validate($rules);

        // Verify old password if updating
        if ($hasPassword) {
            if (!Hash::check($validated['old_password'], $user->withdraw_password_hash)) {
                throw ValidationException::withMessages([
                    'old_password' => ['旧提现密码错误'],
                ]);
            }
        }

        // Set new password
        $user->update([
            'withdraw_password_hash' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => $hasPassword ? '提现密码已更新' : '提现密码已设置',
        ], 200);
    }

    /**
     * Set or update withdrawal address.
     * 
     * Sets or updates the user's default withdrawal address.
     * 
     * @param Request $request
     * @param string $request->address Required. Withdrawal address (max 255 characters).
     * 
     * @return JsonResponse Returns success message
     * 
     * Request example:
     * {
     *   "address": "Txxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
     * }
     */
    public function setAddress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address' => 'required|string|max:255',
        ]);

        $user = auth()->user();

        // Update or create profile
        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            ['withdraw_address' => $validated['address']]
        );

        return response()->json([
            'message' => '提现地址已设置',
        ], 200);
    }

    /**
     * Verify withdrawal password.
     * 
     * Verifies if the provided withdrawal password is correct.
     * Used for validation before sensitive operations.
     * 
     * @param Request $request
     * @param string $request->password Required. Withdrawal password to verify.
     * 
     * @return JsonResponse Returns verification result
     * 
     * Request example:
     * {
     *   "password": "123456"
     * }
     */
    public function verifyPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        $user = auth()->user();

        if (empty($user->withdraw_password_hash)) {
            return response()->json([
                'verified' => false,
                'error' => '提现密码未设置',
            ], 400);
        }

        $verified = Hash::check($validated['password'], $user->withdraw_password_hash);

        return response()->json([
            'verified' => $verified,
            'message' => $verified ? '密码验证成功' : '密码错误',
        ], $verified ? 200 : 400);
    }
}

