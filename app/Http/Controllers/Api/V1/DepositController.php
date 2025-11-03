<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Services\Deposit\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepositController extends Controller
{
    public function __construct(
        private DepositService $depositService
    ) {
    }

    /**
     * Get deposit history.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $deposits = Deposit::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'deposits' => $deposits->map(function ($deposit) {
                return [
                    'id' => $deposit->id,
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
     * Manual apply deposit (for testing/admin).
     */
    public function manualApply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|string|min:0',
            'currency' => 'required|string|exists:currencies,name',
        ]);

        try {
            $deposit = $this->depositService->manualApply(
                auth()->id(),
                $validated['currency'],
                $validated['amount']
            );

            return response()->json([
                'message' => 'Deposit applied successfully',
                'deposit' => [
                    'id' => $deposit->id,
                    'currency' => $deposit->currency,
                    'amount' => $deposit->amount->toFixed(6),
                    'status' => $deposit->status,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
