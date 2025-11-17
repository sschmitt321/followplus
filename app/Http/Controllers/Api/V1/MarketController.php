<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Symbol;
use App\Services\Market\CoinGeckoService;
use App\Services\Market\MarketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketController extends Controller
{
    public function __construct(
        private MarketService $marketService,
        private CoinGeckoService $coinGeckoService
    ) {
    }

    /**
     * Get all enabled symbols.
     * 
     * Returns list of all enabled trading pairs (symbols) available in the system.
     * Each symbol represents a trading pair like BTC/USDT.
     * 
     * @return JsonResponse Returns array of enabled symbols with id, base, quote, and name
     */
    public function symbols(): JsonResponse
    {
        $symbols = Symbol::where('enabled', true)->get();

        return response()->json([
            'symbols' => $symbols->map(function ($symbol) {
                return [
                    'id' => $symbol->id,
                    'base' => $symbol->base,
                    'quote' => $symbol->quote,
                    'name' => $symbol->name,
                ];
            }),
        ]);
    }

    /**
     * Get latest tick for a symbol.
     * 
     * Returns the most recent market tick data for a specific trading symbol.
     * Includes latest price, change percentage, and timestamp.
     * 
     * @param int $id Symbol ID (path parameter)
     * 
     * @return JsonResponse Returns latest tick data or null if no data available
     * 
     * Path example: /api/v1/symbols/1/tick
     */
    public function tick(int $id): JsonResponse
    {
        $symbol = Symbol::findOrFail($id);
        $tick = $this->marketService->getLatestTick($symbol->id);

        if (!$tick) {
            return response()->json([
                'symbol_id' => $symbol->id,
                'symbol' => $symbol->name,
                'last_price' => null,
                'change_percent' => null,
                'tick_at' => null,
                'message' => 'No tick data available',
            ]);
        }

        return response()->json([
            'symbol_id' => $symbol->id,
            'symbol' => $symbol->name,
            'last_price' => $tick->last_price->toFixed(6),
            'change_percent' => (float) $tick->change_percent,
            'tick_at' => $tick->tick_at->toIso8601String(),
        ]);
    }

    /**
     * Get tick history for a symbol.
     * 
     * Returns historical tick data for a symbol, ordered by time descending (newest first).
     * Default limit is 100 ticks.
     * 
     * @param int $id Symbol ID (path parameter)
     * 
     * @return JsonResponse Returns array of historical ticks with price, change percent, and timestamp
     * 
     * Path example: /api/v1/symbols/1/tick-history
     */
    public function tickHistory(int $id): JsonResponse
    {
        $symbol = Symbol::findOrFail($id);
        $history = $this->marketService->getTickHistory($symbol->id, 100);

        return response()->json([
            'symbol_id' => $symbol->id,
            'symbol' => $symbol->name,
            'history' => $history,
        ]);
    }

    /**
     * Get token price from CoinGecko.
     * 
     * Queries CoinGecko API to get real-time price and 24h change for a token.
     * Supports single token query or batch query (comma-separated).
     * If no tokens provided, returns default tokens (BTC, ETH, BNB, SOL, XRP).
     * 
     * @param Request $request Query parameters
     * @param string|null $request->tokens Optional. Comma-separated token symbols (e.g., "BTC,ETH,BNB"). If not provided, returns default tokens.
     * @param string|null $request->vs_currency Optional. Target currency (default: "usdt")
     * 
     * @return JsonResponse Returns price data with symbol, price, and 24h change
     * 
     * Query examples:
     * - ?tokens=BTC (single token)
     * - ?tokens=BTC,ETH,BNB (multiple tokens)
     * - (no params) - returns default tokens
     */
    public function tokenPrice(Request $request): JsonResponse
    {
        $tokensParam = $request->input('tokens');
        $vsCurrency = strtolower($request->input('vs_currency', 'usdt'));

        // If no tokens provided, use default tokens
        if (empty($tokensParam)) {
            $prices = $this->coinGeckoService->getDefaultPrices($vsCurrency);
            
            // Convert object to array for better frontend compatibility
            $pricesArray = array_values($prices);
            
            return response()->json([
                'vs_currency' => $vsCurrency,
                'prices' => $pricesArray,
                'prices_map' => $prices, // Keep object format for backward compatibility
                'message' => 'Default tokens queried',
            ]);
        }

        // Parse comma-separated tokens
        $tokens = array_map('trim', explode(',', $tokensParam));
        $tokens = array_filter($tokens); // Remove empty values

        if (empty($tokens)) {
            return response()->json([
                'error' => 'Invalid tokens parameter',
            ], 400);
        }

        // Limit to 50 tokens per request
        if (count($tokens) > 50) {
            return response()->json([
                'error' => 'Maximum 50 tokens per request',
            ], 400);
        }

        // Get prices
        $prices = $this->coinGeckoService->getPrices($tokens, $vsCurrency);

        // Convert object to array for better frontend compatibility
        $pricesArray = array_values($prices);

        return response()->json([
            'vs_currency' => $vsCurrency,
            'prices' => $pricesArray,
            'prices_map' => $prices, // Keep object format for backward compatibility
        ]);
    }
}

