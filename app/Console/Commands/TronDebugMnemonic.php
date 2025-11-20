<?php

namespace App\Console\Commands;

use App\Support\Bip32Helper;
use FurqanSiddiqui\BIP39\BIP39;
use FurqanSiddiqui\BIP39\WordList;
use Illuminate\Console\Command;

class TronDebugMnemonic extends Command
{
    protected $signature = 'tron:debug-mnemonic 
                            {mnemonic : The mnemonic phrase to debug}
                            {--expected-master= : Expected master private key}
                            {--expected-seed= : Expected seed (hex format)}';

    protected $description = 'Debug mnemonic phrase and seed generation to find discrepancies';

    public function handle(): int
    {
        $mnemonic = $this->argument('mnemonic');
        $expectedMaster = $this->option('expected-master');
        $expectedSeed = $this->option('expected-seed');

        $this->info('=== Mnemonic Debugging Tool ===');
        $this->newLine();

        try {
            $wordlist = WordList::English();
            
            // Parse mnemonic
            $this->info('1. Parsing mnemonic...');
            $words = explode(' ', trim($mnemonic));
            $this->line('   Word count: ' . count($words));
            $this->line('   Words: ' . implode(', ', array_map(function($w, $i) {
                return ($i + 1) . '. ' . $w;
            }, $words, array_keys($words))));
            $this->newLine();

            // Validate mnemonic
            $this->info('2. Validating mnemonic...');
            $bip39 = BIP39::Words($mnemonic, $wordlist, verifyChecksum: true);
            $entropy = $bip39->entropy;
            $this->info('✅ Mnemonic is valid');
            $this->line('   Entropy: ' . $entropy);
            $this->line('   Entropy length: ' . strlen($entropy) . ' hex chars (' . (strlen($entropy)/2) . ' bytes)');
            $this->newLine();

            // Generate seed using different methods
            $this->info('3. Generating seed using different methods...');
            
            // Method 1: BIP39 library
            $seed1 = $bip39->generateSeed('');
            $seed1Hex = bin2hex($seed1);
            $this->line('   Method 1 (BIP39 library):');
            $this->line('     Seed: ' . substr($seed1Hex, 0, 32) . '...');
            $this->line('     Length: ' . strlen($seed1) . ' bytes');
            
            // Method 2: Manual PBKDF2
            $seed2 = hash_pbkdf2('sha512', $mnemonic, 'mnemonic', 2048, 64, true);
            $seed2Hex = bin2hex($seed2);
            $this->line('   Method 2 (Manual PBKDF2):');
            $this->line('     Seed: ' . substr($seed2Hex, 0, 32) . '...');
            $this->line('     Length: ' . strlen($seed2) . ' bytes');
            $this->line('     Match: ' . ($seed1Hex === $seed2Hex ? '✅ YES' : '❌ NO'));
            $this->newLine();

            // Compare with expected seed
            if ($expectedSeed) {
                $expectedSeed = strtolower(trim($expectedSeed));
                $this->info('4. Comparing with expected seed...');
                $this->line('   Expected: ' . substr($expectedSeed, 0, 32) . '...');
                $this->line('   Ours:     ' . substr($seed1Hex, 0, 32) . '...');
                $this->line('   Match: ' . (strtolower($seed1Hex) === $expectedSeed ? '✅ YES' : '❌ NO'));
                $this->newLine();
            }

            // Derive master key
            $this->info('5. Deriving master key...');
            $master = Bip32Helper::deriveMasterKey($seed1Hex, 'Bitcoin seed');
            $this->info('✅ Master key derived');
            $this->line('   Master private: ' . $master['private_key']);
            $this->line('   Master chain code: ' . substr($master['chain_code'], 0, 32) . '...');
            $this->newLine();

            // Compare with expected master key
            if ($expectedMaster) {
                $expectedMaster = strtolower(trim($expectedMaster));
                $this->info('6. Comparing with expected master key...');
                $this->line('   Expected: ' . $expectedMaster);
                $this->line('   Ours:     ' . strtolower($master['private_key']));
                $this->line('   Match: ' . (strtolower($master['private_key']) === $expectedMaster ? '✅ YES' : '❌ NO'));
                $this->newLine();
            }

            // Test different HMAC seeds
            $this->info('7. Testing different HMAC seeds...');
            $hmacSeeds = ['Bitcoin seed', 'Tron seed', 'mnemonic', 'TRON seed'];
            foreach ($hmacSeeds as $hmacSeed) {
                $testMaster = Bip32Helper::deriveMasterKey($seed1Hex, $hmacSeed);
                $match = $expectedMaster && strtolower($testMaster['private_key']) === strtolower($expectedMaster);
                $this->line(sprintf('   %-15s: %s %s', $hmacSeed, substr($testMaster['private_key'], 0, 16) . '...', $match ? '✅ MATCH!' : ''));
            }
            $this->newLine();

            // Summary
            $this->info('=== Summary ===');
            $this->line('Mnemonic: ' . $mnemonic);
            $this->line('Entropy: ' . $entropy);
            $this->line('Seed: ' . substr($seed1Hex, 0, 32) . '...');
            $this->line('Master private: ' . $master['private_key']);
            
            if ($expectedMaster && strtolower($master['private_key']) !== strtolower($expectedMaster)) {
                $this->newLine();
                $this->warn('⚠️  Master keys do not match!');
                $this->line('This means TokenPocket is using a DIFFERENT SEED.');
                $this->line('');
                $this->line('Possible causes:');
                $this->line('1. Different mnemonic phrase (check for typos, extra spaces, etc.)');
                $this->line('2. Different passphrase (even if empty, encoding might differ)');
                $this->line('3. Different BIP39 implementation');
                $this->line('');
                $this->line('Please verify:');
                $this->line('- The exact mnemonic phrase in TokenPocket');
                $this->line('- Whether there is a passphrase set');
                $this->line('- The exact master private key in TokenPocket');
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getTraceAsString());
            return 1;
        }
    }
}

