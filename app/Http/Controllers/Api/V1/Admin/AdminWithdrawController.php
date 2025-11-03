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
     * 
     * Returns paginated list of all withdrawals in the system. Admin only endpoint.
     * Supports filtering by status and user_id.
     * 
     * @param Request $request Query parameters
     * @param string|null $request->status Optional. Filter by withdrawal status. Allowed values: "pending", "approved", "rejected", "paid".
     * @param int|null $request->user_id Optional. Filter by user ID.
     * @param int|null $request->page Optional. Page number for pagination (default: 1)
     * 
     * @return JsonResponse Returns paginated withdrawal list with user information
     * 
     * Query example: ?status=pending&user_id=1&page=1
     */
    public function index(Request $request): JsonResponse
    {
        $query = Withdrawal::with('user');

        if ($request->has('status')) { // Filter by status (pending, approved, rejected, paid)
            $query->where('status', $request->get('status'));
        }

        if ($request->has('user_id')) { // Filter by user ID
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
     * 
     * Approves a pending withdrawal request. The withdrawal status changes to "approved",
     * but funds remain frozen until marked as paid. Creates audit log entry.
     * 
     * @param int $id Withdrawal ID (path parameter)
     * 
     * @return JsonResponse Returns success message and updated withdrawal status
     * 
     * Path example: /api/v1/admin/withdrawals/1/approve
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
     * 
     * Rejects a pending withdrawal request. The frozen amount will be unfrozen and returned
     * to user's available balance. Creates audit log entry.
     * 
     * @param Request $request
     * @param int $id Withdrawal ID (path parameter)
     * 
     * @return JsonResponse Returns success message and updated withdrawal status
     * 
     * Path example: /api/v1/admin/withdrawals/1/reject
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
     * 
     * Marks an approved withdrawal as paid. The frozen amount will be debited from user's account
     * and transferred out. Withdrawal status changes to "paid". Creates audit log entry.
     * 
     * @param Request $request
     * @param int $id Withdrawal ID (path parameter)
     * @param string|null $request->txid Optional. Transaction ID from blockchain (max 255 characters).
     * 
     * @return JsonResponse Returns success message and updated withdrawal status
     * 
     * Path example: /api/v1/admin/withdrawals/1/mark-paid
     * Request example:
     * {
     *   "txid": "0xabcdef123456"
     * }
     */
    public function markPaid(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'txid' => 'nullable|string|max:255', // Transaction ID from blockchain (optional, max 255 characters)
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

