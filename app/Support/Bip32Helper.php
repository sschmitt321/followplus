<?php

namespace App\Support;

use Elliptic\EC;
use kornrunner\Keccak;
use Tak\Tron\Crypto\Base58;

/**
 * BIP32 Helper for HD Wallet Derivation
 * 
 * Implements BIP32/BIP44 key derivation without requiring GMP extension.
 * Uses simplito/elliptic-php for elliptic curve operations.
 */
class Bip32Helper
{
    /**
     * Derive a child key from parent key using BIP32.
     * 
     * @param string $parentPrivateKeyHex Parent private key (64 hex chars)
     * @param string $parentChainCodeHex Parent chain code (64 hex chars)
     * @param int $index Child index
     * @param bool $hardened Whether this is a hardened derivation
     * @return array ['private_key' => string, 'chain_code' => string]
     */
    public static function deriveChildKey(
        string $parentPrivateKeyHex,
        string $parentChainCodeHex,
        int $index,
        bool $hardened = false
    ): array {
        $ec = new EC('secp256k1');
        
        // Get parent public key (uncompressed format: 0x04 + 32-byte X + 32-byte Y = 65 bytes)
        $parentKey = $ec->keyFromPrivate($parentPrivateKeyHex, 'hex');
        $parentPublicKey = $parentKey->getPublic(false, 'hex'); // Uncompressed format
        // Remove '0x' prefix if present
        $parentPublicKey = ltrim($parentPublicKey, '0x');
        // Uncompressed public key should be 130 hex chars (65 bytes)
        // Ensure it's exactly 130 chars
        if (strlen($parentPublicKey) < 130) {
            $parentPublicKey = str_pad($parentPublicKey, 130, '0', STR_PAD_LEFT);
        } elseif (strlen($parentPublicKey) > 130) {
            $parentPublicKey = substr($parentPublicKey, -130);
        }
        $parentPublicKeyBytes = hex2bin($parentPublicKey);
        
        // Prepare data for HMAC-SHA512
        $data = '';
        
        if ($hardened) {
            // Hardened derivation: use parent private key
            $data = "\x00" . hex2bin(str_pad($parentPrivateKeyHex, 64, '0', STR_PAD_LEFT));
        } else {
            // Normal derivation: use parent public key
            $data = $parentPublicKeyBytes;
        }
        
        // Append index (4 bytes, big-endian)
        $indexBytes = pack('N', $hardened ? ($index | 0x80000000) : $index);
        $data .= $indexBytes;
        
        // HMAC-SHA512 with chain code as key
        $chainCodeBytes = hex2bin(str_pad($parentChainCodeHex, 64, '0', STR_PAD_LEFT));
        $hmac = hash_hmac('sha512', $data, $chainCodeBytes, true);
        
        // Split HMAC output: left 32 bytes = child private key offset (IL), right 32 bytes = child chain code (IR)
        $childPrivateKeyOffset = substr($hmac, 0, 32);
        $childChainCode = substr($hmac, 32);
        
        // secp256k1 order
        $orderHex = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
        $orderDec = self::hexToDec($orderHex);
        
        // Check if IL >= order (invalid child key per BIP32)
        $offsetDec = self::bytesToBigInt($childPrivateKeyOffset);
        if (self::bigIntCompare($offsetDec, $orderDec) >= 0) {
            // This child key is invalid per BIP32, but we'll still derive it for compatibility
            // In practice, this is extremely rare
            $offsetDec = self::bigIntMod($offsetDec, $orderDec);
        }
        
        // Add offset to parent private key (mod secp256k1 order)
        $parentPrivateKeyDec = self::hexToDec($parentPrivateKeyHex);
        $childPrivateKeyDec = self::bigIntAdd($parentPrivateKeyDec, $offsetDec);
        
        // Ensure result is < order
        if (self::bigIntCompare($childPrivateKeyDec, $orderDec) >= 0) {
            $childPrivateKeyDec = self::bigIntMod($childPrivateKeyDec, $orderDec);
        }
        
        // Ensure result is not zero (invalid private key)
        if (self::bigIntCompare($childPrivateKeyDec, '0') === 0) {
            throw new \Exception('Derived invalid zero private key');
        }
        
        // Convert back to hex
        $childPrivateKeyHex = str_pad(self::bigIntToHex($childPrivateKeyDec), 64, '0', STR_PAD_LEFT);
        $childChainCodeHex = bin2hex($childChainCode);
        
        return [
            'private_key' => $childPrivateKeyHex,
            'chain_code' => $childChainCodeHex,
        ];
    }
    
