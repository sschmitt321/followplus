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
            // Use triggerconstantcontract method (most reliable)
            // This method directly calls the contract's balanceOf function
            $addressHex = $this->addressToHex($address);
            $contractAddressHex = $this->addressToHex($this->contractAddress);
            
            // Parameter encoding for balanceOf(address):
            // Remove '41' prefix (first 2 chars) to get 20-byte address
            // Pad left with zeros to 64 hex chars (32 bytes)
            $addressWithoutPrefix = substr($addressHex, 2); // Remove '41' prefix
            $parameter = str_pad($addressWithoutPrefix, 64, '0', STR_PAD_LEFT);
            
            $url = "{$this->baseUrl}/wallet/triggerconstantcontract";
            
            $params = [
                'owner_address' => $addressHex,  // Must use hex format
                'contract_address' => $contractAddressHex,  // Must use hex format
                'function_selector' => 'balanceOf(address)',
                'parameter' => $parameter,
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
                    'response' => $response->body(),
                ]);
                return 0.0;
            }

            $data = $response->json();
            $result = $data['constant_result'][0] ?? '';
            
            if (empty($result)) {
                Log::warning('TronUsdtContract: Empty result from balance query', [
                    'address' => $address,
                    'response' => $data,
                ]);
                return 0.0;
            }

            // Parse hex result and convert to decimal (USDT has 6 decimals)
            // Remove 0x prefix if present
            $result = ltrim($result, '0x');
            $value = hexdec($result);
            return (float) bcdiv((string)$value, '1000000', 6);
        } catch (\Exception $e) {
            Log::error('TronUsdtContract: Exception getting balance', [
                'address' => $address,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
            
            $txid = $transactionService->transferTrc20(
                $privateKey,
                $toAddress,
                $amount,
                $this->contractAddress
            );
            
            if (!$txid) {
                Log::error('TronUsdtContract: transferTrc20 returned null', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'contract_address' => $this->contractAddress,
                    'note' => 'TronTransactionService::transferTrc20() may not be fully implemented. Please check if Tron SDK is properly integrated.',
                ]);
            }
            
            return $txid;
        } catch (\Exception $e) {
            Log::error('TronUsdtContract: Exception transferring USDT', [
                'to_address' => $toAddress,
                'amount' => $amount,
                'contract_address' => $this->contractAddress,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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

