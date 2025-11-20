<?php

namespace App\Console\Commands;

use App\Models\UserTronWallet;
use App\Services\Tron\TronWalletService;
use App\Support\TronHelper;
use Illuminate\Console\Command;

class TronFixAddresses extends Command
{
    protected $signature = 'tron:fix-addresses 
                            {--user= : Fix address for specific user ID}
                            {--dry-run : Show what would be changed without actually updating}';

    protected $description = 'Fix Tron addresses for users using correct address generation algorithm';

    public function handle(TronWalletService $walletService): int
    {
        $userId = $this->option('user');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - No changes will be made');
        }

        $query = UserTronWallet::query();
        if ($userId) {
            $query->where('user_id', $userId);
        }

        $wallets = $query->get();

        if ($wallets->isEmpty()) {
            $this->info('No wallets found to fix.');
            return 0;
        }

        $this->info("Found {$wallets->count()} wallet(s) to check...\n");

        $table = [];
        $fixed = 0;
        $errors = 0;

        foreach ($wallets as $wallet) {
            try {
                // Decrypt private key
                $privateKey = $walletService->decryptPrivateKey($wallet->encrypted_private_key);
                
                // Generate correct address
                $result = TronHelper::generateAddressFromPrivateKey($privateKey);
                $correctAddress = $result['address'];
                
                if ($wallet->tron_address !== $correctAddress) {
                    $table[] = [
                        'user_id' => $wallet->user_id,
                        'old_address' => $wallet->tron_address,
                        'new_address' => $correctAddress,
                        'status' => $dryRun ? 'Would Fix' : 'Fixed',
                    ];

                    if (!$dryRun) {
                        $wallet->update(['tron_address' => $correctAddress]);
                        $fixed++;
                    } else {
                        $fixed++;
                    }
                } else {
                    $table[] = [
                        'user_id' => $wallet->user_id,
                        'old_address' => $wallet->tron_address,
                        'new_address' => $correctAddress,
                        'status' => 'OK',
                    ];
                }
            } catch (\Exception $e) {
                $table[] = [
                    'user_id' => $wallet->user_id,
                    'old_address' => $wallet->tron_address,
                    'new_address' => 'ERROR',
                    'status' => $e->getMessage(),
                ];
                $errors++;
                $this->error("Error fixing wallet for user {$wallet->user_id}: " . $e->getMessage());
            }
        }

        // Display results
        $this->table(
            ['User ID', 'Old Address', 'New Address', 'Status'],
            array_map(function ($row) {
                return [
                    $row['user_id'],
                    $row['old_address'],
                    $row['new_address'],
                    $row['status'],
                ];
            }, $table)
        );

        $this->newLine();
        if ($dryRun) {
            $this->info("Would fix {$fixed} address(es). {$errors} error(s).");
        } else {
            $this->info("✅ Fixed {$fixed} address(es). {$errors} error(s).");
        }

        return 0;
    }
}
