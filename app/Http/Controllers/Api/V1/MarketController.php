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

    /**
     * Get exchange rate between two tokens.
     * 
     * Calculates the exchange rate by getting both tokens' prices relative to a base currency (USDT),
     * then dividing: base/quote = (base/USDT) / (quote/USDT)
     * 
     * Example: BTC/ETH = (BTC/USDT) / (ETH/USDT)
     * 
     * @param Request $request Query parameters
     * @param string $request->base Required. Base token symbol (e.g., "BTC")
     * @param string $request->quote Required. Quote token symbol (e.g., "ETH")
     * @param string|null $request->vs_currency Optional. Base currency for calculation (default: "usdt")
     * 
     * @return JsonResponse Returns exchange rate data
     * 
     * Query examples:
     * - ?base=BTC&quote=ETH (BTC/ETH rate)
     * - ?base=ETH&quote=BTC (ETH/BTC rate)
     * - ?base=BTC&quote=ETH&vs_currency=usd (using USD as base currency)
     */
    public function exchangeRate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base' => 'required|string|max:10',
            'quote' => 'required|string|max:10',
            'vs_currency' => 'nullable|string|max:10',
        ]);

        $baseToken = strtoupper($validated['base']);
        $quoteToken = strtoupper($validated['quote']);
        $vsCurrency = strtolower($validated['vs_currency'] ?? 'usdt');

        if ($baseToken === $quoteToken) {
            return response()->json([
                'error' => 'Base and quote tokens cannot be the same',
            ], 400);
        }

        $rate = $this->coinGeckoService->getExchangeRate($baseToken, $quoteToken, $vsCurrency);

        if ($rate === null) {
            return response()->json([
                'error' => 'Token not found or exchange rate unavailable',
                'base' => $baseToken,
                'quote' => $quoteToken,
            ], 404);
        }

        return response()->json($rate);
    }

    /**
     * Get OHLC (K-line) data for a token.
     * 
     * Returns candlestick/K-line data for charting purposes.
     * 
     * Data granularity (automatically determined by CoinGecko based on days):
     * - days = 1: 5-minute intervals (288 candles per day)
     * - days = 1-90: hourly intervals (24 candles per day)
     * - days > 90: daily intervals (1 candle per day, 00:00 UTC)
     * 
     * You can use either 'days' or 'interval' parameter:
     * - Use 'days' to specify exact number of days
     * - Use 'interval' to specify desired granularity (5m, 1h, 1d)
     * 
     * @param Request $request Query parameters
     * @param string $request->token Required. Token symbol (e.g., "BTC", "ETH")
     * @param string|null $request->vs_currency Optional. Target currency (default: "usd")
     * @param int|float|null $request->days Optional. Number of days of data (default: 1, max: 365)
     * @param string|null $request->interval Optional. Desired interval: "5m", "1h", "1d"
     *                                       If provided, will automatically calculate appropriate days value
     * 
     * @return JsonResponse Returns OHLC data array with timestamp, open, high, low, close
     * 
     * Query examples:
     * - ?token=BTC (1 day, 5-minute intervals)
     * - ?token=BTC&interval=5m (explicitly request 5-minute intervals, max 1 day)
     * - ?token=BTC&interval=1h&days=7 (explicitly request hourly intervals, 7 days)
     * - ?token=BTC&days=7 (7 days, hourly intervals)
     * - ?token=BTC&days=90&vs_currency=usdt (90 days, daily intervals)
     * - ?token=ETH&interval=1d&days=365 (explicitly request daily intervals, 365 days)
     */
    public function ohlc(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string', // Token symbol
            'vs_currency' => 'nullable|string|max:10', // Target currency
            'days' => 'nullable|numeric|min:0.01|max:365', // Number of days (can be float)
            'interval' => 'nullable|string|in:5m,5min,5-minute,1h,1hour,hourly,1d,1day,daily', // Desired interval
        ]);

        $tokenSymbol = strtoupper($validated['token']);
        $vsCurrency = strtolower($validated['vs_currency'] ?? 'usd');
        $days = isset($validated['days']) ? (float) $validated['days'] : 1;
        $interval = $validated['interval'] ?? null;

        $ohlcData = $this->coinGeckoService->getOHLC($tokenSymbol, $vsCurrency, $days, $interval);

        if ($ohlcData === null) {
            return response()->json([
                'error' => 'Token not found or OHLC data unavailable',
                'token' => $tokenSymbol,
            ], 404);
        }

        // Determine actual interval from data
        $actualInterval = 'unknown';
        if (count($ohlcData) > 1) {
            $timeDiff = ($ohlcData[1]['timestamp'] - $ohlcData[0]['timestamp']) / 1000; // seconds
            if ($timeDiff <= 600) { // <= 10 minutes
                $actualInterval = '5m';
            } elseif ($timeDiff <= 3600) { // <= 1 hour
                $actualInterval = '1h';
            } else {
                $actualInterval = '1d';
            }
        }

        return response()->json([
            'token' => $tokenSymbol,
            'vs_currency' => $vsCurrency,
            'days' => $days,
            'interval' => $interval ?? 'auto',
            'actual_interval' => $actualInterval,
            'data' => $ohlcData,
            'count' => count($ohlcData),
        ]);
    }
}

