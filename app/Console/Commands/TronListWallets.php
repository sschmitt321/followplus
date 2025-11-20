<?php

namespace App\Console\Commands;

use App\Models\UserTronWallet;
use App\Services\Tron\TronHdWalletService;
use App\Services\Tron\TronWalletService;
use Illuminate\Console\Command;

class TronListWallets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tron:list-wallets 
                            {--user-id= : Filter by specific user ID}
                            {--show-private-key : Show decrypted private keys (use with caution!)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all Tron wallets (HD-derived or random addresses)';

    /**
     * Execute the console command.
     */
    public function handle(
        TronHdWalletService $hdWalletService,
        TronWalletService $walletService
    ): int {
        $userId = $this->option('user-id');
        $showPrivateKey = $this->option('show-private-key');

        // Check HD wallet status
        $isInitialized = $hdWalletService->isInitialized();
        $this->info('HD Wallet Status: ' . ($isInitialized ? '✅ Initialized' : '❌ Not Initialized'));
        
        if ($isInitialized) {
            $currentIndex = $hdWalletService->getCurrentDerivationIndex();
            $this->info("Current Derivation Index: {$currentIndex}");
        }
        
        $this->newLine();

        // Query wallets
        $query = UserTronWallet::with('user:id,email');
        
        if ($userId) {
            $query->where('user_id', $userId);
        }

        $wallets = $query->orderBy('derivation_index')->get();

        if ($wallets->isEmpty()) {
            $this->warn('No wallets found.');
            return Command::SUCCESS;
        }

        // Display wallets
        $headers = ['User ID', 'Email', 'Address', 'Derivation Index', 'Created At'];
        $rows = [];

        foreach ($wallets as $wallet) {
            $rows[] = [
                $wallet->user_id,
                $wallet->user->email ?? 'N/A',
                $wallet->tron_address,
                $wallet->derivation_index > 0 ? $wallet->derivation_index : ($isInitialized ? 'HD (pending)' : 'Random'),
                $wallet->created_at->format('Y-m-d H:i:s'),
            ];
        }

        $this->table($headers, $rows);

        // Show private keys if requested
        if ($showPrivateKey) {
            $this->newLine();
            $this->warn('⚠️  WARNING: Private keys are sensitive information!');
            
            if (!$this->confirm('Do you want to display private keys?', false)) {
                return Command::SUCCESS;
            }

            $this->newLine();
            $this->info('Private Keys:');
            $this->line(str_repeat('-', 80));

            foreach ($wallets as $wallet) {
                try {
                    $privateKey = $walletService->decryptPrivateKey($wallet->encrypted_private_key);
                    $email = $wallet->user->email ?? 'N/A';
                    $this->line("User ID: {$wallet->user_id} ({$email})");
                    $this->line("Address: {$wallet->tron_address}");
                    $this->line("Private Key: {$privateKey}");
                    $this->line(str_repeat('-', 80));
                } catch (\Exception $e) {
                    $this->error("Failed to decrypt private key for user {$wallet->user_id}: {$e->getMessage()}");
                }
            }
        }

        return Command::SUCCESS;
    }
}

