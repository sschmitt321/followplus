<?php

namespace App\Services\Tron;

use App\Models\TronDeposit;
use App\Models\UserTronWallet;
use App\Services\Deposit\DepositService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TronDepositService
{
    private int $requiredConfirmations;

    public function __construct(
        private TronNodeClient $nodeClient,
        private DepositService $depositService
    ) {
        $this->requiredConfirmations = (int) config('services.tron.required_confirmations', env('TRON_REQUIRED_CONFIRMATIONS', 20));
    }

    /**
     * Scan for new deposits.
     */
    public function scanNewDeposits(): int
    {
        $count = 0;
        
        // Get all user deposit addresses
        $wallets = UserTronWallet::all();
        $addresses = $wallets->pluck('tron_address')->toArray();

        if (empty($addresses)) {
            Log::channel('tron-deposits')->info('TronDepositService: No user wallets found');
            return 0;
        }

        Log::channel('tron-deposits')->info('TronDepositService: Starting deposit scan', [
            'address_count' => count($addresses),
            'addresses' => array_slice($addresses, 0, 5), // Log first 5 addresses
        ]);

        // Use account transactions API for each address to avoid missing transactions
        // due to event API limit (200 events)
        $processedCount = 0;
        $skippedAlreadyExists = 0;
        $scanHours = (int) config('services.tron.scan_hours', env('TRON_SCAN_HOURS', 24));
        $minTimestamp = (time() - ($scanHours * 3600)) * 1000; // milliseconds
        
        Log::channel('tron-deposits')->info('TronDepositService: Scanning deposits for each address', [
            'scan_hours' => $scanHours,
            'min_timestamp' => date('Y-m-d H:i:s', $minTimestamp / 1000),
        ]);

        foreach ($addresses as $address) {
            try {
                $startTime = microtime(true);
                $transactions = $this->nodeClient->getAccountTrc20Transactions($address, $minTimestamp);
                $fetchDuration = round((microtime(true) - $startTime) * 1000, 2);
                
                Log::channel('tron-deposits')->info('TronDepositService: Fetched transactions for address', [
                    'address' => $address,
                    'transaction_count' => count($transactions),
                    'fetch_duration_ms' => $fetchDuration,
                ]);

                foreach ($transactions as $tx) {
                    $txid = $tx['txid'];
                    $from = $tx['from'];
                    $amount = $tx['amount'];
                    $tokenSymbol = $tx['token_symbol'] ?? 'USDT';

                    // Only process USDT deposits
                    if ($tokenSymbol !== 'USDT') {
                        continue;
                    }

                    // Check if deposit already exists
                    $exists = TronDeposit::where('txid', $txid)
                        ->where('tron_address', $address)
                        ->exists();

                    if ($exists) {
                        $skippedAlreadyExists++;
                        Log::channel('tron-deposits')->debug('TronDepositService: Skipping transaction - deposit already exists', [
                            'txid' => $txid,
                            'address' => $address,
                        ]);
                        continue;
                    }

                    // Get wallet for this address
                    $wallet = UserTronWallet::where('tron_address', $address)->first();
                    if (!$wallet) {
                        Log::channel('tron-deposits')->warning('TronDepositService: Wallet not found for address', [
                            'address' => $address,
                        ]);
                        continue;
                    }

                    // Create new deposit record using updateOrCreate to prevent duplicates
                    // The unique constraint on (txid, tron_address) will prevent duplicates
                    try {
                        $deposit = TronDeposit::updateOrCreate(
                            [
                                'txid' => $txid,
                                'tron_address' => $address,
                            ],
                            [
                                'user_id' => $wallet->user_id,
                                'from_address' => $from,
                                'amount' => $amount,
                                'token_symbol' => $tokenSymbol,
                                'confirmations' => 0,
                                'required_confirmations' => $this->requiredConfirmations,
                                'status' => 'pending',
                            ]
                        );

                        // Only count as new if it was just created
                        if ($deposit->wasRecentlyCreated) {
                            $count++;
                            $processedCount++;
                            
                            Log::channel('tron-deposits')->info('TronDepositService: Created new deposit', [
                                'user_id' => $wallet->user_id,
                                'txid' => $txid,
                                'amount' => $amount,
                                'to' => $address,
                                'from' => $from,
                                'token_symbol' => $tokenSymbol,
                            ]);
                        } else {
                            $skippedAlreadyExists++;
                            Log::channel('tron-deposits')->debug('TronDepositService: Deposit already exists (updateOrCreate)', [
                                'txid' => $txid,
                                'address' => $address,
                            ]);
                        }
                    } catch (\Illuminate\Database\QueryException $e) {
                        // Handle unique constraint violation (shouldn't happen with updateOrCreate, but just in case)
                        if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                            $skippedAlreadyExists++;
                            Log::channel('tron-deposits')->debug('TronDepositService: Duplicate deposit detected (unique constraint)', [
                                'txid' => $txid,
                                'address' => $address,
                            ]);
                        } else {
                            Log::channel('tron-deposits')->error('TronDepositService: Failed to create deposit', [
                                'txid' => $txid,
                                'address' => $address,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::channel('tron-deposits')->error('TronDepositService: Failed to create deposit', [
                            'txid' => $txid,
                            'address' => $address,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::channel('tron-deposits')->error('TronDepositService: Failed to get transactions for address', [
                    'address' => $address,
                    'error' => $e->getMessage(),
                ]);
                // Continue with next address
                continue;
            }
        }

        Log::channel('tron-deposits')->info('TronDepositService: Scan completed', [
            'new_deposits' => $count,
            'processed' => $processedCount,
            'skipped_already_exists' => $skippedAlreadyExists,
        ]);

        return $count;
    }

    /**
     * Update confirmations and credit confirmed deposits.
     */
    public function updateConfirmationsAndCredit(): int
    {
        $credited = 0;

        // Get pending or confirmed deposits
        $deposits = TronDeposit::whereIn('status', ['pending', 'confirmed'])
            ->get();

        foreach ($deposits as $deposit) {
            try {
                // Get current confirmations
                $confirmations = $this->nodeClient->getConfirmations($deposit->txid);
                
                $deposit->update(['confirmations' => $confirmations]);

                // If confirmations reached threshold and status is pending or confirmed (but not yet credited)
                if (in_array($deposit->status, ['pending', 'confirmed']) && $confirmations >= $deposit->required_confirmations) {
                    // If status is pending, mark as confirmed first
                    if ($deposit->status === 'pending') {
                        $deposit->update(['status' => 'confirmed']);
                    }

                    // Credit to user account (only if not already credited)
                    if ($deposit->status !== 'credited') {
                        $this->creditUserBalance($deposit);

                        // Mark as credited
                        $deposit->update(['status' => 'credited']);
                    }
                    
                    // Log credited deposit
                    Log::channel('tron-deposits')->info('TronDepositService: Deposit credited', [
                        'user_id' => $deposit->user_id,
                        'txid' => $deposit->txid,
                        'amount' => $deposit->amount,
                        'tron_address' => $deposit->tron_address,
                        'confirmations' => $confirmations,
                    ]);
                    
                    $credited++;
                }
            } catch (\Exception $e) {
                Log::channel('tron-deposits')->error('TronDepositService: Error updating deposit', [
                    'deposit_id' => $deposit->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $credited;
    }

    /**
     * Credit user balance from Tron deposit.
     */
    private function creditUserBalance(TronDeposit $tronDeposit): void
    {
        DB::transaction(function () use ($tronDeposit) {
            // Create deposit record in main deposits table
            $deposit = $this->depositService->create(
                $tronDeposit->user_id,
                'USDT',
                $tronDeposit->amount,
                'TRC20',
                $tronDeposit->tron_address
            );

            // Update with txid
            $deposit->update(['txid' => $tronDeposit->txid]);

            // Confirm deposit (this will credit the account)
            $this->depositService->confirm($deposit->id, $tronDeposit->txid);
        });
    }
}

