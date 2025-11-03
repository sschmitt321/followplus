<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Swap\SwapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SwapController extends Controller
{
    public function __construct(
        private SwapService $swapService
    ) {
    }

    /**
     * Get swap quote.
     */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|string|exists:currencies,name',
            'to' => 'required|string|exists:currencies,name',
            'amount' => 'required|string|min:0',
        ]);

        try {
            $quote = $this->swapService->quote(
                $validated['from'],
                $validated['to'],
                $validated['amount']
            );

            // Store quote in cache for 5 minutes
            $quoteId = 'quote_' . uniqid();
            Cache::put("swap_quote:{$quoteId}", $quote, now()->addMinutes(5));

            return response()->json([
                'quote_id' => $quoteId,
                ...$quote,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Confirm swap.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quote_id' => 'required|string',
        ]);

        try {
            $quote = Cache::get("swap_quote:{$validated['quote_id']}");
            if (!$quote) {
                return response()->json([
                    'error' => 'Quote expired or invalid',
                ], 400);
            }

            $swap = $this->swapService->confirm(
                auth()->id(),
                $quote['from_currency'],
                $quote['to_currency'],
                $quote['amount_from'],
                $quote['amount_to'],
                $quote['rate']
            );

            // Remove quote from cache
            Cache::forget("swap_quote:{$validated['quote_id']}");

            return response()->json([
                'message' => 'Swap completed successfully',
                'swap' => [
                    'id' => $swap->id,
                    'from_currency' => $swap->from_currency,
                    'to_currency' => $swap->to_currency,
                    'amount_from' => $swap->amount_from->toFixed(6),
                    'amount_to' => $swap->amount_to->toFixed(6),
                    'rate' => $swap->rate_snapshot->toFixed(6),
                    'status' => $swap->status,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
