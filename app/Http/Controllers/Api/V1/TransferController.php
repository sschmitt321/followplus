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
                'message' => '转账成功',
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
            // Log error for debugging
            \Log::warning('Transfer failed', [
                'user_id' => auth()->id(),
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
                'currency' => $validated['currency'] ?? null,
                'amount' => $validated['amount'] ?? null,
                'error' => $e->getMessage(),
            ]);

            // Return user-friendly error message
            $errorMessage = $e->getMessage();
            
            // Translate common error messages to Chinese
            if (str_contains($errorMessage, 'does not exist') || str_contains($errorMessage, '不存在')) {
                // Error message already contains Chinese account names (资金账户/合约账户)
                // Keep the original message as it's already user-friendly
            } elseif (str_contains($errorMessage, '账户余额不足')) {
                // Keep the detailed error message that includes balance info
                // Error message already contains balance details in Chinese with account names
            } elseif (str_contains($errorMessage, 'Insufficient balance')) {
                $errorMessage = '账户余额不足';
            } elseif (str_contains($errorMessage, 'same account type') || str_contains($errorMessage, '不能在同一账户类型间转账')) {
                $errorMessage = '不能在同一账户类型间转账';
            }
            
            return response()->json([
                'error' => $errorMessage,
            ], 400);
        }
    }
}
