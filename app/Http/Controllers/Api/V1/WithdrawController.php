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
     * @return JsonResponse Returns paginated withdrawal list with metadata
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
     * Calculate withdrawable amount.
     * 
     * Calculates the maximum amount user can withdraw based on their account type:
     * - Newbie (joined within 7 days): 90% of total balance (10% fee deducted)
     * - Old user: Total balance minus configured fee rate
     * 
     * @return JsonResponse Returns withdrawable amount, fee, policy type, and total balance
     */
    public function calcWithdrawable(): JsonResponse
    {
        try {
            $calc = $this->withdrawService->calcWithdrawable(auth()->id());
            return response()->json($calc);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Apply withdrawal.
     * 
     * Creates a withdrawal request. The amount will be frozen until the withdrawal is processed.
     * Fee is calculated based on user type (newbie vs old user).
     * 
     * @param Request $request
     * @param string $request->amount Required. Withdrawal amount as string (e.g., "100.50"). Must be >= 0 and <= withdrawable amount.
     * @param string $request->to_address Required. Destination wallet address (max 255 characters).
     * @param string|null $request->currency Optional. Currency code (default: "USDT"). Must exist in currencies table.
     * @param string|null $request->chain Optional. Blockchain network (max 20 characters, e.g., "TRC20", "ERC20").
     * @param string $request->withdraw_password Required. User's withdrawal password for security verification.
     * 
     * @return JsonResponse Returns withdrawal record with calculated fee and actual amount
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

        try {
            $withdrawal = $this->withdrawService->apply(
                auth()->id(),
                $validated['amount'],
                $validated['to_address'],
                $validated['currency'] ?? 'USDT',
                $validated['chain'] ?? null
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
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
