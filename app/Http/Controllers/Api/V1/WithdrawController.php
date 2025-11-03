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
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|string|min:0',
            'to_address' => 'required|string|max:255',
            'currency' => 'nullable|string|exists:currencies,name',
            'chain' => 'nullable|string|max:20',
            'withdraw_password' => 'required|string', // TODO: 验证提现密码
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
