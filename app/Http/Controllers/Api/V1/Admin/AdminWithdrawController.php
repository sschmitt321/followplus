<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\Withdraw\WithdrawService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWithdrawController extends Controller
{
    public function __construct(
        private WithdrawService $withdrawService,
        private AuditService $auditService
    ) {
    }

    /**
     * Get all withdrawals (with filters).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Withdrawal::with('user');

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        $withdrawals = $query->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'withdrawals' => $withdrawals->map(function ($withdrawal) {
                return [
                    'id' => $withdrawal->id,
                    'user_id' => $withdrawal->user_id,
                    'user_email' => $withdrawal->user->email ?? null,
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
     * Approve a withdrawal.
     */
    public function approve(int $id): JsonResponse
    {
        try {
            $withdrawal = Withdrawal::findOrFail($id);
            $oldStatus = $withdrawal->status;

            $this->withdrawService->approve($withdrawal->id);

            // Log audit
            $this->auditService->log(
                auth()->id(),
                'withdraw_approve',
                'withdrawal',
                ['status' => $oldStatus],
                ['status' => 'approved']
            );

            return response()->json([
                'message' => 'Withdrawal approved successfully',
                'withdrawal' => [
                    'id' => $withdrawal->id,
                    'status' => $withdrawal->fresh()->status,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reject a withdrawal.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $withdrawal = Withdrawal::findOrFail($id);
            $oldStatus = $withdrawal->status;

            $this->withdrawService->reject($withdrawal->id);

            // Log audit
            $this->auditService->log(
                auth()->id(),
                'withdraw_reject',
                'withdrawal',
                ['status' => $oldStatus],
                ['status' => 'rejected']
            );

            return response()->json([
                'message' => 'Withdrawal rejected successfully',
                'withdrawal' => [
                    'id' => $withdrawal->id,
                    'status' => $withdrawal->fresh()->status,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Mark withdrawal as paid.
     */
    public function markPaid(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'txid' => 'nullable|string|max:255',
        ]);

        try {
            $withdrawal = Withdrawal::findOrFail($id);
            $oldStatus = $withdrawal->status;

            $this->withdrawService->markPaid($withdrawal->id, $validated['txid'] ?? null);

            // Log audit
            $this->auditService->log(
                auth()->id(),
                'withdraw_paid',
                'withdrawal',
                ['status' => $oldStatus],
                ['status' => 'paid', 'txid' => $validated['txid'] ?? null]
            );

            return response()->json([
                'message' => 'Withdrawal marked as paid successfully',
                'withdrawal' => [
                    'id' => $withdrawal->id,
                    'status' => $withdrawal->fresh()->status,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

