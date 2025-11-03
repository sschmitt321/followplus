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
     * 
     * Gets an exchange rate quote for swapping between two currencies. The quote is valid for 5 minutes.
     * Returns quote_id which must be used to confirm the swap.
     * 
     * @param Request $request
     * @param string $request->from Required. Source currency code (e.g., "USDT"). Must exist in currencies table.
     * @param string $request->to Required. Destination currency code (e.g., "BTC"). Must exist in currencies table.
     * @param string $request->amount Required. Amount to swap from source currency (e.g., "1000.00"). Must be >= 0.
     * 
     * @return JsonResponse Returns quote_id, exchange rate, and calculated amounts
     * 
     * Request example:
     * {
     *   "from": "USDT",
     *   "to": "BTC",
     *   "amount": "1000.00"
     * }
     */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|string|exists:currencies,name', // Source currency code (e.g., "USDT", must exist in system)
            'to' => 'required|string|exists:currencies,name', // Destination currency code (e.g., "BTC", must exist in system)
            'amount' => 'required|string|min:0', // Amount to swap (string format, e.g., "1000.00", must be >= 0)
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
     * 
     * Executes the swap using a valid quote_id obtained from the quote endpoint.
     * The quote must be used within 5 minutes. After confirmation, funds are transferred
     * between currencies in the user's spot account.
     * 
     * @param Request $request
     * @param string $request->quote_id Required. Quote ID obtained from /swap/quote endpoint. Must be valid and not expired (5 minutes validity).
     * 
     * @return JsonResponse Returns swap record with details
     * 
     * Request example:
     * {
     *   "quote_id": "quote_67890abcdef"
     * }
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quote_id' => 'required|string', // Quote ID from /swap/quote endpoint (valid for 5 minutes)
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
