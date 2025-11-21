<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Tron Helper Class
 * 
 * Provides utility functions for Tron address conversion and validation.
 * For production use, consider integrating a Tron SDK like iexbase/tron-api.
 */
class TronHelper
{
    /**
     * Convert Tron base58 address to hex format (41...).
     * 
     * @param string $address Tron address (base58, starts with T)
     * @return string Hex address (starts with 41)
     */
    public static function addressToHex(string $address): string
    {
        // If already hex format, return as-is
        if (strlen($address) === 42 && str_starts_with($address, '41')) {
            return $address;
        }
        
        // Use takpesar/tron library for base58 decoding
        try {
            // Decode base58 address to hex
            $hexAddress = \Tak\Tron\Crypto\Base58::decodeAddress($address);
            
            // Ensure it starts with 41
            if (!str_starts_with($hexAddress, '41')) {
                $hexAddress = '41' . ltrim($hexAddress, '41');
            }
            
            // Ensure length is 42 (41 + 20 bytes = 40 hex chars)
            if (strlen($hexAddress) !== 42) {
                // Pad or truncate to 42 chars
                if (strlen($hexAddress) < 42) {
                    $hexAddress = str_pad($hexAddress, 42, '0', STR_PAD_RIGHT);
                } else {
                    $hexAddress = substr($hexAddress, 0, 42);
                }
            }
            
            return $hexAddress;
        } catch (\Exception $e) {
            Log::warning('TronHelper: Failed to convert address to hex', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback: try API conversion
            try {
                $baseUrl = config('services.tron.node_url', 'https://api.trongrid.io');
                $apiKey = config('services.tron.api_key', '');
                $headers = [];
                if ($apiKey) {
                    $headers['TRON-PRO-API-KEY'] = $apiKey;
                }
                
                $response = \Illuminate\Support\Facades\Http::withHeaders($headers)
                    ->get("{$baseUrl}/v1/accounts/{$address}");
                
                if ($response->successful()) {
                    $data = $response->json();
                    // API returns hex address in 'address' field
                    if (isset($data['address']) && str_starts_with($data['address'], '41')) {
                        return $data['address'];
                    }
                }
            } catch (\Exception $apiException) {
                Log::warning('TronHelper: API fallback also failed', [
                    'error' => $apiException->getMessage(),
                ]);
            }
            
            // Last resort: return as-is (will likely cause errors)
            return $address;
        }
    }

    /**
     * Convert hex address to Tron base58 format.
     * 
     * @param string $hexAddress Hex address (starts with 41)
     * @return string Tron address (starts with T)
     */
    public static function hexToAddress(string $hexAddress): string
    {
        // If already starts with T, return as-is
        if (str_starts_with($hexAddress, 'T')) {
            return $hexAddress;
        }
        
        // Remove 0x prefix if present
        if (str_starts_with($hexAddress, '0x') || str_starts_with($hexAddress, '0X')) {
            $hexAddress = substr($hexAddress, 2);
        }
        
        // If it already starts with 41, use as-is
        // Otherwise, add 41 prefix (Tron addresses start with 41 in hex)
        if (!str_starts_with($hexAddress, '41')) {
            // If it's a 40-char hex (20 bytes), add 41 prefix
            if (strlen($hexAddress) === 40 && ctype_xdigit($hexAddress)) {
                $hexAddress = '41' . $hexAddress;
            } else {
                // Otherwise, try to prepend 41
                $hexAddress = '41' . ltrim($hexAddress, '41');
            }
        }
        
        // Ensure length is 42 (41 + 20 bytes = 40 hex chars)
        if (strlen($hexAddress) !== 42) {
            if (strlen($hexAddress) < 42) {
                $hexAddress = str_pad($hexAddress, 42, '0', STR_PAD_RIGHT);
            } else {
                $hexAddress = substr($hexAddress, 0, 42);
            }
        }
        
        // Use takpesar/tron library for base58 encoding
        try {
            return \Tak\Tron\Crypto\Base58::encodeAddress($hexAddress);
        } catch (\Exception $e) {
            Log::warning('TronHelper: Failed to convert hex to address', [
                'hex' => $hexAddress,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback: try API conversion
            try {
                $baseUrl = config('services.tron.node_url', 'https://api.trongrid.io');
                $apiKey = config('services.tron.api_key', '');
                $headers = [];
                if ($apiKey) {
                    $headers['TRON-PRO-API-KEY'] = $apiKey;
                }
                
                $response = \Illuminate\Support\Facades\Http::withHeaders($headers)
                    ->get("{$baseUrl}/v1/accounts/{$hexAddress}");
                
                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data['address']) && str_starts_with($data['address'], 'T')) {
                        return $data['address'];
                    }
                }
            } catch (\Exception $apiException) {
                Log::warning('TronHelper: API fallback also failed', [
                    'error' => $apiException->getMessage(),
                ]);
            }
            
            // Last resort: return with T prefix (will likely be invalid)
            return 'T' . substr($hexAddress, 2);
        }
    }

