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
     */
    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|in:spot,contract',
            'to' => 'required|in:spot,contract',
            'amount' => 'required|string|min:0',
            'currency' => 'required|string|exists:currencies,name',
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
