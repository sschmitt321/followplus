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

    public function __construct(
        private TronWalletService $walletService,
        private TronNodeClient $nodeClient,
        private TronUsdtContract $usdtContract
    ) {
        $this->minSweepAmount = (float) config('services.tron.min_sweep_amount', env('TRON_MIN_SWEEP_AMOUNT', 50.0));
        $this->hotWalletAddress = config('services.tron.hot_wallet_address', env('TRON_HOT_WALLET_ADDRESS', ''));
        $this->minTrxBalance = (float) config('services.tron.min_trx_balance', env('TRON_MIN_TRX_BALANCE', 1.0));
    }

    /**
     * Sweep all eligible addresses.
     */
    public function sweepAll(): int
    {
        if (empty($this->hotWalletAddress)) {
            Log::warning('TronSweepService: Hot wallet address not configured');
            return 0;
        }

        $swept = 0;
        $wallets = UserTronWallet::all();

        foreach ($wallets as $wallet) {
            try {
                $this->sweepAddress($wallet);
                $swept++;
            } catch (\Exception $e) {
                Log::error('TronSweepService: Error sweeping address', [
                    'user_id' => $wallet->user_id,
                    'address' => $wallet->tron_address,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $swept;
    }

    /**
     * Sweep a single address.
     */
    private function sweepAddress(UserTronWallet $wallet): void
    {
        $address = $wallet->tron_address;

        // Check USDT balance
        $usdtBalance = $this->usdtContract->getBalance($address);
        
        if ($usdtBalance < $this->minSweepAmount) {
            return; // Skip if balance is below threshold
        }

        // Check TRX balance for gas
        $trxBalance = $this->nodeClient->getTrxBalance($address);
        
        if ($trxBalance < $this->minTrxBalance) {
            // Send TRX from gas bank
            $this->sendTrxFromGasBank($address, $this->minTrxBalance * 2);
            
            // Wait a bit for transaction to confirm
            sleep(3);
        }

        // Decrypt private key
        $privateKey = $this->walletService->decryptPrivateKey($wallet->encrypted_private_key);

        // Transfer USDT to hot wallet
        $txid = $this->usdtContract->transferFromPrivateKey(
            $privateKey,
            $this->hotWalletAddress,
            $usdtBalance
        );

        if (!$txid) {
            throw new \Exception('Failed to broadcast sweep transaction');
        }

        // Record sweep
        TronSweep::create([
            'user_id' => $wallet->user_id,
            'from_address' => $address,
            'to_address' => $this->hotWalletAddress,
            'txid' => $txid,
            'amount' => $usdtBalance,
            'status' => 'broadcasted',
        ]);
    }

    /**
     * Send TRX from gas bank to address.
     */
    private function sendTrxFromGasBank(string $toAddress, float $amount): void
    {
        $gasBankPrivateKey = config('services.tron.gas_bank_private_key', env('TRON_GAS_BANK_PRIVATE_KEY', ''));
        
        if (empty($gasBankPrivateKey)) {
            Log::warning('TronSweepService: Gas bank private key not configured');
            return;
        }

        try {
            $transactionService = app(TronTransactionService::class);
            $txid = $transactionService->transferTrx($gasBankPrivateKey, $toAddress, $amount);
            
            if ($txid) {
                Log::info('TronSweepService: Sent TRX from gas bank', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'txid' => $txid,
                ]);
            } else {
                Log::error('TronSweepService: Failed to send TRX from gas bank', [
                    'to_address' => $toAddress,
                    'amount' => $amount,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('TronSweepService: Exception sending TRX from gas bank', [
                'to_address' => $toAddress,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

