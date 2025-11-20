<?php

namespace App\Console\Commands;

use App\Support\Bip32Helper;
use FurqanSiddiqui\BIP39\BIP39;
use FurqanSiddiqui\BIP39\WordList;
use Illuminate\Console\Command;

class TronVerifyMnemonic extends Command
{
    protected $signature = 'tron:verify-mnemonic 
                            {mnemonic : The mnemonic phrase to verify}
                            {--master-key= : Expected master private key (hex format)}
                            {--path= : Derivation path to test (default: m/44\'/195\'/0\'/0/15)}
                            {--expected-private= : Expected private key for the derivation path}';

    protected $description = 'Verify a mnemonic phrase and compare with TokenPocket/TronLink results';

    public function handle(): int
    {
        $mnemonic = $this->argument('mnemonic');
        $expectedMasterKey = $this->option('master-key');
        $derivationPath = $this->option('path') ?: "m/44'/195'/0'/0/15";
        $expectedPrivateKey = $this->option('expected-private');

        $this->info('=== Mnemonic Verification Tool ===');
        $this->newLine();

        try {
            // Step 1: Validate mnemonic
            $this->info('Step 1: Validating mnemonic phrase...');
            $wordlist = WordList::English();
            $bip39 = BIP39::Words($mnemonic, $wordlist, verifyChecksum: true);
            $this->info('✅ Mnemonic is valid');
            $this->line('   Entropy: ' . $bip39->entropy);
            $this->newLine();

            // Step 2: Generate seed
            $this->info('Step 2: Generating seed from mnemonic...');
            $seed = $bip39->generateSeed('');
            $seedHex = bin2hex($seed);
            $this->info('✅ Seed generated');
            $this->line('   Seed (first 32 hex): ' . substr($seedHex, 0, 32) . '...');
            $this->line('   Seed length: ' . strlen($seed) . ' bytes');
            $this->newLine();

            // Step 3: Derive master key
            $this->info('Step 3: Deriving master key using BIP32...');
            $master = Bip32Helper::deriveMasterKey($seedHex, 'Bitcoin seed');
            $this->info('✅ Master key derived');
            $this->line('   Master private: ' . $master['private_key']);
            $this->line('   Master chain code: ' . substr($master['chain_code'], 0, 32) . '...');
            $this->newLine();

            // Step 4: Compare with expected master key
            if ($expectedMasterKey) {
                $this->info('Step 4: Comparing with expected master key...');
                $expectedMasterKey = strtolower(trim($expectedMasterKey));
                $ourMasterKey = strtolower($master['private_key']);
                
                if ($ourMasterKey === $expectedMasterKey) {
                    $this->info('✅ Master keys MATCH!');
                } else {
                    $this->error('❌ Master keys DO NOT MATCH');
                    $this->line('   Expected: ' . $expectedMasterKey);
                    $this->line('   Ours:     ' . $ourMasterKey);
                    $this->warn('   This suggests the mnemonic or seed generation differs.');
                }
                $this->newLine();
            }

            // Step 5: Derive child key
            $this->info("Step 5: Deriving child key for path: {$derivationPath}");
            $derived = Bip32Helper::deriveFromPath($seedHex, $derivationPath, 'Bitcoin seed');
            $this->info('✅ Child key derived');
            $this->line('   Private key: ' . $derived['private_key']);
            $this->newLine();

            // Step 6: Compare with expected private key
            if ($expectedPrivateKey) {
                $this->info('Step 6: Comparing with expected private key...');
                $expectedPrivateKey = strtolower(trim($expectedPrivateKey));
                $ourPrivateKey = strtolower($derived['private_key']);
                
                if ($ourPrivateKey === $expectedPrivateKey) {
                    $this->info('✅ Private keys MATCH!');
                } else {
                    $this->error('❌ Private keys DO NOT MATCH');
                    $this->line('   Expected: ' . $expectedPrivateKey);
                    $this->line('   Ours:     ' . $ourPrivateKey);
                }
                $this->newLine();
            }

            // Summary
            $this->info('=== Summary ===');
            $this->line('Mnemonic: ' . $mnemonic);
            $this->line('Master private key: ' . $master['private_key']);
            $this->line("Derived private key ({$derivationPath}): " . $derived['private_key']);
            
            if ($expectedMasterKey && strtolower($master['private_key']) !== strtolower($expectedMasterKey)) {
                $this->newLine();
                $this->warn('⚠️  Master keys do not match. Possible reasons:');
                $this->line('   1. Different mnemonic phrase (typo, different word, etc.)');
                $this->line('   2. Different passphrase (even if empty, encoding might differ)');
                $this->line('   3. Different BIP39 implementation');
                $this->line('   4. Different BIP32 implementation');
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getTraceAsString());
            return 1;
        }
    }
}

