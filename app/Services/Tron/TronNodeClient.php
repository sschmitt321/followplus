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
        
        // Convert contract address to hex if needed
        $contractAddressHex = $this->addressToHex($contractAddress);

        $url = "{$this->baseUrl}/v1/contracts/{$contractAddressHex}/events";
        
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

            $response = Http::withHeaders($headers)->get($url, $params);

            if (!$response->successful()) {
                Log::error('TronNodeClient: Failed to get USDT transfer events', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return [];
            }

            $data = $response->json();
            $events = $data['data'] ?? [];

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
            foreach ($events as $event) {
                $result = $event['result'] ?? [];
                $formatted[] = [
                    'txid' => $event['transaction'] ?? '',
                    'from' => $this->hexToAddress($result['from'] ?? ''),
                    'to' => $this->hexToAddress($result['to'] ?? ''),
                    'amount' => $this->parseUsdtAmount($result['value'] ?? '0'),
                    'block_timestamp' => $event['block_timestamp'] ?? 0,
                    'block_number' => $event['block_number'] ?? 0,
                ];
            }

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

            $response = Http::withHeaders($headers)->post($url, [
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

            $response = Http::withHeaders($headers)->get($url);

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

            $response = Http::withHeaders($headers)->get($url);

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
     * Parse USDT amount from hex string.
     * USDT has 6 decimals.
     */
    private function parseUsdtAmount(string $hexValue): string
    {
        return \App\Support\TronHelper::hexToUsdtAmount($hexValue);
    }
}

