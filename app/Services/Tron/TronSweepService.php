<?php

namespace App\Services\Tron;

use App\Models\TronSweep;
use App\Models\UserTronWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TronSweepService
{
    private float $minSweepAmount;
    private string $hotWalletAddress;
    private float $minTrxBalance;
    private float $gasBankTrxAmount; // Amount of TRX to send from gas bank

    public function __construct(
        private TronWalletService $walletService,
        private TronNodeClient $nodeClient,
        private TronUsdtContract $usdtContract
    ) {
        $this->minSweepAmount = (float) config('services.tron.min_sweep_amount', env('TRON_MIN_SWEEP_AMOUNT', 50.0));
        $this->hotWalletAddress = config('services.tron.hot_wallet_address', env('TRON_HOT_WALLET_ADDRESS', ''));
        $this->minTrxBalance = (float) config('services.tron.min_trx_balance', env('TRON_MIN_TRX_BALANCE', 1.0));
        // Amount of TRX to send from gas bank (should cover gas fee ~5.x TRX per transaction)
        $this->gasBankTrxAmount = (float) config('services.tron.gas_bank_trx_amount', env('TRON_GAS_BANK_TRX_AMOUNT', 6.0));
    }

    /**
     * Sweep all eligible addresses.
     * 
     * @param callable|null $outputCallback Optional callback for console output: function(string $message, string $type = 'info')
     */
    public function sweepAll(?callable $outputCallback = null): int
    {
        if (empty($this->hotWalletAddress)) {
            $message = 'Hot wallet address not configured';
            Log::warning('TronSweepService: ' . $message);
            if ($outputCallback) {
                $outputCallback($message, 'error');
            }
            return 0;
        }

        $message = "Starting sweep operation (Hot wallet: {$this->hotWalletAddress}, Min amount: {$this->minSweepAmount} USDT)";
        Log::info('TronSweepService: Starting sweep operation', [
            'hot_wallet' => $this->hotWalletAddress,
            'min_sweep_amount' => $this->minSweepAmount,
        ]);
        if ($outputCallback) {
            $outputCallback($message, 'info');
        }

        $swept = 0;
        $skipped = 0;
        $errors = 0;
        $wallets = UserTronWallet::all();
        $totalWallets = $wallets->count();

        if ($outputCallback) {
            $outputCallback("Found {$totalWallets} wallet(s) to check", 'info');
        }

        foreach ($wallets as $index => $wallet) {
            $progress = "[" . ($index + 1) . "/{$totalWallets}]";
            
            try {
                $result = $this->sweepAddress($wallet, $outputCallback);
                if ($result) {
                    $swept++;
                    if ($outputCallback) {
                        $outputCallback("{$progress} ✓ Swept user #{$wallet->user_id} ({$wallet->tron_address})", 'info');
                    }
                } else {
                    $skipped++;
                    if ($outputCallback) {
                        $outputCallback("{$progress} ⊘ Skipped user #{$wallet->user_id} (balance below threshold)", 'comment');
                    }
                }
            } catch (\Exception $e) {
                $errors++;
                $errorMsg = "{$progress} ✗ Error sweeping user #{$wallet->user_id}: {$e->getMessage()}";
                Log::error('TronSweepService: Error sweeping address', [
                    'user_id' => $wallet->user_id,
                    'address' => $wallet->tron_address,
                    'error' => $e->getMessage(),
                ]);
                if ($outputCallback) {
                    $outputCallback($errorMsg, 'error');
                }
            }
        }

        $summary = "Sweep completed: {$swept} swept, {$skipped} skipped, {$errors} errors";
        Log::info('TronSweepService: Sweep operation completed', [
            'total_wallets' => $totalWallets,
            'swept' => $swept,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
        if ($outputCallback) {
            $outputCallback($summary, 'info');
        }

        return $swept;
    }

    /**
     * Sweep a single address.
     * 
     * @param UserTronWallet $wallet
     * @param callable|null $outputCallback Optional callback for console output
     * @return bool True if sweep was performed, false if skipped
     */
    private function sweepAddress(UserTronWallet $wallet, ?callable $outputCallback = null): bool
    {
        $address = $wallet->tron_address;

        // Check USDT balance
        if ($outputCallback) {
            $outputCallback("  Checking balance for user #{$wallet->user_id}...", 'comment');
        }
        
        $usdtBalance = $this->usdtContract->getBalance($address);
        
        if ($usdtBalance < $this->minSweepAmount) {
            Log::info('TronSweepService: Skipping address (balance below threshold)', [
                'user_id' => $wallet->user_id,
                'address' => $address,
                'balance' => $usdtBalance,
                'min_threshold' => $this->minSweepAmount,
            ]);
            if ($outputCallback) {
                $outputCallback("  Balance: {$usdtBalance} USDT (below threshold: {$this->minSweepAmount} USDT)", 'comment');
            }
            return false; // Skip if balance is below threshold
        }

        Log::info('TronSweepService: Sweeping address', [
            'user_id' => $wallet->user_id,
            'address' => $address,
            'usdt_balance' => $usdtBalance,
        ]);
        
        if ($outputCallback) {
            $outputCallback("  Balance: {$usdtBalance} USDT - Proceeding with sweep...", 'comment');
        }

        // Check TRX balance for gas
        $nodeUrl = config('services.tron.node_url', env('TRON_NODE_URL', 'https://api.trongrid.io'));
        if ($outputCallback) {
            $network = str_contains($nodeUrl, 'shasta') ? 'Shasta Testnet' : (str_contains($nodeUrl, 'trongrid.io') ? 'Mainnet' : 'Unknown');
            $outputCallback("  Checking TRX balance on {$network} ({$nodeUrl})...", 'comment');
        }
        $trxBalance = $this->nodeClient->getTrxBalance($address);
        
        if ($trxBalance < $this->minTrxBalance) {
            if ($outputCallback) {
                $outputCallback("  TRX balance: {$trxBalance} TRX (insufficient, need {$this->minTrxBalance} TRX)", 'comment');
                $outputCallback("  Sending TRX from gas bank...", 'comment');
            }
            
            Log::info('TronSweepService: Insufficient TRX for gas, sending from gas bank', [
                'user_id' => $wallet->user_id,
                'address' => $address,
                'current_trx' => $trxBalance,
                'required_trx' => $this->minTrxBalance,
            ]);
            
            // Send TRX from gas bank (send enough to cover gas fee ~5.x TRX per transaction)
            // Get TXID for confirmation checking
            $txid = $this->sendTrxFromGasBank($address, $this->gasBankTrxAmount, $outputCallback);
            $trxSent = !empty($txid);
            
            if (!$trxSent) {
                throw new \Exception(
                    "Cannot sweep address: insufficient TRX for gas and gas bank is not configured or failed. " .
                    "Please configure TRON_GAS_BANK_PRIVATE_KEY in .env file or manually send TRX to address: {$address}"
                );
            }
            
            // Wait for transaction to confirm before checking balance
            if ($outputCallback) {
                $outputCallback("  Waiting for TRX transaction to confirm (TXID: {$txid})...", 'comment');
            }
            
            // Wait for transaction to confirm (polling with timeout)
            $maxWaitTime = 30; // Maximum wait time in seconds
            $waitInterval = 2; // Check every 2 seconds
            $waited = 0;
            $confirmed = false;
            
            // Poll for transaction confirmation
            while ($waited < $maxWaitTime && !$confirmed) {
                $confirmations = $this->nodeClient->getConfirmations($txid);
                
                if ($confirmations > 0) {
                    $confirmed = true;
                    if ($outputCallback) {
                        $outputCallback("  ✓ Transaction confirmed ({$confirmations} confirmations)", 'info');
                    }
                    break;
                }
                
                sleep($waitInterval);
                $waited += $waitInterval;
                
                if ($outputCallback && $waited % 6 == 0) {
                    $outputCallback("  Still waiting... ({$waited}s elapsed)", 'comment');
                }
            }
            
            if (!$confirmed) {
                if ($outputCallback) {
                    $outputCallback("  ⚠ Transaction not confirmed after {$maxWaitTime}s, checking balance anyway...", 'warn');
                }
            }
            
            // Re-check TRX balance after sending (with retries)
            $trxBalance = 0;
            $maxRetries = 5;
            $retryCount = 0;
            
            while ($retryCount < $maxRetries) {
                $trxBalance = $this->nodeClient->getTrxBalance($address);
                
                if ($outputCallback) {
                    $nodeUrl = config('services.tron.node_url', env('TRON_NODE_URL', 'https://api.trongrid.io'));
                    $network = str_contains($nodeUrl, 'shasta') ? 'Shasta Testnet' : (str_contains($nodeUrl, 'trongrid.io') ? 'Mainnet' : 'Unknown');
                    $outputCallback("  Balance check on {$network}: {$trxBalance} TRX", 'comment');
                }
                
                if ($trxBalance >= $this->minTrxBalance) {
                    break; // Balance is sufficient
                }
                
                $retryCount++;
                if ($retryCount < $maxRetries) {
                    if ($outputCallback) {
                        $outputCallback("  Waiting... (attempt {$retryCount}/{$maxRetries})", 'comment');
                    }
                    sleep(3); // Wait 3 more seconds before retry
                }
            }
            
            if ($trxBalance < $this->minTrxBalance) {
                throw new \Exception(
                    "Insufficient TRX after gas bank transfer. Current: {$trxBalance} TRX, Required: {$this->minTrxBalance} TRX. " .
                    "Address: {$address}. Transaction TXID: {$txid}. Transaction may still be confirming, please wait and try again."
                );
            }
            
            if ($outputCallback) {
                $outputCallback("  ✓ TRX balance updated: {$trxBalance} TRX", 'info');
            }
        } else {
            if ($outputCallback) {
                $outputCallback("  TRX balance: {$trxBalance} TRX (sufficient)", 'comment');
            }
        }

        // Decrypt private key
        if ($outputCallback) {
            $outputCallback("  Transferring {$usdtBalance} USDT to hot wallet...", 'comment');
        }
        
        $privateKey = $this->walletService->decryptPrivateKey($wallet->encrypted_private_key);

        // Transfer USDT to hot wallet
        $txid = $this->usdtContract->transferFromPrivateKey(
            $privateKey,
            $this->hotWalletAddress,
            $usdtBalance
        );

        if (!$txid) {
            // Check TRX balance again to provide better error message
            $finalTrxBalance = $this->nodeClient->getTrxBalance($address);
            $errorMsg = "Failed to broadcast sweep transaction";
            if ($finalTrxBalance < $this->minTrxBalance) {
                $errorMsg .= ". Insufficient TRX for gas: {$finalTrxBalance} TRX (required: {$this->minTrxBalance} TRX)";
            }
            throw new \Exception($errorMsg);
        }

        // Record sweep
        $sweep = TronSweep::create([
            'user_id' => $wallet->user_id,
            'from_address' => $address,
            'to_address' => $this->hotWalletAddress,
            'txid' => $txid,
            'amount' => $usdtBalance,
            'status' => 'broadcasted',
        ]);

        Log::info('TronSweepService: Successfully swept address', [
            'user_id' => $wallet->user_id,
            'from_address' => $address,
            'to_address' => $this->hotWalletAddress,
            'amount' => $usdtBalance,
            'txid' => $txid,
            'sweep_id' => $sweep->id,
        ]);
        
        if ($outputCallback) {
            $outputCallback("  ✓ Transfer successful! TXID: {$txid}", 'info');
        }

        return true;
    }

    /**
     * Send TRX from gas bank to address.
     * 
     * @param string $toAddress
     * @param float $amount
     * @param callable|null $outputCallback Optional callback for console output
     * @return string|null TXID if TRX was sent successfully, null otherwise
     */
    private function sendTrxFromGasBank(string $toAddress, float $amount, ?callable $outputCallback = null): ?string
    {
        $gasBankPrivateKey = config('services.tron.gas_bank_private_key', env('TRON_GAS_BANK_PRIVATE_KEY', ''));
        
            if (empty($gasBankPrivateKey)) {
                $message = "Gas bank private key not configured";
                Log::warning('TronSweepService: ' . $message, [
                    'to_address' => $toAddress,
                    'required_amount' => $amount,
                ]);
                if ($outputCallback) {
                    $outputCallback("  ✗ {$message}", 'error');
                }
                return null;
            }

        try {
            Log::info('TronSweepService: Calling transferTrx', [
                'to_address' => $toAddress,
                'amount' => $amount,
                'gas_bank_key_length' => strlen($gasBankPrivateKey),
            ]);
            
            if ($outputCallback) {
                $nodeUrl = config('services.tron.node_url', env('TRON_NODE_URL', 'https://api.trongrid.io'));
                $network = str_contains($nodeUrl, 'shasta') ? 'Shasta Testnet' : (str_contains($nodeUrl, 'trongrid.io') ? 'Mainnet' : 'Unknown');
                $outputCallback("  Sending {$amount} TRX from gas bank on {$network}...", 'comment');
            }
            
            $transactionService = app(TronTransactionService::class);
            $txid = $transactionService->transferTrx($gasBankPrivateKey, $toAddress, $amount);
            
            Log::info('TronSweepService: transferTrx returned', [
                'to_address' => $toAddress,
                'txid' => $txid,
                'has_txid' => !empty($txid),
            ]);
            
            if ($txid) {
                Log::info('TronSweepService: Sent TRX from gas bank', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'txid' => $txid,
                ]);
                if ($outputCallback) {
                    $outputCallback("  ✓ TRX sent! TXID: {$txid}", 'info');
                }
                return $txid; // Return TXID instead of true
            } else {
                // Get detailed error from logs or try to get more info
                $message = "Failed to send TRX (transferTrx returned null)";
                $detailedMessage = "  ✗ {$message}";
                $detailedMessage .= "\n    → Check logs for details: storage/logs/laravel.log";
                $detailedMessage .= "\n    → Look for 'TronTransactionService' entries";
                $detailedMessage .= "\n    → Possible causes:";
                $detailedMessage .= "\n      - SDK initialization failed";
                $detailedMessage .= "\n      - Transaction creation failed";
                $detailedMessage .= "\n      - Broadcast failed";
                $detailedMessage .= "\n      - Invalid response format";
                
                Log::error('TronSweepService: Failed to send TRX from gas bank (transferTrx returned null)', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'note' => 'Check TronTransactionService logs for detailed error information',
                ]);
                if ($outputCallback) {
                    $outputCallback($detailedMessage, 'error');
                }
                return false;
            }
        } catch (\Exception $e) {
            $message = "Exception sending TRX: {$e->getMessage()}";
            Log::error('TronSweepService: Exception sending TRX from gas bank', [
                'to_address' => $toAddress,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            if ($outputCallback) {
                $outputCallback("  ✗ {$message}", 'error');
            }
            return false;
        }
    }
}

