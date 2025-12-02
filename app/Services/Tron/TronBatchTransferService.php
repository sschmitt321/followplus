<?php

namespace App\Services\Tron;

use App\Models\AddressLiquidity;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tron Batch Transfer Service
 * 
 * Handles USDT batch transfers from addresses to main wallet.
 */
class TronBatchTransferService
{
    private TronTransactionService $transactionService;
    private TronWalletService $walletService;
    
    private float $minTrx;
    private float $minUsdt;
    private string $mainUsdtWallet;
    private string $usdtContract;

    public function __construct(
        TronTransactionService $transactionService,
        TronWalletService $walletService
    ) {
        $this->transactionService = $transactionService;
        $this->walletService = $walletService;
        
        // Load configuration
        $this->minTrx = (float) config('services.tron.batch_transfer.min_trx', env('TRON_BATCH_MIN_TRX', 6));
        $this->minUsdt = (float) config('services.tron.batch_transfer.min_usdt', env('TRON_BATCH_MIN_USDT', 50));
        $this->mainUsdtWallet = config('services.tron.batch_transfer.main_usdt_wallet', env('TRON_BATCH_MAIN_USDT_WALLET', ''));
        $this->usdtContract = config('services.tron.usdt_contract', env('TRON_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'));
    }

    /**
     * Process USDT transfers for ready addresses.
     * 
     * @param int|null $limit Optional limit for batch processing
     * @param callable|null $outputCallback Optional callback for console output: function(string $message, string $type = 'info')
     * @return array Statistics: ['processed' => int, 'transferred' => int, 'failed' => int]
     */
    public function processTransfers(?int $limit = null, ?callable $outputCallback = null): array
    {
        $query = AddressLiquidity::where('status', AddressLiquidity::STATUS_READY_TO_TRANSFER)
            ->where('usdt_balance', '>=', $this->minUsdt);

        if ($limit) {
            $query->limit($limit);
        }

        $addresses = $query->get();
        $totalAddresses = $addresses->count();

        $stats = [
            'processed' => 0,
            'transferred' => 0,
            'failed' => 0,
        ];

        if ($outputCallback) {
            $outputCallback("Found {$totalAddresses} address(es) ready for USDT transfer", 'info');
            $outputCallback("Main USDT wallet: {$this->mainUsdtWallet}", 'comment');
            $outputCallback("USDT contract: {$this->usdtContract}", 'comment');
            $outputCallback("", 'comment'); // Empty line
        }

        Log::info('TronBatchTransferService: Starting USDT transfers', [
            'address_count' => $totalAddresses,
            'main_usdt_wallet' => $this->mainUsdtWallet,
        ]);

        foreach ($addresses as $index => $address) {
            $progress = "[" . ($index + 1) . "/{$totalAddresses}]";
            
            try {
                $stats['processed']++;

                // Convert Decimal to float for comparison and display
                $usdtBalanceFloat = $address->usdt_balance instanceof \App\Support\Decimal 
                    ? $address->usdt_balance->toFloat() 
                    : (float) $address->usdt_balance;
                $trxBalanceFloat = $address->trx_balance instanceof \App\Support\Decimal 
                    ? $address->trx_balance->toFloat() 
                    : (float) $address->trx_balance;

                if ($outputCallback) {
                    $outputCallback("{$progress} Processing: {$address->address}", 'comment');
                    $outputCallback("  Current status: {$address->status}", 'comment');
                    $outputCallback("  TRX balance: {$trxBalanceFloat}", 'comment');
                    $outputCallback("  USDT balance: {$usdtBalanceFloat}", 'comment');
                }

                // Double-check balance before transfer
                if ($usdtBalanceFloat < $this->minUsdt) {
                    if ($outputCallback) {
                        $outputCallback("  ⊘ Skipped: USDT balance ({$usdtBalanceFloat}) below threshold ({$this->minUsdt})", 'warn');
                    }
                    Log::warning('TronBatchTransferService: Address USDT balance too low', [
                        'address' => $address->address,
                        'usdt_balance' => $usdtBalanceFloat,
                        'min_usdt' => $this->minUsdt,
                    ]);
                    continue;
                }

                // Mark as TRANSFER_SENT to prevent concurrent processing
                if ($outputCallback) {
                    $outputCallback("  Marking as TRANSFER_SENT...", 'comment');
                }
                $address->update([
                    'status' => AddressLiquidity::STATUS_TRANSFER_SENT,
                ]);

                // Get private key for this address
                if ($outputCallback) {
                    $outputCallback("  Getting private key for address...", 'comment');
                }
                $privateKey = $this->getPrivateKeyForAddress($address->address);
                
                if (!$privateKey) {
                    throw new \Exception("Private key not found for address: {$address->address}");
                }

                // Calculate transfer amount (transfer all USDT balance)
                $transferAmount = $usdtBalanceFloat;

                if ($outputCallback) {
                    $outputCallback("  Transferring {$transferAmount} USDT to {$this->mainUsdtWallet}...", 'comment');
                }

                // Execute USDT transfer
                $txHash = $this->transactionService->transferTrc20(
                    $privateKey,
                    $this->mainUsdtWallet,
                    $transferAmount,
                    $this->usdtContract
                );

                if (!$txHash) {
                    throw new \Exception("Failed to transfer USDT: transaction returned null");
                }

                // Update status to DONE
                $address->update([
                    'status' => AddressLiquidity::STATUS_DONE,
                    'last_tx_hash' => $txHash,
                    'error_code' => null,
                    'error_message' => null,
                ]);

                $stats['transferred']++;

                if ($outputCallback) {
                    $outputCallback("  ✓ USDT transfer successful! TX Hash: {$txHash}", 'info');
                    $outputCallback("  Status updated: TRANSFER_SENT → DONE", 'comment');
                }

                Log::info('TronBatchTransferService: USDT transfer successful', [
                    'address' => $address->address,
                    'amount' => $transferAmount,
                    'tx_hash' => $txHash,
                ]);
            } catch (\Exception $e) {
                $stats['failed']++;

                // Update status to FAILED
                $address->update([
                    'status' => AddressLiquidity::STATUS_FAILED,
                    'error_code' => 'TRANSFER_ERROR',
                    'error_message' => $e->getMessage(),
                ]);

                if ($outputCallback) {
                    $outputCallback("  ✗ Error: {$e->getMessage()}", 'error');
                }

                Log::error('TronBatchTransferService: USDT transfer failed', [
                    'address' => $address->address,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
            
            if ($outputCallback && $index < $totalAddresses - 1) {
                $outputCallback("", 'comment'); // Empty line between addresses
            }
        }

        Log::info('TronBatchTransferService: USDT transfers completed', $stats);

        return $stats;
    }

    /**
     * Get private key for an address.
     * 
     * This method needs to be implemented based on your wallet management system.
     * For now, it tries to get from UserTronWallet table.
     * 
     * @param string $address TRON address
     * @return string|null Private key (hex format) or null if not found
     */
    private function getPrivateKeyForAddress(string $address): ?string
    {
        try {
            // Try to get from UserTronWallet
            $wallet = \App\Models\UserTronWallet::where('tron_address', $address)->first();
            
            if ($wallet && $wallet->encrypted_private_key) {
                return $this->walletService->decryptPrivateKey($wallet->encrypted_private_key);
            }

            // If not found in UserTronWallet, you may need to implement other lookup methods
            // For example, from a dedicated wallet management table
            Log::warning('TronBatchTransferService: Private key not found for address', [
                'address' => $address,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('TronBatchTransferService: Error getting private key', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

