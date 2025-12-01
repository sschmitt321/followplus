<?php

namespace App\Console\Commands;

use App\Services\Tron\TronHdWalletService;
use Illuminate\Console\Command;

class TronInitHdWallet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tron:init-hd-wallet 
                            {--seed= : Master seed (mnemonic phrase or hex seed). If not provided, will generate a new one.}
                            {--force : Force re-initialization even if already initialized}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize HD wallet with master seed for Tron address derivation';

    /**
     * Execute the console command.
     */
    public function handle(TronHdWalletService $hdWalletService): int
    {
        // Prevent force re-initialization in production environment
        if ($this->option('force') && app()->environment('production')) {
            $this->error('❌ Force re-initialization is disabled in production environment for security reasons.');
            $this->newLine();
            $this->warn('⚠️  Re-initializing HD wallet in production will:');
            $this->line('   - Overwrite the existing master seed');
            $this->line('   - Make all previously derived addresses inaccessible');
            $this->line('   - Potentially cause loss of funds');
            $this->newLine();
            $this->info('If you need to re-initialize, please:');
            $this->line('   1. Ensure you have a backup of the current master seed');
            $this->line('   2. Manually update the database if necessary');
            $this->line('   3. Contact system administrator for assistance');
            return Command::FAILURE;
        }

        // Check if already initialized
        if ($hdWalletService->isInitialized() && !$this->option('force')) {
            $this->error('HD wallet is already initialized. Use --force to re-initialize.');
            if (app()->environment('production')) {
                $this->warn('Note: --force is disabled in production environment.');
            }
            return Command::FAILURE;
        }

        // Get or generate master seed
        $masterSeed = $this->option('seed');
        
        if (empty($masterSeed)) {
            // Generate a new mnemonic seed (24 words)
            // TODO: Use actual BIP39 mnemonic generation library
            // For now, generate a random hex seed
            $masterSeed = bin2hex(random_bytes(32));
            $this->info('Generated new master seed (hex format):');
            $this->line($masterSeed);
            $this->warn('⚠️  IMPORTANT: Save this seed securely! You will need it to recover all addresses.');
        } else {
            $this->info('Using provided master seed...');
        }

        // Confirm initialization
        if (!$this->option('force')) {
            if (!$this->confirm('Are you sure you want to initialize the HD wallet?', true)) {
                $this->info('Initialization cancelled.');
                return Command::SUCCESS;
            }
        }

        // Initialize HD wallet
        $this->info('Initializing HD wallet...');
        
        $force = $this->option('force');
        if ($hdWalletService->initialize($masterSeed, $force)) {
            $this->info('✅ HD wallet initialized successfully!');
            $this->newLine();
            $this->info('Next steps:');
            $this->line('1. New user registrations will automatically get HD-derived addresses');
            $this->line('2. Existing users can get addresses via: GET /api/v1/deposits/tron-address');
            $this->line('3. Current derivation index: ' . $hdWalletService->getCurrentDerivationIndex());
            
            if (empty($this->option('seed'))) {
                $this->newLine();
                $this->warn('⚠️  Make sure to backup the master seed shown above!');
            }
            
            return Command::SUCCESS;
        } else {
            $this->error('Failed to initialize HD wallet. Check logs for details.');
            return Command::FAILURE;
        }
    }
}

