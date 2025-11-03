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
     * 
     * Returns paginated list of user's deposit records. Includes all deposits regardless of status.
     * 
     * @param Request $request Query parameters for filtering
     * @param int|null $request->page Optional. Page number for pagination (default: 1)
     * 
     * @return JsonResponse Returns paginated deposit list with metadata
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
     * 
     * Creates and immediately confirms a deposit. Amount will be credited to user's spot account.
     * This endpoint is mainly for testing purposes.
     * 
     * @param Request $request
     * @param string $request->amount Required. Deposit amount as string (e.g., "100.50"). Must be >= 0.
     * @param string $request->currency Required. Currency code (e.g., "USDT", "BTC"). Must exist in currencies table.
     * 
     * @return JsonResponse Returns deposit record with status "confirmed"
     * 
     * Request example:
     * {
     *   "amount": "1000.00",
     *   "currency": "USDT"
     * }
     */
    public function manualApply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|string|min:0', // Deposit amount (string format, e.g., "100.50", must be >= 0)
            'currency' => 'required|string|exists:currencies,name', // Currency code (e.g., "USDT", "BTC", must exist in system)
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
