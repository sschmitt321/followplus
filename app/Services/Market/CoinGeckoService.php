<?php

namespace App\Services\Market;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CoinGeckoService
{
    private const API_BASE_URL = 'https://api.coingecko.com/api/v3';
    private const API_KEY = 'CG-LApS6iKMyGAnaKS6nmTuVg4r';
    private const CACHE_TTL = 60; // 缓存 60 秒

    /**
     * Token symbol to CoinGecko ID mapping.
     * 
     * Maps common token symbols to their CoinGecko coin IDs.
     */
    private const TOKEN_MAP = [
        'BTC' => 'bitcoin',
        'ETH' => 'ethereum',
        'BNB' => 'binancecoin',
        'SOL' => 'solana',
        'XRP' => 'ripple',
        'USDT' => 'tether',
        'USDC' => 'usd-coin',
        'ADA' => 'cardano',
        'DOGE' => 'dogecoin',
        'DOT' => 'polkadot',
        'MATIC' => 'matic-network',
        'AVAX' => 'avalanche-2',
        'LINK' => 'chainlink',
        'UNI' => 'uniswap',
        'ATOM' => 'cosmos',
        'LTC' => 'litecoin',
        'ETC' => 'ethereum-classic',
        'XLM' => 'stellar',
        'ALGO' => 'algorand',
        'VET' => 'vechain',
    ];

    /**
     * Default tokens to query.
     */
    private const DEFAULT_TOKENS = ['BTC', 'ETH', 'BNB', 'SOL', 'XRP'];

    /**
     * Get CoinGecko coin ID from token symbol.
     */
    public function getCoinId(string $symbol): ?string
    {
        $symbol = strtoupper($symbol);
        return self::TOKEN_MAP[$symbol] ?? null;
    }

