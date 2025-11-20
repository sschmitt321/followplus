<?php

namespace App\Console\Commands;

use App\Models\TronHdWallet;
use App\Models\UserTronWallet;
use App\Services\Tron\TronHdWalletService;
use Illuminate\Console\Command;

class TronRecoverHdWallets extends Command
{
    protected $signature = 'tron:recover-hd-wallets 
                            {--index= : Specific index to recover}
                            {--max= : Maximum index to recover (default: next_derivation_index)}
                            {--verify : Verify against database addresses}
                            {--export : Export master seed (WARNING: sensitive data)}';

    protected $description = 'Recover all HD wallet addresses from master seed';

    public function handle(TronHdWalletService $hdService): int
    {
        if (!$hdService->isInitialized()) {
            $this->error('HD wallet not initialized. Please run: php artisan tron:init-hd-wallet');
            return 1;
        }

        $hdWallet = TronHdWallet::getInstance();
        $maxIndex = $this->option('max') ?? $hdWallet->next_derivation_index;
        $specificIndex = $this->option('index');
        $verify = $this->option('verify');
        $export = $this->option('export');

        // Export master seed if requested
        if ($export) {
            if (!$this->confirm('⚠️  WARNING: This will display the master seed. Continue?')) {
                return 0;
            }
            
            try {
                // Use reflection to call private decryptMasterSeed method
                $reflection = new \ReflectionClass($hdService);
                $decryptMethod = $reflection->getMethod('decryptMasterSeed');
                $decryptMethod->setAccessible(true);
                $masterSeed = $decryptMethod->invoke($hdService, $hdWallet->encrypted_master_seed);
                
                $this->info("\n📋 Master Seed (hex):");
                $this->line($masterSeed);
                $this->warn("\n⚠️  Keep this secure! Anyone with this seed can control all addresses.");
            } catch (\Exception $e) {
                $this->error('Failed to decrypt master seed: ' . $e->getMessage());
                return 1;
            }
        }

        // Recover addresses
        $this->info("\n🔑 Recovering HD wallet addresses...");
        $this->info("Derivation Path: m/44'/195'/0'/0/{index}");
        $this->info("Next Derivation Index: {$hdWallet->next_derivation_index}\n");

        $table = [];
        $startIndex = $specificIndex !== null ? (int)$specificIndex : 0;
        $endIndex = $specificIndex !== null ? (int)$specificIndex : ($maxIndex - 1);

        // Use reflection to access private methods
        $reflection = new \ReflectionClass($hdService);
        $decryptMethod = $reflection->getMethod('decryptMasterSeed');
        $decryptMethod->setAccessible(true);
        $deriveMethod = $reflection->getMethod('deriveAddress');
        $deriveMethod->setAccessible(true);
        
        // Decrypt master seed once
        try {
            $masterSeed = $decryptMethod->invoke($hdService, $hdWallet->encrypted_master_seed);
        } catch (\Exception $e) {
            $this->error('Failed to decrypt master seed: ' . $e->getMessage());
            return 1;
        }

        for ($i = $startIndex; $i <= $endIndex; $i++) {
            try {
                // Derive address using reflection
                $derived = $deriveMethod->invoke($hdService, $masterSeed, $i);

                $row = [
                    'index' => $i,
                    'address' => $derived['address'],
                    'private_key' => $derived['private_key'],
                ];

                // Verify against database if requested
                if ($verify) {
                    $dbWallet = UserTronWallet::where('derivation_index', $i)->first();
                    if ($dbWallet) {
                        $row['db_address'] = $dbWallet->tron_address;
                        $row['match'] = $dbWallet->tron_address === $derived['address'] ? '✅' : '❌';
                        $row['user_id'] = $dbWallet->user_id;
                    } else {
                        $row['db_address'] = 'N/A';
                        $row['match'] = 'N/A';
                        $row['user_id'] = 'N/A';
                    }
                }

                $table[] = $row;
            } catch (\Exception $e) {
                $this->error("Failed to recover index {$i}: " . $e->getMessage());
            }
        }

        // Display results
        $headers = ['Index', 'Address', 'Private Key'];
        if ($verify) {
            $headers = ['Index', 'User ID', 'Derived Address', 'DB Address', 'Match'];
        }

        $this->table($headers, array_map(function ($row) use ($verify) {
            if ($verify) {
                return [
                    $row['index'],
                    $row['user_id'] ?? 'N/A',
                    $row['address'],
                    $row['db_address'] ?? 'N/A',
                    $row['match'] ?? 'N/A',
                ];
            }
            return [
                $row['index'],
                $row['address'],
                $row['private_key'],
            ];
        }, $table));

        $this->info("\n✅ Recovery complete!");
        $this->warn("⚠️  Keep private keys secure. Do not share them publicly.");

        return 0;
    }
}

