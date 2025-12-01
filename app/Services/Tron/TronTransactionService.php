<?php

namespace App\Services\Tron;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tron Transaction Service
 * 
 * Handles transaction creation, signing, and broadcasting.
 * For production use, integrate a Tron SDK for proper transaction signing.
 */
class TronTransactionService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.tron.node_url', env('TRON_NODE_URL', 'https://api.trongrid.io'));
        $this->apiKey = config('services.tron.api_key', env('TRON_API_KEY', ''));
    }

    /**
     * Create and broadcast a TRC20 USDT transfer transaction.
     * 
     * @param string $fromPrivateKey Sender's private key (hex)
     * @param string $toAddress Recipient address
     * @param float $amount Amount in USDT
     * @param string $contractAddress USDT contract address
     * @return string|null Transaction ID or null on failure
     */
    public function transferTrc20(
        string $fromPrivateKey,
        string $toAddress,
        float $amount,
        string $contractAddress
    ): ?string {
        try {
            // Get sender address from private key using TronHelper
            $fromAddress = \App\Support\TronHelper::generateAddressFromPrivateKey($fromPrivateKey)['address'];
            $fromAddressHex = \App\Support\TronHelper::addressToHex($fromAddress);
            
            // Initialize Tron SDK for signing
            $tron = new \Tak\Tron\API($this->baseUrl, $fromPrivateKey);
            
            // Convert addresses to hex
            $contractAddressHex = \App\Support\TronHelper::addressToHex($contractAddress);
            $toAddressHex = \App\Support\TronHelper::addressToHex($toAddress);
            
            // Encode parameters for transfer(address,uint256)
            // Function selector: transfer(address,uint256) = a9059cbb (first 4 bytes of keccak256 hash)
            // Parameter 1: toAddress (32 bytes, padded)
            $toAddressParam = substr($toAddressHex, 2); // Remove '41' prefix
            $toAddressParam = str_pad($toAddressParam, 64, '0', STR_PAD_LEFT);
            
            // Parameter 2: amount (32 bytes, padded)
            $amountHex = \App\Support\TronHelper::usdtAmountToHex($amount);
            
            // Combine parameters
            $parameter = $toAddressParam . $amountHex;
            
            // Create triggerSmartContract transaction
            $url = "{$this->baseUrl}/wallet/triggersmartcontract";
            
            $params = [
                'owner_address' => $fromAddressHex,
                'contract_address' => $contractAddressHex,
                'function_selector' => 'transfer(address,uint256)',
                'parameter' => $parameter,
                'fee_limit' => 100000000, // 100 TRX fee limit
            ];
            
            $headers = [];
            if ($this->apiKey) {
                $headers['TRON-PRO-API-KEY'] = $this->apiKey;
            }
            
            $response = Http::withHeaders($headers)->post($url, $params);
            
            if (!$response->successful()) {
                Log::error('TronTransactionService: Failed to create TRC20 transaction', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return null;
            }
            
            $transactionData = $response->json();
            
            // Check for errors
            if (isset($transactionData['Error'])) {
                Log::error('TronTransactionService: TRC20 transaction creation error', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'error' => $transactionData['Error'],
                ]);
                return null;
            }
            
            // Get the transaction from the result
            $transaction = $transactionData['transaction'] ?? null;
            if (!$transaction) {
                Log::error('TronTransactionService: No transaction in response', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'response' => $transactionData,
                ]);
                return null;
            }
            
            // Sign transaction using takpesar/tron SDK
            $signedTransaction = $tron->signature($transaction);
            
            // Broadcast transaction
            $broadcastResult = $tron->broadcast($signedTransaction);
            
            // Check result
            if (isset($broadcastResult->result) && $broadcastResult->result === true) {
                $txid = $broadcastResult->txid ?? ($signedTransaction['txID'] ?? null);
                
                if ($txid) {
                    Log::info('TronTransactionService: TRC20 transfer successful', [
                        'to_address' => $toAddress,
                        'amount' => $amount,
                        'contract_address' => $contractAddress,
                        'txid' => $txid,
                    ]);
                    return $txid;
                }
            }
            
            // Check for errors
            if (isset($broadcastResult->Error)) {
                Log::error('TronTransactionService: TRC20 transfer broadcast error', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'error' => $broadcastResult->Error,
                ]);
            } else {
                Log::error('TronTransactionService: TRC20 transfer failed - unknown error', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'broadcast_result' => json_encode($broadcastResult),
                ]);
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('TronTransactionService: Exception transferring TRC20', [
                'to_address' => $toAddress,
                'amount' => $amount,
                'contract_address' => $contractAddress,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Create and broadcast a TRX transfer transaction.
     * 
     * @param string $fromPrivateKey Sender's private key (hex)
     * @param string $toAddress Recipient address
     * @param float $amount Amount in TRX
     * @return string|null Transaction ID or null on failure
     */
    public function transferTrx(
        string $fromPrivateKey,
        string $toAddress,
        float $amount
    ): ?string {
        Log::info('TronTransactionService: transferTrx called', [
            'to_address' => $toAddress,
            'amount' => $amount,
            'base_url' => $this->baseUrl,
            'private_key_length' => strlen($fromPrivateKey),
            'private_key_preview' => substr($fromPrivateKey, 0, 8) . '...',
        ]);
        
        try {
            // Use takpesar/tron SDK for TRX transfer
            Log::info('TronTransactionService: Initializing Tron API SDK', [
                'base_url' => $this->baseUrl,
            ]);
            
            // Generate address from private key (get both base58 and hex)
            $addressInfo = \App\Support\TronHelper::generateAddressFromPrivateKey($fromPrivateKey);
            $fromAddress = $addressInfo['address']; // base58 format
            $fromAddressHex = \App\Support\TronHelper::addressToHex($fromAddress); // hex format
            
            Log::info('TronTransactionService: Generated sender address from private key', [
                'from_address' => $fromAddress,
                'from_address_hex' => $fromAddressHex,
            ]);
            
            // Initialize SDK with private key and wallet address (hex format)
            // SDK expects hex address and will convert it to base58 internally
            $tron = new \Tak\Tron\API($this->baseUrl, $fromPrivateKey, $fromAddressHex);
            
            Log::info('TronTransactionService: SDK initialized, creating TRX transaction', [
                'from_address' => $fromAddress,
                'to_address' => $toAddress,
                'amount' => $amount,
            ]);
            
            $result = $tron->createTransaction($toAddress, $amount);
            
            Log::info('TronTransactionService: TRX transaction created', [
                'to_address' => $toAddress,
                'amount' => $amount,
                'result_type' => gettype($result),
                'result_class' => is_object($result) ? get_class($result) : null,
            ]);
            
            // Convert to array for easier handling
            $resultArray = (array) $result;
            
            Log::info('TronTransactionService: TRX transaction result converted', [
                'to_address' => $toAddress,
                'result_keys' => array_keys($resultArray),
                'result_preview' => json_encode(array_slice($resultArray, 0, 5, true)),
            ]);
            
            // The SDK's createTransaction returns an object merged from broadcast and signature
            // It may contain: result, txid, txID, Error, etc.
            
            // Check for transaction ID (various possible field names)
            $txid = $resultArray['txid'] ?? $resultArray['txID'] ?? null;
            
            // Check if transaction was successful
            // Success can be indicated by: result === true, or presence of txid without Error
            $isSuccess = false;
            if (isset($resultArray['result']) && $resultArray['result'] === true) {
                $isSuccess = true;
            } elseif ($txid && !isset($resultArray['Error'])) {
                // If we have a txid and no error, consider it successful
                $isSuccess = true;
            }
            
            if ($isSuccess && $txid) {
                Log::info('TronTransactionService: TRX transfer successful', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'txid' => $txid,
                ]);
                return $txid;
            }
            
            // Check for errors
            if (isset($resultArray['Error'])) {
                Log::error('TronTransactionService: TRX transfer failed', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'error' => $resultArray['Error'],
                    'result' => json_encode($resultArray),
                ]);
            } else {
                // Log the full result for debugging
                $errorDetails = [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'result' => json_encode($resultArray),
                    'result_keys' => array_keys($resultArray),
                    'has_txid' => !empty($txid),
                    'txid_value' => $txid,
                ];
                
                Log::error('TronTransactionService: TRX transfer failed - unknown error', $errorDetails);
                
                // Also log to help debug
                Log::error('TronTransactionService: Full result dump', [
                    'result_array' => $resultArray,
                    'result_json' => json_encode($resultArray, JSON_PRETTY_PRINT),
                ]);
            }
            
            return null;
        } catch (\Exception $e) {
            $errorMsg = "Exception: {$e->getMessage()}";
            if ($e->getCode()) {
                $errorMsg .= " (Code: {$e->getCode()})";
            }
            
            Log::error('TronTransactionService: Exception transferring TRX', [
                'to_address' => $toAddress,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Log the first few lines of trace for quick debugging
            $traceLines = explode("\n", $e->getTraceAsString());
            Log::error('TronTransactionService: Exception trace (first 5 lines)', [
                'trace_preview' => implode("\n", array_slice($traceLines, 0, 5)),
            ]);
            
            return null;
        }
    }

    /**
     * Broadcast a signed transaction.
     * 
     * @param array $signedTransaction Signed transaction object
     * @return array|null Transaction result or null on failure
     */
    public function broadcastTransaction(array $signedTransaction): ?array
    {
        try {
            $url = "{$this->baseUrl}/wallet/broadcasttransaction";
            
            $headers = [];
            if ($this->apiKey) {
                $headers['TRON-PRO-API-KEY'] = $this->apiKey;
            }

            $response = Http::withHeaders($headers)->post($url, $signedTransaction);

            if (!$response->successful()) {
                Log::error('TronTransactionService: Failed to broadcast transaction', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('TronTransactionService: Exception broadcasting transaction', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get transaction info by ID.
     * 
     * @param string $txid Transaction ID
     * @return array|null Transaction info or null on failure
     */
    public function getTransactionInfo(string $txid): ?array
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
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('TronTransactionService: Exception getting transaction info', [
                'txid' => $txid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

