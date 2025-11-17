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

        $cacheKey = "coingecko:price:{$coinId}:{$vsCurrency}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($coinId, $vsCurrency) {
            try {
                $response = Http::withHeaders([
                    'x-cg-demo-api-key' => self::API_KEY,
                ])->get(self::API_BASE_URL . '/simple/price', [
                    'ids' => $coinId,
                    'vs_currencies' => $vsCurrency,
                    'include_24hr_change' => 'true', // Use string instead of boolean
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    if (isset($data[$coinId])) {
                        $priceData = $data[$coinId];
                        return [
                            'coin_id' => $coinId,
                            'symbol' => $this->getSymbolFromCoinId($coinId),
                            'price' => $priceData[$vsCurrency] ?? null,
                            'price_usdt' => $priceData['usdt'] ?? null,
                            'change_24h' => $priceData[$vsCurrency . '_24h_change'] ?? null,
                            'change_24h_usdt' => $priceData['usdt_24h_change'] ?? null,
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
}

