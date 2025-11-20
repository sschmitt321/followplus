<?php

namespace App\Services\Tron;

use App\Models\UserTronWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TronWalletService
{
    /**
     * Get or create deposit address for user.
     * 
     * If HD wallet is initialized, uses HD wallet derivation.
     * Otherwise, generates a random address.
     * 
     * Handles race conditions by using updateOrCreate and letting
     * deriveAddressForUser handle its own transaction.
     */
    public function getOrCreateDepositAddress(int $userId): string
    {
        // Quick check without lock first
        $wallet = UserTronWallet::where('user_id', $userId)->first();
        if ($wallet) {
            return $wallet->tron_address;
        }

        // Try to use HD wallet if available
        // deriveAddressForUser handles its own transaction and locking
        $hdWalletService = app(TronHdWalletService::class);
        if ($hdWalletService->isInitialized()) {
            try {
                $derived = $hdWalletService->deriveAddressForUser($userId);
                
                // Double-check in case another request created it concurrently
                $wallet = UserTronWallet::where('user_id', $userId)->first();
                return $wallet ? $wallet->tron_address : $derived['address'];
            } catch (\Exception $e) {
                // Always check if wallet was created, even if exception occurred
                // This handles cases where wallet was created but exception was thrown (e.g., deadlock)
                $wallet = UserTronWallet::where('user_id', $userId)->first();
                if ($wallet) {
                    Log::info('TronWalletService: Wallet found after exception, returning existing address', [
                        'user_id' => $userId,
                        'address' => $wallet->tron_address,
                        'exception' => $e->getMessage(),
                    ]);
                    return $wallet->tron_address;
                }

                // Only log warning if wallet truly doesn't exist
                Log::warning('TronWalletService: Failed to derive HD address, wallet not created', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                
                // Re-throw exception if it's not a deadlock (deadlock will be retried by deriveAddressForUser)
                // For other errors, fall through to fallback logic
                if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'deadlock')) {
                    throw $e; // Let the retry mechanism handle it
                }
            }
        }

        // Fallback: Generate new random Tron address
        // Use transaction with updateOrCreate to handle race conditions
        return DB::transaction(function () use ($userId) {
            // Check again with lock inside transaction
            $wallet = UserTronWallet::lockForUpdate()
                ->where('user_id', $userId)
                ->first();

            if ($wallet) {
                return $wallet->tron_address;
            }

            $addressData = $this->generateTronAddress();
            $address = $addressData['address'];
            $privateKey = $addressData['private_key'];

            // Encrypt private key
            $encryptedPk = $this->encryptPrivateKey($privateKey);

            // Use updateOrCreate to prevent duplicate key errors
            $wallet = UserTronWallet::updateOrCreate(
                ['user_id' => $userId],
                [
                    'tron_address' => $address,
                    'derivation_index' => 0,
                    'encrypted_private_key' => $encryptedPk,
                ]
            );

            return $wallet->tron_address;
        });
    }

    /**
     * Generate Tron address and private key.
     */
    private function generateTronAddress(): array
    {
        return \App\Support\TronHelper::generateNewAddress();
    }

    /**
     * Encrypt private key using AES-256-GCM.
     */
    public function encryptPrivateKey(string $privateKey): string
    {
        $key = config('services.tron.encryption_key', env('TRON_PK_ENC_KEY'));
        
        if (empty($key)) {
            throw new \Exception('TRON_PK_ENC_KEY is not configured');
        }

        // Ensure key is 32 bytes for AES-256
        $key = substr(hash('sha256', $key), 0, 32);
        
        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $privateKey,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new \Exception('Failed to encrypt private key');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt private key.
     */
    public function decryptPrivateKey(string $encrypted): string
    {
        $key = config('services.tron.encryption_key', env('TRON_PK_ENC_KEY'));
        
        if (empty($key)) {
            throw new \Exception('TRON_PK_ENC_KEY is not configured');
        }

        // Ensure key is 32 bytes for AES-256
        $key = substr(hash('sha256', $key), 0, 32);
        
        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);

        $data = base64_decode($encrypted);
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, 16);
        $ciphertext = substr($data, $ivLength + 16);

        $privateKey = openssl_decrypt(
            $ciphertext,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($privateKey === false) {
            throw new \Exception('Failed to decrypt private key');
        }

        return $privateKey;
    }

    /**
     * Get wallet by user ID.
     */
    public function getWalletByUserId(int $userId): ?UserTronWallet
    {
        return UserTronWallet::where('user_id', $userId)->first();
    }

    /**
     * Get wallet by address.
     */
    public function getWalletByAddress(string $address): ?UserTronWallet
    {
        return UserTronWallet::where('tron_address', $address)->first();
    }
}

