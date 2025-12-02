<?php

namespace App\Services\Tron;

use App\Models\AddressLiquidity;
use App\Support\Decimal;
use Illuminate\Support\Facades\Log;

/**
 * Tron Liquidity Service
 * 
 * Handles balance scanning and status updates for addresses.
 */
class TronLiquidityService
{
    private TronNodeClient $nodeClient;
    private TronUsdtContract $usdtContract;
    
    private float $minTrx;
    private float $minUsdt;

    public function __construct(
        TronNodeClient $nodeClient,
        TronUsdtContract $usdtContract
    ) {
        $this->nodeClient = $nodeClient;
        $this->usdtContract = $usdtContract;
        
        // Load configuration
        $this->minTrx = (float) config('services.tron.batch_transfer.min_trx', env('TRON_BATCH_MIN_TRX', 6));
        $this->minUsdt = (float) config('services.tron.batch_transfer.min_usdt', env('TRON_BATCH_MIN_USDT', 50));
    }

    /**
     * Sync addresses from user_tron_wallets table.
     * Creates AddressLiquidity records for addresses that don't exist yet.
     * 
     * @param callable|null $outputCallback Optional callback for console output: function(string $message, string $type = 'info')
     * @return array Statistics: ['synced' => int, 'skipped' => int]
     */
    public function syncAddressesFromWallets(?callable $outputCallback = null): array
    {
        $wallets = \App\Models\UserTronWallet::all();
        $totalWallets = $wallets->count();
        
        $stats = [
            'synced' => 0,
            'skipped' => 0,
        ];

        if ($outputCallback) {
            $outputCallback("Found {$totalWallets} wallet(s) in user_tron_wallets table", 'comment');
        }

        foreach ($wallets as $index => $wallet) {
            $progress = "[" . ($index + 1) . "/{$totalWallets}]";
            
            // Check if address already exists in addresses_liquidity
            $exists = AddressLiquidity::where('address', $wallet->tron_address)->exists();
            
            if ($exists) {
                $stats['skipped']++;
                if ($outputCallback) {
                    $outputCallback("{$progress} ⊘ Skipped: {$wallet->tron_address} (already exists)", 'comment');
                }
                continue;
            }

            // Create new record with status NEW
            AddressLiquidity::create([
                'address' => $wallet->tron_address,
                'status' => AddressLiquidity::STATUS_NEW,
            ]);

            $stats['synced']++;
            if ($outputCallback) {
                $outputCallback("{$progress} ✓ Synced: {$wallet->tron_address}", 'info');
            }
        }

        Log::info('TronLiquidityService: Addresses synced from user_tron_wallets', $stats);

        return $stats;
    }

