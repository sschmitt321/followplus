<?php

namespace App\Services\Tron;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TronUsdtContract
{
    private string $baseUrl;
    private string $apiKey;
    private string $contractAddress;

    public function __construct()
    {
        $this->baseUrl = config('services.tron.node_url', env('TRON_NODE_URL', 'https://api.trongrid.io'));
        $this->apiKey = config('services.tron.api_key', env('TRON_API_KEY', ''));
        $this->contractAddress = config('services.tron.usdt_contract', env('TRON_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'));
    }

    /**
     * Get USDT balance for an address.
     */
    public function getBalance(string $address): float
    {
        try {
            // Call balanceOf(address) function
            $functionSelector = '70a08231'; // balanceOf(address) function selector
            $addressHex = $this->addressToHex($address);
            
            $url = "{$this->baseUrl}/wallet/triggerconstantcontract";
            
            $params = [
                'owner_address' => $address,
                'contract_address' => $this->contractAddress,
                'function_selector' => 'balanceOf(address)',
                'parameter' => $addressHex,
            ];

            $headers = [];
            if ($this->apiKey) {
                $headers['TRON-PRO-API-KEY'] = $this->apiKey;
            }

            $response = Http::withHeaders($headers)->post($url, $params);

            if (!$response->successful()) {
                Log::error('TronUsdtContract: Failed to get balance', [
                    'address' => $address,
                    'status' => $response->status(),
                ]);
                return 0.0;
            }

            $data = $response->json();
            $result = $data['constant_result'][0] ?? '';
            
            if (empty($result)) {
                return 0.0;
            }

            // Parse hex result and convert to decimal (USDT has 6 decimals)
            $value = hexdec($result);
            return (float) bcdiv((string)$value, '1000000', 6);
        } catch (\Exception $e) {
            Log::error('TronUsdtContract: Exception getting balance', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    /**
     * Transfer USDT using private key.
     */
    public function transferFromPrivateKey(string $privateKey, string $toAddress, float $amount): ?string
    {
        try {
            $transactionService = app(TronTransactionService::class);
            
            return $transactionService->transferTrc20(
                $privateKey,
                $toAddress,
                $amount,
                $this->contractAddress
            );
        } catch (\Exception $e) {
            Log::error('TronUsdtContract: Error transferring USDT', [
                'to_address' => $toAddress,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Convert Tron address to hex format.
     */
    private function addressToHex(string $address): string
    {
        return \App\Support\TronHelper::addressToHex($address);
    }
}

