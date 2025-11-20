<?php

namespace App\Console\Commands;

use App\Models\TronHdWallet;
use App\Services\Tron\TronHdWalletService;
use Illuminate\Console\Command;

class TronExportMasterSeed extends Command
{
    protected $signature = 'tron:export-master-seed 
                            {--format=hex : Output format (hex or mnemonic)}
                            {--output= : Save to file instead of displaying}
                            {--verify : Verify mnemonic by converting back to hex}';

    protected $description = 'Export HD wallet master seed as hex or BIP39 mnemonic phrase (WARNING: sensitive data!)';

    public function handle(TronHdWalletService $hdService): int
    {
        if (!$hdService->isInitialized()) {
            $this->error('HD wallet not initialized. Please run: php artisan tron:init-hd-wallet');
            return 1;
        }

        if (!$this->confirm('⚠️  WARNING: This will display the master seed. Anyone with this seed can control all addresses. Continue?')) {
            $this->info('Export cancelled.');
            return 0;
        }

        try {
            $hdWallet = TronHdWallet::getInstance();
            
            // Use reflection to call private decryptMasterSeed method
            $reflection = new \ReflectionClass($hdService);
            $decryptMethod = $reflection->getMethod('decryptMasterSeed');
            $decryptMethod->setAccessible(true);
            $masterSeed = $decryptMethod->invoke($hdService, $hdWallet->encrypted_master_seed);

            $format = $this->option('format');
            $output = $this->option('output');

            if ($format === 'hex') {
                $content = $masterSeed;
                $this->info("\n📋 Master Seed (hex format):");
            } else {
                // Convert hex seed to mnemonic phrase
                try {
                    $wordlist = \FurqanSiddiqui\BIP39\WordList::English();
                    
                    // Master seed is 64 hex characters (32 bytes = 256 bits)
                    // For BIP39, we need entropy in multiples of 32 bits (128, 160, 192, 224, 256)
                    // 256 bits = 24 words
                    $mnemonic = \FurqanSiddiqui\BIP39\BIP39::Entropy($masterSeed, $wordlist);
                    $mnemonicPhrase = implode(' ', $mnemonic->words);
                    $content = $mnemonicPhrase;
                    $this->info("\n📋 Master Seed (BIP39 mnemonic, 24 words):");
                    
                    // Verify mnemonic if requested
                    if ($this->option('verify')) {
                        $verifiedMnemonic = \FurqanSiddiqui\BIP39\BIP39::Words($mnemonicPhrase, $wordlist, verifyChecksum: true);
                        $verifiedHex = $verifiedMnemonic->entropy;
                        if ($masterSeed === $verifiedHex) {
                            $this->info("✅ Verification: Mnemonic correctly converts back to original hex seed");
                        } else {
                            $this->error("❌ Verification failed: Mnemonic does not match original seed");
                        }
                    }
                } catch (\Exception $e) {
                    $this->error('Failed to convert to mnemonic: ' . $e->getMessage());
                    $this->warn('Falling back to hex format.');
                    $content = $masterSeed;
                    $this->info("\n📋 Master Seed (hex format):");
                }
            }

            if ($output) {
                // Save to file
                file_put_contents($output, $content);
                $this->info("✅ Master seed saved to: {$output}");
                $this->warn("⚠️  Make sure to secure this file! Delete it after backing up.");
            } else {
                // Display on screen
                $this->line($content);
                $this->newLine();
                $this->warn("⚠️  Keep this secure! Anyone with this seed can control all addresses.");
                $this->info("💡 Tip: Use --output=filename to save to a file instead.");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to export master seed: ' . $e->getMessage());
            return 1;
        }
    }
}