    /**
     * Scan balances for addresses and update their status.
     * 
     * @param array $statuses Statuses to scan (default: NEW, TRX_TOPPED_UP, NEED_TRX_TOPUP, SKIP_SMALL_BALANCE)
     * @param int|null $limit Optional limit for batch processing
     * @param bool $autoSync If true, automatically sync addresses from user_tron_wallets before scanning
     * @param callable|null $outputCallback Optional callback for console output: function(string $message, string $type = 'info')
     * @return array Statistics: ['scanned' => int, 'updated' => int, 'errors' => int]
     */
    public function scanBalances(array $statuses = null, ?int $limit = null, bool $autoSync = true, ?callable $outputCallback = null): array
    {
        // Auto-sync addresses from user_tron_wallets if enabled
        if ($autoSync) {
            $this->syncAddressesFromWallets($outputCallback);
        }

        if ($statuses === null) {
            $statuses = [
                AddressLiquidity::STATUS_NEW,
                AddressLiquidity::STATUS_TRX_TOPPED_UP,
                AddressLiquidity::STATUS_NEED_TRX_TOPUP,
                AddressLiquidity::STATUS_SKIP_SMALL_BALANCE,
                AddressLiquidity::STATUS_DONE,      // Re-scan DONE addresses in case new USDT arrives
                AddressLiquidity::STATUS_FAILED,     // Re-scan FAILED addresses to retry
            ];
        }

        $query = AddressLiquidity::whereIn('status', $statuses);
        
        if ($limit) {
            $query->limit($limit);
        }

        $addresses = $query->get();
        $totalAddresses = $addresses->count();
        
        $stats = [
            'scanned' => 0,
            'updated' => 0,
            'errors' => 0,
        ];

        if ($outputCallback) {
            $outputCallback("Found {$totalAddresses} address(es) to scan", 'info');
            $outputCallback("Statuses: " . implode(', ', $statuses), 'comment');
            $outputCallback("Thresholds: MIN_TRX={$this->minTrx}, MIN_USDT={$this->minUsdt}", 'comment');
            $outputCallback("", 'comment'); // Empty line
        }

        Log::info('TronLiquidityService: Starting balance scan', [
            'address_count' => $totalAddresses,
            'statuses' => $statuses,
            'min_trx' => $this->minTrx,
            'min_usdt' => $this->minUsdt,
        ]);

        foreach ($addresses as $index => $address) {
            $progress = "[" . ($index + 1) . "/{$totalAddresses}]";
            
            try {
                $stats['scanned']++;
                
                if ($outputCallback) {
                    $outputCallback("{$progress} Scanning: {$address->address}", 'comment');
                    $outputCallback("  Current status: {$address->status}", 'comment');
                }
                
                // Get balances from blockchain
                if ($outputCallback) {
                    $outputCallback("  Fetching TRX balance...", 'comment');
                }
                $trxBalance = $this->nodeClient->getTrxBalance($address->address);
                
                if ($outputCallback) {
                    $outputCallback("  Fetching USDT balance...", 'comment');
                }
                $usdtBalance = $this->usdtContract->getBalance($address->address);
                $dtBalance = 0.0; // TODO: Implement DT balance if needed

                if ($outputCallback) {
                    $outputCallback("  Balance: TRX={$trxBalance}, USDT={$usdtBalance}", 'comment');
                }

                // Determine status based on balances
                $oldStatus = $address->status;
                $newStatus = $this->determineStatus($trxBalance, $usdtBalance);
                $gasStrategy = $this->determineGasStrategy($trxBalance, $usdtBalance);

                // Update address record
                $address->update([
                    'trx_balance' => Decimal::of($trxBalance)->toFixed(8),
                    'usdt_balance' => Decimal::of($usdtBalance)->toFixed(8),
                    'dt_balance' => Decimal::of($dtBalance)->toFixed(8),
                    'status' => $newStatus,
                    'gas_strategy' => $gasStrategy,
                    'last_checked_at' => now(),
                    'error_code' => null,
                    'error_message' => null,
                ]);

                $stats['updated']++;

                if ($outputCallback) {
                    $statusChange = $oldStatus !== $newStatus ? " ({$oldStatus} → {$newStatus})" : " (no change)";
                    $outputCallback("  ✓ Status: {$newStatus}{$statusChange}", 'info');
                    if ($gasStrategy) {
                        $outputCallback("  Gas strategy: {$gasStrategy}", 'comment');
                    }
                }

                Log::debug('TronLiquidityService: Address scanned', [
                    'address' => $address->address,
                    'trx_balance' => $trxBalance,
                    'usdt_balance' => $usdtBalance,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ]);
            } catch (\Exception $e) {
                $stats['errors']++;
                
                // Update error information
                $address->update([
                    'error_code' => 'SCAN_ERROR',
                    'error_message' => $e->getMessage(),
                ]);

                if ($outputCallback) {
                    $outputCallback("  ✗ Error: {$e->getMessage()}", 'error');
                }

                Log::error('TronLiquidityService: Failed to scan address', [
                    'address' => $address->address,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
            
            if ($outputCallback && $index < $totalAddresses - 1) {
                $outputCallback("", 'comment'); // Empty line between addresses
            }
        }

        Log::info('TronLiquidityService: Balance scan completed', $stats);

        return $stats;
    }

    /**
     * Determine status based on balances.
     */
    private function determineStatus(float $trxBalance, float $usdtBalance): string
    {
        if ($usdtBalance >= $this->minUsdt && $trxBalance >= $this->minTrx) {
            return AddressLiquidity::STATUS_READY_TO_TRANSFER;
        } elseif ($usdtBalance >= $this->minUsdt && $trxBalance < $this->minTrx) {
            return AddressLiquidity::STATUS_NEED_TRX_TOPUP;
        } else {
            return AddressLiquidity::STATUS_SKIP_SMALL_BALANCE;
        }
    }

    /**
     * Determine gas strategy based on balances.
     */
    private function determineGasStrategy(float $trxBalance, float $usdtBalance): ?string
    {
        if ($usdtBalance >= $this->minUsdt && $trxBalance >= $this->minTrx) {
            return AddressLiquidity::GAS_STRATEGY_USE_TRX;
        } elseif ($usdtBalance >= $this->minUsdt && $trxBalance < $this->minTrx) {
            return AddressLiquidity::GAS_STRATEGY_NEED_TOPUP;
        }
        
        return null;
    }

    /**
     * Import addresses (create new records with status NEW).
     * 
     * Note: Normally addresses should be synced from user_tron_wallets table.
     * This method is kept for manual import of additional addresses if needed.
     * 
     * @param array $addresses Array of address strings
     * @return array Statistics: ['imported' => int, 'skipped' => int]
     */
    public function importAddresses(array $addresses): array
    {
        $stats = [
            'imported' => 0,
            'skipped' => 0,
        ];

        foreach ($addresses as $address) {
            $address = trim($address);
            
            if (empty($address)) {
                continue;
            }

            // Check if address already exists
            $exists = AddressLiquidity::where('address', $address)->exists();
            
            if ($exists) {
                $stats['skipped']++;
                continue;
            }

            // Create new record
            AddressLiquidity::create([
                'address' => $address,
                'status' => AddressLiquidity::STATUS_NEW,
            ]);

            $stats['imported']++;
        }

        Log::info('TronLiquidityService: Addresses imported', $stats);

        return $stats;
    }
}

