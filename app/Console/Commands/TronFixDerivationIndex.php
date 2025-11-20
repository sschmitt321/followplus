<?php

namespace App\Console\Commands;

use App\Models\TronHdWallet;
use App\Models\UserTronWallet;
use App\Services\Tron\TronHdWalletService;
use App\Services\Tron\TronWalletService;
use Illuminate\Console\Command;

class TronFixDerivationIndex extends Command
{
    protected $signature = 'tron:fix-derivation-index 
                            {--reassign : Reassign derivation indices and regenerate addresses from HD wallet}
                            {--dry-run : Show what would be changed without actually updating}';

    protected $description = 'Fix derivation indices for existing wallets. Use --reassign to regenerate addresses from HD wallet (WARNING: will change addresses!)';

    public function handle(TronHdWalletService $hdService, TronWalletService $walletService): int
    {
        if (!$hdService->isInitialized()) {
            $this->error('HD wallet not initialized. Please run: php artisan tron:init-hd-wallet');
            return 1;
        }

        $reassign = $this->option('reassign');
        $dryRun = $this->option('dry-run');

        if ($reassign && !$dryRun) {
            if (!$this->confirm('⚠️  WARNING: This will regenerate addresses from HD wallet. Existing addresses will change! Continue?')) {
                return 0;
            }
        }

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - No changes will be made');
        }

        $hdWallet = TronHdWallet::getInstance();
        $wallets = UserTronWallet::orderBy('user_id')->get();

        if ($wallets->isEmpty()) {
            $this->info('No wallets found.');
            return 0;
        }

        $this->info("Found {$wallets->count()} wallet(s).\n");
        $this->info("Current next_derivation_index: {$hdWallet->next_derivation_index}\n");

        // Use reflection to access private methods
        $reflection = new \ReflectionClass($hdService);
        $decryptMethod = $reflection->getMethod('decryptMasterSeed');
        $decryptMethod->setAccessible(true);
        $deriveMethod = $reflection->getMethod('deriveAddress');
        $deriveMethod->setAccessible(true);

        $masterSeed = $decryptMethod->invoke($hdService, $hdWallet->encrypted_master_seed);

        $table = [];
        $maxIndex = -1;

        foreach ($wallets as $wallet) {
            $dbPrivateKey = $walletService->decryptPrivateKey($wallet->encrypted_private_key);
            
            // Check if wallet is derived from HD wallet
            $derived = $deriveMethod->invoke($hdService, $masterSeed, $wallet->derivation_index);
            $isDerived = ($wallet->tron_address === $derived['address']);

            if ($reassign) {
                // Reassign derivation index sequentially
                $newIndex = $wallet->user_id - 1; // Simple sequential assignment
                $newDerived = $deriveMethod->invoke($hdService, $masterSeed, $newIndex);
                
                $table[] = [
                    'user_id' => $wallet->user_id,
                    'old_index' => $wallet->derivation_index,
                    'new_index' => $newIndex,
                    'old_address' => $wallet->tron_address,
                    'new_address' => $newDerived['address'],
                    'status' => $dryRun ? 'Would Reassign' : 'Reassigned',
                ];

                if (!$dryRun) {
                    $encryptedPk = $walletService->encryptPrivateKey($newDerived['private_key']);
                    $wallet->update([
                        'derivation_index' => $newIndex,
                        'tron_address' => $newDerived['address'],
                        'encrypted_private_key' => $encryptedPk,
                    ]);
                }

                $maxIndex = max($maxIndex, $newIndex);
            } else {
                // Just check and report
                $status = $isDerived ? '✅ Derived' : '❌ Not Derived (Random)';
                $table[] = [
                    'user_id' => $wallet->user_id,
                    'index' => $wallet->derivation_index,
                    'address' => $wallet->tron_address,
                    'derived_address' => $derived['address'],
                    'status' => $status,
                ];
            }
        }

        // Display results
        if ($reassign) {
            $this->table(
                ['User ID', 'Old Index', 'New Index', 'Old Address', 'New Address', 'Status'],
                array_map(function ($row) {
                    return [
                        $row['user_id'],
                        $row['old_index'],
                        $row['new_index'],
                        substr($row['old_address'], 0, 20) . '...',
                        substr($row['new_address'], 0, 20) . '...',
                        $row['status'],
                    ];
                }, $table)
            );

            if (!$dryRun && $maxIndex >= 0) {
                $hdWallet->update([
                    'next_derivation_index' => $maxIndex + 1,
                ]);
                $this->info("\n✅ Updated next_derivation_index to " . ($maxIndex + 1));
            } else if ($dryRun) {
                $this->info("\nWould update next_derivation_index to " . ($maxIndex + 1));
            }
        } else {
            $this->table(
                ['User ID', 'Index', 'Address', 'Derived Address', 'Status'],
                array_map(function ($row) {
                    return [
                        $row['user_id'],
                        $row['index'],
                        substr($row['address'], 0, 20) . '...',
                        substr($row['derived_address'], 0, 20) . '...',
                        $row['status'],
                    ];
                }, $table)
            );

            $this->warn("\n⚠️  Some wallets are not derived from HD wallet.");
            $this->info("Use --reassign to regenerate addresses from HD wallet (WARNING: addresses will change!)");
        }

        return 0;
    }
}

