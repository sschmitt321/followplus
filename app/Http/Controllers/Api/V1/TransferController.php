<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transfer\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(
        private TransferService $transferService
    ) {
    }

    /**
     * Transfer between account types.
     * 
     * Transfers funds between spot and contract accounts. Both accounts must be different types.
     * The transfer is instant and updates both account balances immediately.
     * 
     * @param Request $request
     * @param string $request->from Required. Source account type. Must be either "spot" or "contract".
     * @param string $request->to Required. Destination account type. Must be either "spot" or "contract". Must be different from "from".
     * @param string $request->amount Required. Transfer amount as string (e.g., "100.50"). Must be >= 0 and <= available balance.
     * @param string $request->currency Required. Currency code (e.g., "USDT", "BTC"). Must exist in currencies table.
     * 
     * @return JsonResponse Returns transfer record with details
     * 
     * Request example:
     * {
     *   "from": "spot",
     *   "to": "contract",
     *   "amount": "1000.00",
     *   "currency": "USDT"
     * }
     */
    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|in:spot,contract', // Source account type (must be "spot" or "contract")
            'to' => 'required|in:spot,contract', // Destination account type (must be "spot" or "contract", must differ from "from")
            'amount' => 'required|string|min:0', // Transfer amount (string format, e.g., "100.50", must be >= 0)
            'currency' => 'required|string|exists:currencies,name', // Currency code (e.g., "USDT", "BTC", must exist in system)
        ]);

        try {
            $transfer = $this->transferService->transfer(
                auth()->id(),
                $validated['currency'],
                $validated['from'],
                $validated['to'],
                $validated['amount']
            );

            return response()->json([
                'message' => 'Transfer completed successfully',
                'transfer' => [
                    'id' => $transfer->id,
                    'currency' => $transfer->currency,
                    'from_type' => $transfer->from_type,
                    'to_type' => $transfer->to_type,
                    'amount' => $transfer->amount->toFixed(6),
                    'status' => $transfer->status,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
