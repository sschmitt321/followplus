<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Services\Deposit\DepositService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDepositController extends Controller
{
    public function __construct(
        private DepositService $depositService,
        private AuditService $auditService
    ) {
    }

    /**
     * Get all deposits (with filters).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Deposit::with('user');

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        $deposits = $query->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'deposits' => $deposits->map(function ($deposit) {
                return [
                    'id' => $deposit->id,
                    'user_id' => $deposit->user_id,
                    'user_email' => $deposit->user->email ?? null,
                    'currency' => $deposit->currency,
                    'amount' => $deposit->amount->toFixed(6),
                    'status' => $deposit->status,
                    'txid' => $deposit->txid,
                    'confirmed_at' => $deposit->confirmed_at?->toIso8601String(),
                    'created_at' => $deposit->created_at->toIso8601String(),
                ];
            }),
            'pagination' => [
                'current_page' => $deposits->currentPage(),
                'total_pages' => $deposits->lastPage(),
                'total' => $deposits->total(),
            ],
        ]);
    }

    /**
     * Confirm a deposit.
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'txid' => 'nullable|string|max:255',
        ]);

        try {
            $deposit = Deposit::findOrFail($id);
            
            if ($deposit->status !== 'pending') {
                return response()->json([
                    'error' => 'Deposit already processed',
                ], 400);
            }

            $this->depositService->confirm($deposit->id, $validated['txid'] ?? null);

            // Log audit
            $this->auditService->log(
                auth()->id(),
                'deposit_confirm',
                'deposit',
                $deposit->toArray(),
                $deposit->fresh()->toArray()
            );

            return response()->json([
                'message' => 'Deposit confirmed successfully',
                'deposit' => [
                    'id' => $deposit->id,
                    'status' => $deposit->fresh()->status,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

