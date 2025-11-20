<?php

namespace App\Services\Tron;

use Illuminate\Support\Facades\Log;

class TronWithdrawalService
{
    private string $hotWalletAddress;
    private string $hotWalletPrivateKey;

    public function __construct(
        private TronUsdtContract $usdtContract
    ) {
        $this->hotWalletAddress = config('services.tron.hot_wallet_address', env('TRON_HOT_WALLET_ADDRESS', ''));
        $this->hotWalletPrivateKey = config('services.tron.hot_wallet_private_key', env('TRON_HOT_WALLET_PRIVATE_KEY', ''));
    }

    /**
     * Process approved withdrawals.
     * 
     * This should be called by a queue worker or scheduled task.
     * 
     * @return int Number of withdrawals processed
     */
    public function processApprovedWithdrawals(): int
    {
        // This will be called from WithdrawService or a queue worker
        // The actual withdrawal records are in the withdrawals table
        return 0;
    }

    /**
     * Send USDT from hot wallet to destination address.
     * 
     * @param string $toAddress Destination address
     * @param float $amount Amount to send
     * @return string|null Transaction ID or null on failure
     */
    public function sendFromHotWallet(string $toAddress, float $amount): ?string
    {
        if (empty($this->hotWalletAddress) || empty($this->hotWalletPrivateKey)) {
            Log::error('TronWithdrawalService: Hot wallet not configured');
            return null;
        }

        try {
            // Decrypt hot wallet private key if encrypted
            $privateKey = $this->decryptHotWalletKey($this->hotWalletPrivateKey);

            // Transfer USDT
            $txid = $this->usdtContract->transferFromPrivateKey(
                $privateKey,
                $toAddress,
                $amount
            );

            return $txid;
        } catch (\Exception $e) {
            Log::error('TronWithdrawalService: Error sending from hot wallet', [
                'to_address' => $toAddress,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Decrypt hot wallet private key if needed.
     */
    private function decryptHotWalletKey(string $encryptedKey): string
    {
        // If the key is already in hex format, return as is
        // Otherwise, decrypt it using the same encryption method as user wallets
        if (strlen($encryptedKey) === 64 && ctype_xdigit($encryptedKey)) {
            return $encryptedKey;
        }

        // Try to decrypt
        try {
            $walletService = app(TronWalletService::class);
            return $walletService->decryptPrivateKey($encryptedKey);
        } catch (\Exception $e) {
            // If decryption fails, assume it's already plaintext (not recommended for production)
            Log::warning('TronWithdrawalService: Failed to decrypt hot wallet key, using as-is');
            return $encryptedKey;
        }
    }
}