    /**
     * Validate Tron address format.
     * 
     * @param string $address Address to validate
     * @return bool True if valid
     */
    public static function isValidAddress(string $address): bool
    {
        // Basic validation: starts with T and has correct length
        if (str_starts_with($address, 'T') && strlen($address) === 34) {
            return true;
        }
        
        // Also accept hex format (41...)
        if (str_starts_with($address, '41') && strlen($address) === 42) {
            return true;
        }
        
        return false;
    }

    /**
     * Generate Tron address from private key.
     * 
     * Uses the correct Tron address generation algorithm:
     * 1. ECDSA (secp256k1) to get public key from private key
     * 2. Keccak256 hash of public key (last 64 bytes, X+Y without 0x04 prefix)
     * 3. Take last 20 bytes from hash
     * 4. Add 0x41 prefix
     * 5. Base58 encode with checksum
     * 
     * @param string $privateKey Hex private key (64 characters)
     * @return array ['address' => string, 'private_key' => string]
     */
    public static function generateAddressFromPrivateKey(string $privateKey): array
    {
        // Remove 0x prefix if present (but preserve leading zeros)
        if (str_starts_with($privateKey, '0x') || str_starts_with($privateKey, '0X')) {
            $privateKey = substr($privateKey, 2);
        }
        
        // Validate private key length (64 hex chars = 32 bytes)
        if (strlen($privateKey) !== 64 || !ctype_xdigit($privateKey)) {
            throw new \InvalidArgumentException('Invalid private key format. Expected 64 hex characters.');
        }

        try {
            // Use takpesar/tron library for correct address generation
            $tron = new \Tak\Tron\API();
            
            // 1. Create secp256k1 elliptic curve
            $ec = new \Elliptic\EC('secp256k1');
            
            // 2. Create key pair from private key
            $keyPair = $ec->keyFromPrivate($privateKey, 'hex');
            
            // 3. Get public key in hex format (uncompressed, includes 0x04 prefix)
            $publicKeyHex = $keyPair->getPublic(false, 'hex');
            // Remove 0x prefix if present
            $publicKeyHex = ltrim($publicKeyHex, '0x');
            // Ensure public key is exactly 130 hex chars (65 bytes: 0x04 + 32-byte X + 32-byte Y)
            if (strlen($publicKeyHex) < 130) {
                $publicKeyHex = str_pad($publicKeyHex, 130, '0', STR_PAD_LEFT);
            } elseif (strlen($publicKeyHex) > 130) {
                $publicKeyHex = substr($publicKeyHex, 0, 130);
            }
            
            // 4. Get address hex using library method (handles Keccak256 and formatting)
            // The library expects hex string without 0x prefix, exactly 130 chars
            $addressHex = $tron->getAddressHexFromPublicKey($publicKeyHex);
            
            // 5. Convert hex address to base58 address (includes checksum)
            $address = \Tak\Tron\Crypto\Base58::encodeAddress($addressHex);
            
            return [
                'address' => $address,
                'private_key' => $privateKey,
            ];
        } catch (\Exception $e) {
            Log::error('TronHelper: Failed to generate address from private key', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('Failed to generate Tron address: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate a new Tron address and private key.
     * 
     * @return array ['address' => string, 'private_key' => string]
     */
    public static function generateNewAddress(): array
    {
        // Generate random private key (64 hex characters = 32 bytes)
        $privateKey = bin2hex(random_bytes(32));
        
        return self::generateAddressFromPrivateKey($privateKey);
    }

    /**
     * Convert USDT amount to hex (for contract calls).
     * USDT has 6 decimals.
     * 
     * @param float|string $amount Amount in USDT
     * @return string Hex representation
     */
    public static function usdtAmountToHex(float|string $amount): string
    {
        // Convert to integer (multiply by 10^6)
        $amountInt = bcmul((string)$amount, '1000000', 0);
        
        // Convert to hex
        $hex = dechex((int)$amountInt);
        
        // Pad to 64 characters (32 bytes)
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    /**
     * Convert hex amount to USDT.
     * 
     * @param string $hexValue Hex value
     * @return string USDT amount
     */
    public static function hexToUsdtAmount(string $hexValue): string
    {
        // Remove 0x prefix if present
        $hexValue = ltrim($hexValue, '0x');
        
        // Convert hex to decimal
        $value = hexdec($hexValue);
        
        // Divide by 10^6
        return bcdiv((string)$value, '1000000', 6);
    }
}

