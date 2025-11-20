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
            return 0;
        }

        // Get recent transfer events (last 1 hour)
        $minTimestamp = (time() - 3600) * 1000; // milliseconds
        $events = $this->nodeClient->getUsdtTransferEvents($minTimestamp);

        foreach ($events as $event) {
            $to = $event['to'];
            $from = $event['from'];
            $amount = $event['amount'];
            $txid = $event['txid'];

            // Check if this is one of our deposit addresses
            $wallet = UserTronWallet::where('tron_address', $to)->first();
            
            if (!$wallet) {
                continue;
            }

            // Check if deposit already exists
            $exists = TronDeposit::where('txid', $txid)
                ->where('tron_address', $to)
                ->exists();

            if ($exists) {
                continue;
            }

            // Create new deposit record
            TronDeposit::create([
                'user_id' => $wallet->user_id,
                'tron_address' => $to,
                'txid' => $txid,
                'from_address' => $from,
                'amount' => $amount,
                'token_symbol' => 'USDT',
                'confirmations' => 0,
                'required_confirmations' => $this->requiredConfirmations,
                'status' => 'pending',
            ]);

            $count++;
        }

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

                // If confirmations reached threshold and status is pending
                if ($deposit->status === 'pending' && $confirmations >= $deposit->required_confirmations) {
                    // Mark as confirmed
                    $deposit->update(['status' => 'confirmed']);

                    // Credit to user account
                    $this->creditUserBalance($deposit);

                    // Mark as credited
                    $deposit->update(['status' => 'credited']);
                    $credited++;
                }
            } catch (\Exception $e) {
                Log::error('TronDepositService: Error updating deposit', [
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

