<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\Withdraw\WithdrawService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawController extends Controller
{
    public function __construct(
        private WithdrawService $withdrawService
    ) {
    }

    /**
     * Get withdrawal history.
     * 
     * Returns paginated list of user's withdrawal records. Includes all withdrawals
     * regardless of status (pending, approved, rejected, paid).
     * 
     * @param Request $request Query parameters
     * @param int|null $request->page Optional. Page number for pagination (default: 1)
     * 
     * @return JsonResponse Returns paginated withdrawal list with metadata, including:
     * - review_note: Admin review note/comment (null if not reviewed)
     * - reviewed_at: Review timestamp (null if not reviewed)
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $withdrawals = Withdrawal::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'withdrawals' => $withdrawals->map(function ($withdrawal) {
                return [
                    'id' => $withdrawal->id,
                    'currency' => $withdrawal->currency,
                    'amount_request' => $withdrawal->amount_request->toFixed(6),
                    'fee' => $withdrawal->fee->toFixed(6),
                    'amount_actual' => $withdrawal->amount_actual->toFixed(6),
                    'status' => $withdrawal->status,
                    'to_address' => $withdrawal->to_address,
                    'txid' => $withdrawal->txid,
                    'review_note' => $withdrawal->review_note,
                    'reviewed_at' => $withdrawal->reviewed_at ? (
                        $withdrawal->reviewed_at instanceof \Carbon\Carbon 
                            ? $withdrawal->reviewed_at->toIso8601String() 
                            : $withdrawal->reviewed_at
                    ) : null,
                    'created_at' => $withdrawal->created_at->toIso8601String(),
                ];
            }),
            'pagination' => [
                'current_page' => $withdrawals->currentPage(),
                'total_pages' => $withdrawals->lastPage(),
                'total' => $withdrawals->total(),
            ],
        ]);
    }

    /**
     * Apply withdrawal.
     * 
     * Creates a withdrawal request. The amount will be frozen until the withdrawal is processed.
     * Fee is calculated based on user type (newbie vs old user).
     * 
     * Prerequisites (all must be met, otherwise returns 400 error):
     * - User must have completed KYC verification (status = 'approved')
     * - User must have set a withdrawal address (profile.withdraw_address)
     * - User must have set a withdrawal password (withdraw_password_hash)
     * 
     * @param Request $request
     * @param string $request->amount Required. Withdrawal amount as string (e.g., "100.50"). Must be >= 0 and <= withdrawable amount.
     * @param string $request->to_address Required. Destination wallet address (max 255 characters).
     * @param string|null $request->currency Optional. Currency code (default: "USDT"). Must exist in currencies table.
     * @param string|null $request->chain Optional. Blockchain network (max 20 characters, e.g., "TRC20", "ERC20").
     * @param string $request->withdraw_password Required. User's withdrawal password for security verification.
     * 
     * @return JsonResponse Returns withdrawal record with calculated fee and actual amount, or error message if prerequisites not met
     * 
     * Error responses:
     * - 400: "身份认证未完成" - KYC verification not completed
     * - 400: "提现地址未绑定" - Withdrawal address not set
     * - 400: "提现密码未设置" - Withdrawal password not set
     * 
     * Request example:
     * {
     *   "amount": "1000.00",
     *   "to_address": "Txxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
     *   "currency": "USDT",
     *   "chain": "TRC20",
     *   "withdraw_password": "123456"
     * }
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|string|min:0', // Withdrawal amount (string format, e.g., "100.50", must be >= 0)
            'to_address' => 'required|string|max:255', // Destination wallet address (max 255 characters)
            'currency' => 'nullable|string|exists:currencies,name', // Currency code (default: "USDT", must exist in system)
            'chain' => 'nullable|string|max:20', // Blockchain network (e.g., "TRC20", "ERC20", max 20 characters)
            'withdraw_password' => 'required|string', // Withdrawal password for security verification
        ]);

        $user = auth()->user();
        $user->load(['kyc', 'profile']);

        // Verify KYC status
        if (!$user->kyc || $user->kyc->status !== 'approved') {
            return response()->json([
                'error' => '身份认证未完成',
            ], 400);
        }

        // Verify withdraw address is set
        if (empty($user->profile?->withdraw_address)) {
            return response()->json([
                'error' => '提现地址未绑定',
            ], 400);
        }

        // Verify withdraw password is set
        if (empty($user->withdraw_password_hash)) {
            return response()->json([
                'error' => '提现密码未设置',
            ], 400);
        }

        try {
            $withdrawal = $this->withdrawService->apply(
                $user->id,
                $validated['amount'],
                $validated['to_address'],
                $validated['currency'] ?? 'USDT',
                $validated['chain'] ?? null,
                $validated['withdraw_password']
            );

            return response()->json([
                'message' => 'Withdrawal applied successfully',
                'withdrawal' => [
                    'id' => $withdrawal->id,
                    'currency' => $withdrawal->currency,
                    'amount_request' => $withdrawal->amount_request->toFixed(6),
                    'fee' => $withdrawal->fee->toFixed(6),
                    'amount_actual' => $withdrawal->amount_actual->toFixed(6),
                    'status' => $withdrawal->status,
                ],
            ], 200);
        } catch (\Exception $e) {
            // Log error for debugging
            \Log::warning('Withdrawal failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            // Return user-friendly error message
            // The error messages from WithdrawService are already user-friendly
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
