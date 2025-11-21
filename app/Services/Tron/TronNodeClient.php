<?php

namespace App\Services\Tron;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TronNodeClient
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.tron.node_url', env('TRON_NODE_URL', 'https://api.trongrid.io'));
        $this->apiKey = config('services.tron.api_key', env('TRON_API_KEY', ''));
    }

    /**
     * Get USDT Transfer events.
     * 
     * @param int|null $minBlockTimestamp Optional. Minimum block timestamp
     * @param int|null $maxBlockTimestamp Optional. Maximum block timestamp
     * @param string|null $onlyTo Optional. Filter by 'to' address
     * @return array Array of transfer events
     */
    public function getUsdtTransferEvents(
        ?int $minBlockTimestamp = null,
        ?int $maxBlockTimestamp = null,
        ?string $onlyTo = null
    ): array {
        // USDT TRC20 contract address
        $contractAddress = config('services.tron.usdt_contract', env('TRON_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'));
        
        // TronGrid API expects base58 format for contract address in URL
        // Keep the original base58 address for the URL
        $contractAddressForUrl = $contractAddress;
        
        // Convert to hex for filtering if needed
        $contractAddressHex = $this->addressToHex($contractAddress);

        $url = "{$this->baseUrl}/v1/contracts/{$contractAddressForUrl}/events";
        
        $params = [
            'event_name' => 'Transfer',
            'only_confirmed' => 'true',
            'limit' => 200,
        ];

        if ($minBlockTimestamp) {
            $params['min_block_timestamp'] = $minBlockTimestamp;
        }

        if ($maxBlockTimestamp) {
            $params['max_block_timestamp'] = $maxBlockTimestamp;
        }

        try {
            $headers = [];
            if ($this->apiKey) {
                $headers['TRON-PRO-API-KEY'] = $this->apiKey;
            }

            Log::info('TronNodeClient: Requesting USDT transfer events', [
                'url' => $url,
                'params' => $params,
                'min_timestamp' => $minBlockTimestamp ? date('Y-m-d H:i:s', $minBlockTimestamp / 1000) : null,
            ]);

            $startTime = microtime(true);
            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->get($url, $params);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if (!$response->successful()) {
                Log::error('TronNodeClient: Failed to get USDT transfer events', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'duration_ms' => $duration,
                ]);
                return [];
            }

            $data = $response->json();
            $events = $data['data'] ?? [];

            Log::info('TronNodeClient: Received USDT transfer events response', [
                'raw_event_count' => count($events),
                'duration_ms' => $duration,
            ]);

            // Filter by 'to' address if specified
            if ($onlyTo) {
                $onlyToHex = $this->addressToHex($onlyTo);
                $events = array_filter($events, function ($event) use ($onlyToHex) {
                    $to = $event['result']['to'] ?? '';
                    return strtolower($to) === strtolower($onlyToHex);
                });
            }

            // Format events
            $formatted = [];
            foreach ($events as $index => $event) {
                $result = $event['result'] ?? [];
                
                // TronGrid API may return transaction ID in different fields
                $txid = $event['transaction'] ?? $event['transactionHash'] ?? $event['txid'] ?? '';
                
                // Log first event structure for debugging
                if ($index === 0 && count($events) > 0) {
                    Log::debug('TronNodeClient: Sample event structure', [
                        'event_keys' => array_keys($event),
                        'result_keys' => array_keys($result),
                        'transaction_field' => $txid ?: 'not found',
                        'from_raw' => $result['from'] ?? 'not found',
                        'to_raw' => $result['to'] ?? 'not found',
                    ]);
                }
                
                // TronGrid API returns addresses in 0x... format (20 bytes = 40 hex chars + 0x prefix)
                // Convert to 41... format for consistency
                $fromHex = $result['from'] ?? '';
                $toHex = $result['to'] ?? '';
                
                // Remove 0x prefix if present and add 41 prefix
                if (str_starts_with($fromHex, '0x') || str_starts_with($fromHex, '0X')) {
                    $fromHex = '41' . substr($fromHex, 2);
                } elseif (!str_starts_with($fromHex, '41')) {
                    $fromHex = '41' . $fromHex;
                }
                
                if (str_starts_with($toHex, '0x') || str_starts_with($toHex, '0X')) {
                    $toHex = '41' . substr($toHex, 2);
                } elseif (!str_starts_with($toHex, '41')) {
                    $toHex = '41' . $toHex;
                }
                
                $formatted[] = [
                    'txid' => $txid,
                    'from' => $this->hexToAddress($fromHex),
                    'to' => $this->hexToAddress($toHex),
                    'amount' => $this->parseUsdtAmount($result['value'] ?? '0'),
                    'block_timestamp' => $event['block_timestamp'] ?? 0,
                    'block_number' => $event['block_number'] ?? 0,
                ];
            }

            Log::info('TronNodeClient: Formatted USDT transfer events', [
                'formatted_count' => count($formatted),
            ]);

            return $formatted;
        } catch (\Exception $e) {
            Log::error('TronNodeClient: Exception getting USDT transfer events', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get transaction confirmations.
     */
    public function getConfirmations(string $txid): int
    {
        try {
            $url = "{$this->baseUrl}/wallet/gettransactionbyid";
            
            $headers = [];
            if ($this->apiKey) {
                $headers['TRON-PRO-API-KEY'] = $this->apiKey;
            }

            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->post($url, [
                    'value' => $txid,
                ]);

            if (!$response->successful()) {
                return 0;
            }

            $data = $response->json();
            
            if (!isset($data['ret'][0]['contractRet']) || $data['ret'][0]['contractRet'] !== 'SUCCESS') {
                return 0;
            }

            // Get current block number
            $currentBlock = $this->getCurrentBlockNumber();
            $txBlock = $data['blockNumber'] ?? 0;

            return max(0, $currentBlock - $txBlock);
        } catch (\Exception $e) {
            Log::error('TronNodeClient: Exception getting confirmations', [
                'txid' => $txid,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get current block number.
     */
    public function getCurrentBlockNumber(): int
    {
        try {
            $url = "{$this->baseUrl}/wallet/getnowblock";
            
            $headers = [];
            if ($this->apiKey) {
                $headers['TRON-PRO-API-KEY'] = $this->apiKey;
            }

            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->get($url);

            if (!$response->successful()) {
                return 0;
            }

            $data = $response->json();
            return $data['block_header']['raw_data']['number'] ?? 0;
        } catch (\Exception $e) {
            Log::error('TronNodeClient: Exception getting current block number', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get TRX balance.
     */
    public function getTrxBalance(string $address): float
    {
        try {
            $url = "{$this->baseUrl}/v1/accounts/{$address}";
            
            $headers = [];
            if ($this->apiKey) {
                $headers['TRON-PRO-API-KEY'] = $this->apiKey;
            }

            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->get($url);

            if (!$response->successful()) {
                return 0.0;
            }

            $data = $response->json();
            $balance = $data['balance'] ?? 0;
            
            // Convert from sun to TRX (1 TRX = 1,000,000 sun)
            return $balance / 1000000;
        } catch (\Exception $e) {
            Log::error('TronNodeClient: Exception getting TRX balance', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    /**
     * Convert Tron address to hex format.
     */
    private function addressToHex(string $address): string
    {
        return \App\Support\TronHelper::addressToHex($address);
    }

    /**
     * Convert hex address to Tron address format.
     */
    private function hexToAddress(string $hex): string
    {
        return \App\Support\TronHelper::hexToAddress($hex);
    }

    /**
     * Get TRC20 transactions for a specific account address.
     * This is more reliable than events API as it queries transactions directly for the address.
     * 
     * @param string $address Account address (base58)
     * @param int|null $minTimestamp Optional. Minimum timestamp in milliseconds
     * @return array Array of transactions
     */
    public function getAccountTrc20Transactions(string $address, ?int $minTimestamp = null): array
    {
        try {
            $url = "{$this->baseUrl}/v1/accounts/{$address}/transactions/trc20";
            
            $params = [
                'limit' => 200,
            ];
            
            // Get USDT contract address
            $contractAddress = config('services.tron.usdt_contract', env('TRON_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'));
            $params['contract_address'] = $contractAddress;
            
            if ($minTimestamp) {
                $params['min_timestamp'] = $minTimestamp;
            }

            $headers = [];
            if ($this->apiKey) {
                $headers['TRON-PRO-API-KEY'] = $this->apiKey;
            }

            Log::info('TronNodeClient: Requesting account TRC20 transactions', [
                'address' => $address,
                'params' => $params,
            ]);

            $startTime = microtime(true);
            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->get($url, $params);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if (!$response->successful()) {
                Log::error('TronNodeClient: Failed to get account TRC20 transactions', [
                    'address' => $address,
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'duration_ms' => $duration,
                ]);
                return [];
            }

            $data = $response->json();
            $transactions = $data['data'] ?? [];

            Log::info('TronNodeClient: Received account TRC20 transactions', [
                'address' => $address,
                'transaction_count' => count($transactions),
                'duration_ms' => $duration,
            ]);

            // Format transactions
            $formatted = [];
            foreach ($transactions as $tx) {
                // Only process incoming transactions (to this address)
                $to = $tx['to'] ?? '';
                if (strtolower($to) !== strtolower($address)) {
                    continue;
                }

                $txid = $tx['transaction_id'] ?? '';
                $from = $tx['from'] ?? '';
                $value = $tx['value'] ?? '0';
                $tokenInfo = $tx['token_info'] ?? [];
                $tokenSymbol = $tokenInfo['symbol'] ?? 'USDT';
                $decimals = (int) ($tokenInfo['decimals'] ?? 6);
                
                // Convert value to amount
                // Account transactions API returns value as decimal string, not hex
                $amount = bcdiv((string)$value, bcpow('10', (string)$decimals, 0), $decimals);

                $formatted[] = [
                    'txid' => $txid,
                    'from' => $from,
                    'to' => $to,
                    'amount' => $amount,
                    'token_symbol' => $tokenSymbol,
                    'block_timestamp' => $tx['block_timestamp'] ?? 0,
                ];
            }

            return $formatted;
        } catch (\Exception $e) {
            Log::error('TronNodeClient: Exception getting account TRC20 transactions', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Parse USDT amount from hex string.
     * USDT has 6 decimals.
     */
    private function parseUsdtAmount(string $hexValue): string
    {
        return \App\Support\TronHelper::hexToUsdtAmount($hexValue);
    }
}

