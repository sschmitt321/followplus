<?php

namespace App\Services\Tron;

use App\Models\TronHdWallet;
use App\Models\UserTronWallet;
use App\Support\Bip32Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tron HD Wallet Service
 * 
 * Manages HD wallet master seed and derives child addresses for users.
 * Uses BIP44 derivation path: m/44'/195'/0'/0/{index}
 * 
 * Tron coin type: 195
 */
class TronHdWalletService
{
    private const COIN_TYPE = 195; // Tron coin type for BIP44
    private const DERIVATION_PATH_PREFIX = "m/44'/195'/0'/0/";

    /**
     * Initialize HD wallet with master seed.
     * 
     * @param string $masterSeed Master seed (mnemonic phrase or hex seed)
     * @param bool $force Force re-initialization even if already initialized
     * @return bool Success
     */
    public function initialize(string $masterSeed, bool $force = false): bool
    {
        try {
            $hdWallet = TronHdWallet::getInstance();
            
            // Check if already initialized (unless force is true)
            if (!empty($hdWallet->encrypted_master_seed) && !$force) {
                throw new \Exception('HD wallet already initialized');
            }

            // Encrypt master seed
            $encrypted = $this->encryptMasterSeed($masterSeed);

            // Update HD wallet
            $hdWallet->update([
                'encrypted_master_seed' => $encrypted,
                'next_derivation_index' => 0,
            ]);

            Log::info('TronHdWalletService: HD wallet initialized successfully', [
                'force' => $force,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('TronHdWalletService: Failed to initialize HD wallet', [
                'error' => $e->getMessage(),
                'force' => $force,
            ]);
            return false;
        }
    }

    /**
     * Check if HD wallet is initialized.
     */
    public function isInitialized(): bool
    {
        $hdWallet = TronHdWallet::getInstance();
        return !empty($hdWallet->encrypted_master_seed);
    }

    /**
     * Derive a child address for a user.
     * 
     * @param int $userId User ID
     * @return array ['address' => string, 'private_key' => string, 'derivation_index' => int]
     */
    public function deriveAddressForUser(int $userId): array
    {
        if (!$this->isInitialized()) {
            throw new \Exception('HD wallet not initialized. Please run: php artisan tron:init-hd-wallet');
        }

        // Use a retry mechanism for deadlock handling
        $maxRetries = 3;
        $retryCount = 0;
        
        while ($retryCount < $maxRetries) {
            try {
                return DB::transaction(function () use ($userId) {
                    // Lock tables in consistent order to prevent deadlocks:
                    // 1. First lock HD wallet (always exists, single row)
                    // 2. Then check and lock user wallet
                    // Get instance first, then lock it
                    $hdWallet = TronHdWallet::lockForUpdate()->first();
                    if (!$hdWallet) {
                        // If doesn't exist, create it (shouldn't happen if initialized)
                        $hdWallet = TronHdWallet::create([
                            'encrypted_master_seed' => '',
                            'next_derivation_index' => 0,
                        ]);
                    }
                    
                    // Check if user wallet already exists with lock to prevent race conditions
                    $existingWallet = UserTronWallet::lockForUpdate()
                        ->where('user_id', $userId)
                        ->first();

                    if ($existingWallet) {
                        // User already has a wallet, return existing address
                        $walletService = app(TronWalletService::class);
                        $privateKey = $walletService->decryptPrivateKey($existingWallet->encrypted_private_key);

                        return [
                            'address' => $existingWallet->tron_address,
                            'private_key' => $privateKey,
                            'derivation_index' => $existingWallet->derivation_index,
                        ];
                    }

                    // User doesn't have a wallet, derive new address
                    // Get next derivation index
                    $derivationIndex = $hdWallet->next_derivation_index;
                    
                    // Derive address from master seed
                    $masterSeed = $this->decryptMasterSeed($hdWallet->encrypted_master_seed);
                    $derived = $this->deriveAddress($masterSeed, $derivationIndex);

                    // Save user wallet - use create() and catch duplicate key errors
                    // Create wallet FIRST, then update index to ensure atomicity
                    $encryptedPk = app(TronWalletService::class)->encryptPrivateKey($derived['private_key']);
                    
                    try {
                        UserTronWallet::create([
                            'user_id' => $userId,
                            'tron_address' => $derived['address'],
                            'derivation_index' => $derivationIndex,
                            'encrypted_private_key' => $encryptedPk,
                        ]);
                        
                        // Only increment next_derivation_index AFTER wallet is successfully created
                        $hdWallet->update([
                            'next_derivation_index' => $derivationIndex + 1,
                        ]);
                    } catch (\Illuminate\Database\QueryException $e) {
                        // Handle duplicate key error (race condition)
                        if ($e->getCode() == 23000) { // Integrity constraint violation
                            // Another request created the wallet, fetch it
                            $existingWallet = UserTronWallet::where('user_id', $userId)->first();
                            if ($existingWallet) {
                                $walletService = app(TronWalletService::class);
                                $privateKey = $walletService->decryptPrivateKey($existingWallet->encrypted_private_key);
                                return [
                                    'address' => $existingWallet->tron_address,
                                    'private_key' => $privateKey,
                                    'derivation_index' => $existingWallet->derivation_index,
                                ];
                            }
                        }
                        throw $e;
                    }

                    Log::info('TronHdWalletService: Derived address for user', [
                        'user_id' => $userId,
                        'derivation_index' => $derivationIndex,
                        'address' => $derived['address'],
                    ]);

                    return [
                        'address' => $derived['address'],
                        'private_key' => $derived['private_key'],
                        'derivation_index' => $derivationIndex,
                    ];
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle deadlock (error code 1213 or 40001)
                $errorCode = $e->getCode();
                $errorMessage = $e->getMessage();
                
                // Check if wallet was created despite the exception
                $existingWallet = UserTronWallet::where('user_id', $userId)->first();
                if ($existingWallet) {
                    // Wallet was created, return it even though exception occurred
                    Log::info('TronHdWalletService: Wallet found after exception, returning existing', [
                        'user_id' => $userId,
                        'address' => $existingWallet->tron_address,
                        'exception' => $errorMessage,
                    ]);
                    $walletService = app(TronWalletService::class);
                    $privateKey = $walletService->decryptPrivateKey($existingWallet->encrypted_private_key);
                    return [
                        'address' => $existingWallet->tron_address,
                        'private_key' => $privateKey,
                        'derivation_index' => $existingWallet->derivation_index,
                    ];
                }
                
                if (($errorCode == 40001 || $errorCode == 1213) && 
                    (str_contains($errorMessage, 'Deadlock') || str_contains($errorMessage, 'deadlock'))) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        Log::error('TronHdWalletService: Failed to derive address after retries', [
                            'user_id' => $userId,
                            'retries' => $retryCount,
                            'error' => $errorMessage,
                        ]);
                        throw new \Exception('Failed to derive address after ' . $maxRetries . ' retries due to deadlocks', 0, $e);
                    }
                    // Wait a random amount of time before retrying (exponential backoff)
                    $delay = rand(10000, 50000) * $retryCount;
                    usleep($delay);
                    Log::warning('TronHdWalletService: Deadlock detected, retrying', [
                        'user_id' => $userId,
                        'retry' => $retryCount,
                        'delay_us' => $delay,
                    ]);
                    continue;
                }
                throw $e;
            }
        }
        
        throw new \Exception('Failed to derive address after retries');
    }

    /**
     * Derive address from master seed using derivation index.
     * 
     * @param string $masterSeed Master seed
     * @param int $index Derivation index
     * @return array ['address' => string, 'private_key' => string]
     */
    /**
     * Derive a child address from master seed using BIP32/BIP44.
     * 
     * @param string $masterSeed Master seed (hex string, 64 characters)
     * @param int $index Derivation index
     * @return array ['address' => string, 'private_key' => string]
     */
    private function deriveAddress(string $masterSeed, int $index): array
    {
        try {
            // Validate master seed format
            if (strlen($masterSeed) !== 64 || !ctype_xdigit($masterSeed)) {
                throw new \InvalidArgumentException('Master seed must be 64 hex characters (32 bytes)');
            }
            
            // Derive path: m/44'/195'/0'/0/{index}
            // 44' = purpose (BIP44)
            // 195' = coin type (Tron)
            // 0' = account
            // 0 = change (external addresses)
            // {index} = address index
            $derivationPath = sprintf("m/44'/195'/0'/0/%d", $index);
            
            // Convert entropy to seed using BIP39 standard (PBKDF2 with "mnemonic" passphrase)
            // This ensures compatibility with standard wallets like TokenPocket
            $seed = $this->entropyToSeed($masterSeed);
            
            // Use BIP32 helper to derive key from path
            // Standard BIP32 uses "Bitcoin seed" as HMAC seed
            $derived = Bip32Helper::deriveFromPath(bin2hex($seed), $derivationPath, 'Bitcoin seed');
            
            $privateKeyHex = $derived['private_key'];
            
            // Ensure private key is 64 hex characters (32 bytes)
            $privateKeyHex = str_pad($privateKeyHex, 64, '0', STR_PAD_LEFT);
            
            // Generate Tron address from private key
            $addressData = \App\Support\TronHelper::generateAddressFromPrivateKey($privateKeyHex);
            
            Log::info('TronHdWalletService: Derived address using BIP32/BIP44', [
                'index' => $index,
                'path' => $derivationPath,
                'address' => $addressData['address'],
            ]);
            
            return [
                'address' => $addressData['address'],
                'private_key' => $privateKeyHex,
            ];
        } catch (\Exception $e) {
            Log::error('TronHdWalletService: Failed to derive address using BIP32/BIP44', [
                'index' => $index,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Fallback to placeholder for backward compatibility (should not happen)
            $hash = hash('sha256', $masterSeed . '|' . $index);
            $privateKey = substr($hash, 0, 64);
            $addressData = \App\Support\TronHelper::generateAddressFromPrivateKey($privateKey);
            
            Log::warning('TronHdWalletService: Using fallback placeholder derivation', [
                'index' => $index,
            ]);
            
            return [
                'address' => $addressData['address'],
                'private_key' => $privateKey,
            ];
        }
    }

    /**
     * Convert entropy to seed using BIP39 standard.
     * 
     * BIP39 standard: entropy -> seed using PBKDF2 with "mnemonic" passphrase
     * 
     * @param string $entropyHex Entropy in hex format (64 hex chars = 32 bytes)
     * @param string $passphrase Optional passphrase (default: empty string)
     * @return string Seed in binary format (64 bytes)
     */
    private function entropyToSeed(string $entropyHex, string $passphrase = ''): string
    {
        // Convert entropy to mnemonic phrase using BIP39
        $wordlist = \FurqanSiddiqui\BIP39\WordList::English();
        $mnemonic = \FurqanSiddiqui\BIP39\BIP39::Entropy($entropyHex, $wordlist);
        $mnemonicPhrase = implode(' ', $mnemonic->words);
        
        // Generate seed from mnemonic using BIP39 standard
        // This uses PBKDF2-SHA512 with 2048 iterations
        $bip39 = \FurqanSiddiqui\BIP39\BIP39::Words($mnemonicPhrase, $wordlist, verifyChecksum: true);
        $seed = $bip39->generateSeed($passphrase);
        
        return $seed;
    }

    /**
     * Encrypt master seed.
     */
    private function encryptMasterSeed(string $masterSeed): string
    {
        $key = config('services.tron.encryption_key', env('TRON_PK_ENC_KEY'));
        
        if (empty($key)) {
            throw new \Exception('TRON_PK_ENC_KEY is not configured. Please set it in your .env file. You can generate one using: php -r "echo bin2hex(random_bytes(32));"');
        }

        // Ensure key is 32 bytes for AES-256
        $key = substr(hash('sha256', $key), 0, 32);
        
        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $masterSeed,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new \Exception('Failed to encrypt master seed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt master seed.
     */
    private function decryptMasterSeed(string $encrypted): string
    {
        $key = config('services.tron.encryption_key', env('TRON_PK_ENC_KEY'));
        
        if (empty($key)) {
            throw new \Exception('TRON_PK_ENC_KEY is not configured. Please set it in your .env file. You can generate one using: php -r "echo bin2hex(random_bytes(32));"');
        }

        // Ensure key is 32 bytes for AES-256
        $key = substr(hash('sha256', $key), 0, 32);
        
        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);

        $data = base64_decode($encrypted);
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, 16);
        $ciphertext = substr($data, $ivLength + 16);

        $masterSeed = openssl_decrypt(
            $ciphertext,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($masterSeed === false) {
            throw new \Exception('Failed to decrypt master seed');
        }

        return $masterSeed;
    }

    /**
     * Get current derivation index.
     */
    public function getCurrentDerivationIndex(): int
    {
        $hdWallet = TronHdWallet::getInstance();
        return $hdWallet->next_derivation_index;
    }
}

