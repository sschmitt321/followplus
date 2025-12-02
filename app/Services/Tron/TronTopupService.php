<?php

namespace App\Services\Tron;

use App\Models\AddressLiquidity;
use Illuminate\Support\Facades\Log;

/**
 * Tron Topup Service
 * 
 * Handles TRX topup for addresses that need gas.
 */
class TronTopupService
{
    private TronTransactionService $transactionService;
    
    private float $minTrx;
    private float $minUsdt;
    private float $topupAmount;
    private string $mainTrxWallet;

    public function __construct(
        TronTransactionService $transactionService
    ) {
        $this->transactionService = $transactionService;
        
        // Load configuration
        $this->minTrx = (float) config('services.tron.batch_transfer.min_trx', env('TRON_BATCH_MIN_TRX', 6));
        $this->minUsdt = (float) config('services.tron.batch_transfer.min_usdt', env('TRON_BATCH_MIN_USDT', 50));
        $this->topupAmount = (float) config('services.tron.batch_transfer.trx_topup_amount', env('TRON_BATCH_TRX_TOPUP_AMOUNT', 8));
        $this->mainTrxWallet = config('services.tron.batch_transfer.main_trx_wallet', env('TRON_BATCH_MAIN_TRX_WALLET', ''));
    }

    /**
     * Process TRX topups for addresses that need gas.
     * 
     * @param int|null $limit Optional limit for batch processing
     * @param callable|null $outputCallback Optional callback for console output: function(string $message, string $type = 'info')
     * @return array Statistics: ['processed' => int, 'topped_up' => int, 'failed' => int]
     */
    public function processTopups(?int $limit = null, ?callable $outputCallback = null): array
    {
        $query = AddressLiquidity::where('status', AddressLiquidity::STATUS_NEED_TRX_TOPUP)
            ->where('usdt_balance', '>=', $this->minUsdt);

        if ($limit) {
            $query->limit($limit);
        }

        $addresses = $query->get();
        $totalAddresses = $addresses->count();

        $stats = [
            'processed' => 0,
            'topped_up' => 0,
            'failed' => 0,
        ];

        if ($outputCallback) {
            $outputCallback("Found {$totalAddresses} address(es) that need TRX topup", 'info');
            $outputCallback("Topup amount: {$this->topupAmount} TRX", 'comment');
            $outputCallback("Main TRX wallet: {$this->mainTrxWallet}", 'comment');
            $outputCallback("", 'comment'); // Empty line
        }

        Log::info('TronTopupService: Starting TRX topups', [
            'address_count' => $totalAddresses,
            'topup_amount' => $this->topupAmount,
            'main_trx_wallet' => $this->mainTrxWallet,
        ]);

        foreach ($addresses as $index => $address) {
            $progress = "[" . ($index + 1) . "/{$totalAddresses}]";
            
            try {
                $stats['processed']++;

                // Convert Decimal to float for comparison
                $usdtBalanceFloat = $address->usdt_balance instanceof \App\Support\Decimal 
                    ? $address->usdt_balance->toFloat() 
                    : (float) $address->usdt_balance;

                if ($outputCallback) {
                    $outputCallback("{$progress} Processing: {$address->address}", 'comment');
                    $outputCallback("  Current status: {$address->status}", 'comment');
                    $outputCallback("  USDT balance: {$usdtBalanceFloat}", 'comment');
                }

                // Double-check conditions
                if ($usdtBalanceFloat < $this->minUsdt) {
                    if ($outputCallback) {
                        $outputCallback("  ⊘ Skipped: USDT balance ({$usdtBalanceFloat}) below threshold ({$this->minUsdt})", 'warn');
                    }
                    Log::warning('TronTopupService: Address USDT balance too low', [
                        'address' => $address->address,
                        'usdt_balance' => $usdtBalanceFloat,
                        'min_usdt' => $this->minUsdt,
                    ]);
                    continue;
                }

                // Get private key for main TRX wallet
                if ($outputCallback) {
                    $outputCallback("  Getting main TRX wallet private key...", 'comment');
                }
                $privateKey = $this->getMainTrxWalletPrivateKey();
                
                if (!$privateKey) {
                    throw new \Exception("Main TRX wallet private key not configured. Please set TRON_BATCH_MAIN_TRX_WALLET_PRIVATE_KEY or TRON_GAS_BANK_PRIVATE_KEY in .env file.");
                }
                
                // Validate private key format (should be hex string, not plain text)
                if (strlen($privateKey) < 64 || !ctype_xdigit($privateKey)) {
                    throw new \Exception("Invalid private key format. Private key should be a 64-character hexadecimal string. Current length: " . strlen($privateKey));
                }

                if ($outputCallback) {
                    $outputCallback("  Sending {$this->topupAmount} TRX to {$address->address}...", 'comment');
                }

                // Execute TRX transfer
                try {
                    $txHash = $this->transactionService->transferTrx(
                        $privateKey,
                        $address->address,
                        $this->topupAmount
                    );

                    if (!$txHash) {
                        // Check if there's a more detailed error in logs
                        throw new \Exception("Failed to transfer TRX: transaction returned null. Check logs for detailed error information.");
                    }
                } catch (\Exception $transferException) {
                    // Re-throw with more context
                    throw new \Exception("TRX transfer failed: {$transferException->getMessage()}", 0, $transferException);
                }

                // Update status to TRX_TOPPED_UP (will be re-scanned in next scan cycle)
                $address->update([
                    'status' => AddressLiquidity::STATUS_TRX_TOPPED_UP,
                    'last_topup_hash' => $txHash,
                    'error_code' => null,
                    'error_message' => null,
                ]);

                $stats['topped_up']++;

                if ($outputCallback) {
                    $outputCallback("  ✓ TRX topup successful! TX Hash: {$txHash}", 'info');
                    $outputCallback("  Status updated: NEED_TRX_TOPUP → TRX_TOPPED_UP", 'comment');
                }

                Log::info('TronTopupService: TRX topup successful', [
                    'address' => $address->address,
                    'amount' => $this->topupAmount,
                    'tx_hash' => $txHash,
                ]);
            } catch (\Exception $e) {
                $stats['failed']++;

                // Get detailed error message
                $errorMessage = $e->getMessage();
                $previousException = $e->getPrevious();
                if ($previousException) {
                    $errorMessage .= " (Original: {$previousException->getMessage()})";
                }

                // Keep status as NEED_TRX_TOPUP but record error
                $address->update([
                    'error_code' => 'TOPUP_ERROR',
                    'error_message' => $errorMessage,
                ]);

                if ($outputCallback) {
                    $outputCallback("  ✗ Error: {$errorMessage}", 'error');
                    // Show helpful hints for common errors
                    if (str_contains($errorMessage, 'private key') || str_contains($errorMessage, 'Private key')) {
                        $outputCallback("  💡 Hint: Check TRON_BATCH_MAIN_TRX_WALLET_PRIVATE_KEY or TRON_GAS_BANK_PRIVATE_KEY in .env file", 'comment');
                        $outputCallback("  💡 Private key should be a 64-character hexadecimal string (without 0x prefix)", 'comment');
                    }
                }

                Log::error('TronTopupService: TRX topup failed', [
                    'address' => $address->address,
                    'error' => $errorMessage,
                    'exception_class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
            
            if ($outputCallback && $index < $totalAddresses - 1) {
                $outputCallback("", 'comment'); // Empty line between addresses
            }
        }

        Log::info('TronTopupService: TRX topups completed', $stats);

        return $stats;
    }

    /**
     * Get private key for main TRX wallet.
     * 
     * @return string|null Private key (hex format) or null if not configured
     */
    private function getMainTrxWalletPrivateKey(): ?string
    {
        // Try to get from config/env
        $privateKey = config('services.tron.batch_transfer.main_trx_wallet_private_key', env('TRON_BATCH_MAIN_TRX_WALLET_PRIVATE_KEY', ''));
        
        if (empty($privateKey)) {
            // Fallback: try gas bank private key
            $privateKey = config('services.tron.gas_bank_private_key', env('TRON_GAS_BANK_PRIVATE_KEY', ''));
        }

        return $privateKey ?: null;
    }
}

