<?php

namespace App\Console\Commands;

use App\Models\TronDeposit;
use App\Models\UserTronWallet;
use App\Services\Tron\TronDepositService;
use App\Services\Tron\TronNodeClient;
use App\Services\Tron\TronUsdtContract;
use Illuminate\Console\Command;

class TronTestDeposit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tron:test-deposit {--user-id= : User ID to test} {--address= : Tron address to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test deposit scanning and verification on Shasta testnet';

    /**
     * Execute the console command.
     */
    public function handle(
        TronDepositService $depositService,
        TronNodeClient $nodeClient,
        TronUsdtContract $usdtContract
    ): int {
        $this->info('🧪 Testing Tron Deposit Functionality');
        $this->newLine();

        // Step 1: Check node connection
        $this->info('Step 1: Checking node connection...');
        $blockNumber = $nodeClient->getCurrentBlockNumber();
        if ($blockNumber > 0) {
            $this->info("✅ Connected to node. Current block: {$blockNumber}");
        } else {
            $this->error('❌ Failed to connect to node. Please check TRON_NODE_URL configuration.');
            return Command::FAILURE;
        }
        $this->newLine();

        // Step 2: Check USDT contract configuration
        $this->info('Step 2: Checking USDT contract configuration...');
        $contractAddress = config('services.tron.usdt_contract');
        $this->info("USDT Contract: {$contractAddress}");
        $this->newLine();

        // Step 3: Get user address
        $userId = $this->option('user-id');
        $address = $this->option('address');

        if (!$address && !$userId) {
            // Show all user wallets
            $this->info('Step 3: Available user wallets:');
            $wallets = UserTronWallet::with('user')->get();
            if ($wallets->isEmpty()) {
                $this->warn('⚠️  No user wallets found. Users need to generate wallets first.');
                $this->info('   You can generate a wallet by calling the /api/v1/wallets endpoint or using TronHdWalletService.');
            } else {
                $this->table(
                    ['User ID', 'Email', 'Tron Address', 'Derivation Index'],
                    $wallets->map(function ($wallet) {
                        return [
                            $wallet->user_id,
                            $wallet->user->email ?? 'N/A',
                            $wallet->tron_address,
                            $wallet->derivation_index,
                        ];
                    })->toArray()
                );
            }
            $this->newLine();
        } else {
            if ($userId && !$address) {
                $wallet = UserTronWallet::where('user_id', $userId)->first();
                if (!$wallet) {
                    $this->error("❌ No wallet found for user ID: {$userId}");
                    return Command::FAILURE;
                }
                $address = $wallet->tron_address;
                $this->info("Step 3: Using wallet for user ID {$userId}");
            }

            // Step 4: Check USDT balance
            $this->info("Step 4: Checking USDT balance for address: {$address}");
            $balance = $usdtContract->getBalance($address);
            $this->info("USDT Balance: {$balance} USDT");
            $this->newLine();

            // Step 5: Check TRX balance
            $this->info("Step 5: Checking TRX balance for address: {$address}");
            $trxBalance = $nodeClient->getTrxBalance($address);
            $this->info("TRX Balance: {$trxBalance} TRX");
            if ($trxBalance < 0.1) {
                $this->warn('⚠️  Low TRX balance. You may need TRX for gas fees.');
                $this->info('   Get test TRX from: https://www.trongrid.io/faucet');
            }
            $this->newLine();
        }

        // Step 6: Scan for deposits
        $this->info('Step 6: Scanning for new deposits...');
        $scannedCount = $depositService->scanNewDeposits();
        if ($scannedCount > 0) {
            $this->info("✅ Found {$scannedCount} new deposit(s)");
        } else {
            $this->info('ℹ️  No new deposits found (scanning last 1 hour)');
        }
        $this->newLine();

        // Step 7: Update confirmations
        $this->info('Step 7: Updating deposit confirmations...');
        $creditedCount = $depositService->updateConfirmationsAndCredit();
        if ($creditedCount > 0) {
            $this->info("✅ Credited {$creditedCount} deposit(s)");
        } else {
            $this->info('ℹ️  No deposits ready to credit');
        }
        $this->newLine();

        // Step 8: Show recent deposits
        $this->info('Step 8: Recent deposits:');
        $deposits = TronDeposit::orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        if ($deposits->isEmpty()) {
            $this->info('ℹ️  No deposits found');
        } else {
            $tableData = $deposits->map(function ($deposit) {
                return [
                    $deposit->id,
                    substr($deposit->txid, 0, 16) . '...',
                    substr($deposit->tron_address, 0, 10) . '...',
                    $deposit->amount . ' USDT',
                    $deposit->status,
                    "{$deposit->confirmations}/{$deposit->required_confirmations}",
                    $deposit->created_at->format('Y-m-d H:i:s'),
                ];
            })->toArray();

            $this->table(
                ['ID', 'TXID', 'Address', 'Amount', 'Status', 'Confirmations', 'Created At'],
                $tableData
            );
        }
        $this->newLine();

        // Summary
        $this->info('📊 Summary:');
        $totalDeposits = TronDeposit::count();
        $pendingDeposits = TronDeposit::where('status', 'pending')->count();
        $confirmedDeposits = TronDeposit::where('status', 'confirmed')->count();
        $creditedDeposits = TronDeposit::where('status', 'credited')->count();

        $this->info("Total deposits: {$totalDeposits}");
        $this->info("Pending: {$pendingDeposits}");
        $this->info("Confirmed: {$confirmedDeposits}");
        $this->info("Credited: {$creditedDeposits}");

        $this->newLine();
        $this->info('✅ Test completed!');

        return Command::SUCCESS;
    }
}