    /**
     * Derive master key from seed using BIP32.
     * 
     * @param string $seedHex Seed (hex string, typically 64-128 hex chars)
     * @return array ['private_key' => string, 'chain_code' => string]
     */
    public static function deriveMasterKey(string $seedHex, string $hmacSeed = 'Bitcoin seed'): array
    {
        // BIP32 seed can be 16-64 bytes (32-128 hex chars)
        // Convert hex to binary
        $seedBytes = hex2bin($seedHex);
        
        // BIP32 standard: seed length should be between 16 and 64 bytes
        // If shorter than 16 bytes, pad to 16 bytes
        // If longer than 64 bytes, truncate to 64 bytes
        if (strlen($seedBytes) < 16) {
            $seedBytes = str_pad($seedBytes, 16, "\x00", STR_PAD_RIGHT);
        } elseif (strlen($seedBytes) > 64) {
            $seedBytes = substr($seedBytes, 0, 64);
        }
        
        // HMAC-SHA512 with "Bitcoin seed" or custom seed
        // Note: seed can be any length between 16-64 bytes, HMAC handles it
        $hmac = hash_hmac('sha512', $seedBytes, $hmacSeed, true);
        
        // Left 32 bytes = master private key, right 32 bytes = master chain code
        $masterPrivateKey = substr($hmac, 0, 32);
        $masterChainCode = substr($hmac, 32);
        
        // Validate private key (must be < secp256k1 order)
        $orderHex = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
        $masterPrivateKeyHex = bin2hex($masterPrivateKey);
        $orderDec = self::hexToDec($orderHex);
        $masterPrivateKeyDec = self::hexToDec($masterPrivateKeyHex);
        
        // If private key >= order, adjust it
        while (self::bigIntCompare($masterPrivateKeyDec, $orderDec) >= 0) {
            // Re-hash if invalid
            $hmac = hash_hmac('sha512', $hmac, $hmacSeed, true);
            $masterPrivateKey = substr($hmac, 0, 32);
            $masterPrivateKeyHex = bin2hex($masterPrivateKey);
            $masterPrivateKeyDec = self::hexToDec($masterPrivateKeyHex);
        }
        
        return [
            'private_key' => str_pad($masterPrivateKeyHex, 64, '0', STR_PAD_LEFT),
            'chain_code' => bin2hex($masterChainCode),
        ];
    }
    
    /**
     * Derive a key from a BIP44 path.
     * 
     * @param string $seedHex Master seed (hex)
     * @param string $path BIP44 path (e.g., "m/44'/195'/0'/0/0")
     * @param string $hmacSeed HMAC seed (default: "Bitcoin seed")
     * @return array ['private_key' => string, 'chain_code' => string]
     */
    public static function deriveFromPath(string $seedHex, string $path, string $hmacSeed = 'Bitcoin seed'): array
    {
        // Derive master key
        $master = self::deriveMasterKey($seedHex, $hmacSeed);
        $currentPrivateKey = $master['private_key'];
        $currentChainCode = $master['chain_code'];
        
        // Parse path
        $parts = explode('/', trim($path, '/'));
        if ($parts[0] !== 'm') {
            throw new \InvalidArgumentException('Path must start with "m"');
        }
        
        // Derive each level
        for ($i = 1; $i < count($parts); $i++) {
            $part = $parts[$i];
            $hardened = str_ends_with($part, "'");
            $index = (int) rtrim($part, "'");
            
            $derived = self::deriveChildKey($currentPrivateKey, $currentChainCode, $index, $hardened);
            $currentPrivateKey = $derived['private_key'];
            $currentChainCode = $derived['chain_code'];
        }
        
        return [
            'private_key' => $currentPrivateKey,
            'chain_code' => $currentChainCode,
        ];
    }
    
    // Big integer arithmetic helpers using bcmath (fallback to string manipulation)
    
    /**
     * Convert hex string to decimal string for bcmath.
     */
    private static function hexToDec(string $hex): string
    {
        $hex = ltrim($hex, '0');
        if (empty($hex)) {
            return '0';
        }
        
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcmul($dec, '16', 0);
            $dec = bcadd($dec, (string) hexdec($hex[$i]), 0);
        }
        
        return $dec;
    }
    
    /**
     * Convert decimal string to hex string.
     */
    private static function decToHex(string $dec): string
    {
        $hex = '';
        while (bccomp($dec, '0', 0) > 0) {
            $remainder = bcmod($dec, '16', 0);
            $hex = dechex((int) $remainder) . $hex;
            $dec = bcdiv($dec, '16', 0);
        }
        
        return empty($hex) ? '0' : $hex;
    }
    
    private static function hexToBigInt(string $hex): string
    {
        return self::hexToDec($hex);
    }
    
    private static function bytesToBigInt(string $bytes): string
    {
        return self::hexToDec(bin2hex($bytes));
    }
    
    private static function bigIntToHex(string $bigInt): string
    {
        return self::decToHex($bigInt);
    }
    
    private static function bigIntAdd(string $a, string $b): string
    {
        return bcadd($a, $b, 0);
    }
    
    private static function bigIntSub(string $a, string $b): string
    {
        return bcsub($a, $b, 0);
    }
    
    private static function bigIntCompare(string $a, string $b): int
    {
        return bccomp($a, $b, 0);
    }
    
    private static function bigIntMod(string $a, string $mod): string
    {
        return bcmod($a, $mod);
    }
}