    /**
     * Get price and 24h change for a single token.
     * 
     * @param string $tokenSymbol Token symbol (e.g., 'BTC', 'ETH')
     * @param string $vsCurrency Target currency (default: 'usdt')
     * @return array|null Returns price data or null if token not found
     */
    public function getPrice(string $tokenSymbol, string $vsCurrency = 'usdt'): ?array
    {
        $coinId = $this->getCoinId($tokenSymbol);
        
        if (!$coinId) {
            return null;
        }

        // If querying USDT, also include USD for better compatibility (USDT is pegged to USD)
        $currencies = $vsCurrency === 'usdt' ? 'usd,usdt' : $vsCurrency;
        $cacheKey = "coingecko:price:{$coinId}:{$currencies}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($coinId, $vsCurrency, $currencies) {
            try {
                $response = Http::withHeaders([
                    'x-cg-demo-api-key' => self::API_KEY,
                ])->get(self::API_BASE_URL . '/simple/price', [
                    'ids' => $coinId,
                    'vs_currencies' => $currencies,
                    'include_24hr_change' => 'true', // Use string instead of boolean
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    if (isset($data[$coinId])) {
                        $priceData = $data[$coinId];
                        
                        // Get price for requested currency
                        $price = $priceData[$vsCurrency] ?? null;
                        
                        // Get USDT price - prefer usdt, fallback to usd (USDT is pegged to USD)
                        $priceUsdt = $priceData['usdt'] ?? null;
                        if ($priceUsdt === null && isset($priceData['usd'])) {
                            $priceUsdt = $priceData['usd']; // USDT is pegged to USD
                        }
                        
                        // Get 24h change for requested currency
                        $change24h = $priceData[$vsCurrency . '_24h_change'] ?? null;
                        
                        // Get USDT 24h change
                        $change24hUsdt = $priceData['usdt_24h_change'] ?? null;
                        if ($change24hUsdt === null && isset($priceData['usd_24h_change'])) {
                            $change24hUsdt = $priceData['usd_24h_change']; // Use USD change as approximation
                        }
                        
                        return [
                            'coin_id' => $coinId,
                            'symbol' => $this->getSymbolFromCoinId($coinId),
                            'price' => $price,
                            'price_usdt' => $priceUsdt,
                            'change_24h' => $change24h,
                            'change_24h_usdt' => $change24hUsdt,
                            'updated_at' => now()->toIso8601String(),
                        ];
                    }
                }

                Log::warning('CoinGecko API error', [
                    'coin_id' => $coinId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('CoinGecko API exception', [
                    'coin_id' => $coinId,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Get prices for multiple tokens.
     * 
     * @param array $tokenSymbols Array of token symbols (e.g., ['BTC', 'ETH'])
     * @param string $vsCurrency Target currency (default: 'usdt')
     * @return array Returns array of price data indexed by token symbol
     */
    public function getPrices(array $tokenSymbols, string $vsCurrency = 'usdt'): array
    {
        // Convert symbols to coin IDs
        $coinIds = [];
        $symbolToCoinId = [];
        
        foreach ($tokenSymbols as $symbol) {
            $coinId = $this->getCoinId($symbol);
            if ($coinId) {
                $coinIds[] = $coinId;
                $symbolToCoinId[$symbol] = $coinId;
            }
        }

        if (empty($coinIds)) {
            return [];
        }

        $coinIdsString = implode(',', $coinIds);
        
        // If querying USD, also include USDT for better compatibility
        $currencies = $vsCurrency === 'usd' ? 'usd,usdt' : $vsCurrency;
        $cacheKey = "coingecko:prices:" . md5($coinIdsString) . ":{$currencies}";

        $results = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($coinIdsString, $currencies) {
            try {
                $response = Http::withHeaders([
                    'x-cg-demo-api-key' => self::API_KEY,
                ])->get(self::API_BASE_URL . '/simple/price', [
                    'ids' => $coinIdsString,
                    'vs_currencies' => $currencies,
                    'include_24hr_change' => 'true', // Use string instead of boolean
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                Log::warning('CoinGecko API batch error', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return [];
            } catch (\Exception $e) {
                Log::error('CoinGecko API batch exception', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });

        // Format results
        $formatted = [];
        foreach ($symbolToCoinId as $symbol => $coinId) {
            if (isset($results[$coinId])) {
                $priceData = $results[$coinId];
                
                // Get price for requested currency
                $price = $priceData[$vsCurrency] ?? null;
                
                // Get USDT price - if not available and querying USD, use USD price as approximation
                $priceUsdt = $priceData['usdt'] ?? null;
                if ($priceUsdt === null && $vsCurrency === 'usd' && $price !== null) {
                    $priceUsdt = $price; // USDT is pegged to USD, so use USD price as approximation
                }
                
                // Get 24h change for requested currency
                $change24h = $priceData[$vsCurrency . '_24h_change'] ?? null;
                
                // Get USDT 24h change - if not available and querying USD, use USD change as approximation
                $change24hUsdt = $priceData['usdt_24h_change'] ?? null;
                if ($change24hUsdt === null && $vsCurrency === 'usd' && $change24h !== null) {
                    $change24hUsdt = $change24h; // Use USD change as approximation
                }
                
                $formatted[$symbol] = [
                    'coin_id' => $coinId,
                    'symbol' => $symbol,
                    'price' => $price,
                    'price_usdt' => $priceUsdt,
                    'change_24h' => $change24h,
                    'change_24h_usdt' => $change24hUsdt,
                    'updated_at' => now()->toIso8601String(),
                ];
            } else {
                $formatted[$symbol] = [
                    'symbol' => $symbol,
                    'error' => 'Token not found or price data unavailable',
                ];
            }
        }

        return $formatted;
    }

    /**
     * Get default tokens prices.
     * 
     * @param string $vsCurrency Target currency (default: 'usdt')
     * @return array Returns price data for default tokens
     */
    public function getDefaultPrices(string $vsCurrency = 'usdt'): array
    {
        return $this->getPrices(self::DEFAULT_TOKENS, $vsCurrency);
    }

    /**
     * Get token symbol from CoinGecko coin ID.
     */
    private function getSymbolFromCoinId(string $coinId): ?string
    {
        $map = array_flip(self::TOKEN_MAP);
        return $map[$coinId] ?? null;
    }

    /**
     * Get all supported token symbols.
     * 
     * @return array Array of supported token symbols
     */
    public function getSupportedTokens(): array
    {
        return array_keys(self::TOKEN_MAP);
    }

    /**
     * Get OHLC (Open, High, Low, Close) data for a token.
     * 
     * Returns candlestick/K-line data for charting.
     * Data granularity (automatically determined by CoinGecko):
     * - 1 day = 5-minute intervals (288 candles per day)
     * - 1-90 days = hourly intervals
     * - 90+ days = daily intervals (00:00 UTC)
     * 
     * @param string $tokenSymbol Token symbol (e.g., 'BTC', 'ETH')
     * @param string $vsCurrency Target currency (default: 'usd')
     * @param int|float $days Number of days of data (default: 1)
     *                        Use 1 for 5-minute intervals, 1-90 for hourly, 90+ for daily
     * @param string|null $interval Optional. Desired interval: '5m', '1h', '1d'
     *                              If provided, will calculate appropriate days value
     * @return array|null Returns array of OHLC data or null if token not found
     *                    Format: [[timestamp_ms, open, high, low, close], ...]
     */
    public function getOHLC(string $tokenSymbol, string $vsCurrency = 'usd', int|float $days = 1, ?string $interval = null): ?array
    {
        $coinId = $this->getCoinId($tokenSymbol);
        
        if (!$coinId) {
            return null;
        }

        // If interval is specified, calculate appropriate days
        if ($interval !== null) {
            $days = $this->calculateDaysFromInterval($interval, $days);
        }

        // Validate days parameter
        if ($days < 1) {
            $days = 1;
        }
        if ($days > 365) {
            $days = 365; // Limit to 1 year
        }

        $cacheKey = "coingecko:ohlc:{$coinId}:{$vsCurrency}:{$days}";
        $cacheTTL = min(self::CACHE_TTL, 300); // OHLC data cache for max 5 minutes

        return Cache::remember($cacheKey, $cacheTTL, function () use ($coinId, $vsCurrency, $days) {
            try {
                $response = Http::withHeaders([
                    'x-cg-demo-api-key' => self::API_KEY,
                ])->get(self::API_BASE_URL . "/coins/{$coinId}/ohlc", [
                    'vs_currency' => $vsCurrency,
                    'days' => $days,
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    // Format the data for easier frontend consumption
                    $formatted = [];
                    foreach ($data as $candle) {
                        $formatted[] = [
                            'timestamp' => $candle[0], // Unix timestamp in milliseconds
                            'open' => $candle[1],
                            'high' => $candle[2],
                            'low' => $candle[3],
                            'close' => $candle[4],
                        ];
                    }

                    return $formatted;
                }

                Log::warning('CoinGecko OHLC API error', [
                    'coin_id' => $coinId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('CoinGecko OHLC API exception', [
                    'coin_id' => $coinId,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Calculate appropriate days value based on desired interval.
     * 
     * CoinGecko API granularity rules:
     * - days = 1: 5-minute intervals
     * - days = 1-90: hourly intervals
     * - days > 90: daily intervals
     * 
     * @param string $interval Desired interval: '5m', '1h', '1d'
     * @param int|float $maxDays Maximum days of data to retrieve
     * @return float Calculated days value
     */
    private function calculateDaysFromInterval(string $interval, int|float $maxDays = 1): float
    {
        return match (strtolower($interval)) {
            '5m', '5min', '5-minute' => min(1.0, $maxDays), // 5-minute requires days <= 1
            '1h', '1hour', 'hourly' => min(90.0, max(1.0, $maxDays)), // Hourly requires 1 <= days <= 90
            '1d', '1day', 'daily' => max(90.0, $maxDays), // Daily requires days > 90
            default => (float) $maxDays, // Unknown interval, use provided days
        };
    }

    /**
     * Get exchange rate between two tokens (e.g., BTC/ETH).
     * 
     * Calculates the exchange rate by getting both tokens' prices relative to a base currency (USDT),
     * then dividing: base/quote = (base/USDT) / (quote/USDT)
     * 
     * @param string $baseToken Base token symbol (e.g., 'BTC')
     * @param string $quoteToken Quote token symbol (e.g., 'ETH')
     * @param string $vsCurrency Base currency for calculation (default: 'usdt')
     * @return array|null Returns exchange rate data or null if tokens not found
     *                    Format: ['pair' => 'BTC/ETH', 'rate' => 15.5, 'base_price' => 50000, 'quote_price' => 3225.8, ...]
     */
    public function getExchangeRate(string $baseToken, string $quoteToken, string $vsCurrency = 'usdt'): ?array
    {
        $baseTokenUpper = strtoupper($baseToken);
        $quoteTokenUpper = strtoupper($quoteToken);
        $vsCurrencyLower = strtolower($vsCurrency);

        // Special case: if quote is USDT, directly query base token price in USDT
        // USDT/USDT = 1, so we can simplify the calculation
        if ($quoteTokenUpper === 'USDT') {
            // Query base token price in USDT (or USD if USDT not available)
            $basePrice = $this->getPrice($baseToken, 'usdt');
            
            // If USDT price not available, try USD (USDT is pegged to USD)
            if (!$basePrice || ($basePrice['price_usdt'] === null && $basePrice['price'] === null)) {
                $basePrice = $this->getPrice($baseToken, 'usd');
            }

            if (!$basePrice) {
                return null;
            }

            // Get base price value (prefer USDT, fallback to USD)
            $basePriceValue = $basePrice['price_usdt'] ?? $basePrice['price'] ?? null;
            
            if ($basePriceValue === null) {
                return null;
            }

            // Convert to float for proper JSON serialization
            $basePriceFloat = (float) $basePriceValue;

            // USDT price is always 1 (pegged to USD)
            $quotePriceValue = 1.0;

            // Calculate rate: base/USDT = base_price / 1
            $rate = $basePriceFloat;

            // Convert change_24h to float
            $baseChange24h = $basePrice['change_24h_usdt'] ?? $basePrice['change_24h'] ?? null;

            return [
                'pair' => $baseTokenUpper . '/' . $quoteTokenUpper,
                'base_token' => $baseTokenUpper,
                'quote_token' => $quoteTokenUpper,
                'rate' => round($rate, 6), // Round to 6 decimal places
                'base_price' => $basePriceFloat,
                'quote_price' => $quotePriceValue,
                'base_price_usdt' => $basePriceFloat,
                'quote_price_usdt' => 1.0,
                'base_change_24h' => $baseChange24h !== null ? (float) $baseChange24h : null,
                'quote_change_24h' => 0.0,
                'vs_currency' => 'usdt',
                'updated_at' => now()->toIso8601String(),
            ];
        }

        // For other quote tokens, use the standard calculation method
        // Always use USDT as base currency for calculation (or USD if USDT not available)
        // This ensures we get consistent price data
        $actualVsCurrency = 'usdt';

        // Get prices for both tokens (will automatically include USD as fallback)
        $basePrice = $this->getPrice($baseToken, $actualVsCurrency);
        $quotePrice = $this->getPrice($quoteToken, $actualVsCurrency);

        if (!$basePrice || !$quotePrice) {
            return null;
        }

        // Get price values - prefer price_usdt, fallback to price (which might be USD)
        // Convert to float to ensure proper JSON serialization
        $basePriceValue = $basePrice['price_usdt'] ?? $basePrice['price'] ?? null;
        $quotePriceValue = $quotePrice['price_usdt'] ?? $quotePrice['price'] ?? null;

        if ($basePriceValue === null || $quotePriceValue === null || $quotePriceValue == 0) {
            return null;
        }

        // Convert to float for calculation
        $basePriceFloat = (float) $basePriceValue;
        $quotePriceFloat = (float) $quotePriceValue;

        // Calculate exchange rate: base/quote = (base/USDT) / (quote/USDT)
        $rate = $basePriceFloat / $quotePriceFloat;

        // Convert change_24h to float (handle null values)
        $baseChange24h = $basePrice['change_24h_usdt'] ?? $basePrice['change_24h'] ?? null;
        $quoteChange24h = $quotePrice['change_24h_usdt'] ?? $quotePrice['change_24h'] ?? null;

        return [
            'pair' => $baseTokenUpper . '/' . $quoteTokenUpper,
            'base_token' => $baseTokenUpper,
            'quote_token' => $quoteTokenUpper,
            'rate' => round($rate, 6), // Round to 6 decimal places
            'base_price' => $basePriceFloat,
            'quote_price' => $quotePriceFloat,
            'base_price_usdt' => $basePriceFloat,
            'quote_price_usdt' => $quotePriceFloat,
            'base_change_24h' => $baseChange24h !== null ? (float) $baseChange24h : null,
            'quote_change_24h' => $quoteChange24h !== null ? (float) $quoteChange24h : null,
            'vs_currency' => $vsCurrencyLower,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}

