<?php

namespace App\Console\Commands;

use App\Models\Withdrawal;
use App\Models\User;
use App\Services\Withdraw\WithdrawService;
use App\Services\Tron\TronWithdrawalService;
use App\Services\Assets\AssetsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WithdrawReview extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'withdraw:review 
                            {--list : List all pending withdrawals}
                            {--id= : Withdrawal ID to review}
                            {--action= : Action (approve/reject/process)}
                            {--note= : Review note/comment}
                            {--txid= : Transaction ID (for process action)}
                            {--verify-amount : Verify amount before processing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Review and process withdrawal requests';

    /**
     * Execute the console command.
     */
    public function handle(
        WithdrawService $withdrawService,
        ?TronWithdrawalService $tronWithdrawalService = null,
        AssetsService $assetsService = null
    ): int {
        if ($this->option('list')) {
            return $this->listWithdrawals();
        }

        $id = $this->option('id');
        if (!$id) {
            $this->error('Please provide --id option or use --list to see all withdrawals');
            return Command::FAILURE;
        }

        $action = $this->option('action');
        if (!$action) {
            $this->error('Please provide --action option (approve/reject/process)');
            return Command::FAILURE;
        }

        $withdrawal = Withdrawal::with('user')->findOrFail($id);

        $this->info("📋 Withdrawal Details:");
        $this->displayWithdrawal($withdrawal);
        $this->newLine();

        switch ($action) {
            case 'approve':
                return $this->approveWithdrawal($withdrawal, $withdrawService);
            case 'reject':
                return $this->rejectWithdrawal($withdrawal, $withdrawService);
            case 'process':
                return $this->processWithdrawal($withdrawal, $withdrawService, $tronWithdrawalService, $assetsService);
            default:
                $this->error("Invalid action: {$action}. Use approve/reject/process");
                return Command::FAILURE;
        }
    }

    /**
     * List all withdrawals.
     */
    private function listWithdrawals(): int
    {
        $this->info("📋 All Withdrawal Requests:");
        $this->newLine();

        $withdrawals = Withdrawal::with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($withdrawals->isEmpty()) {
            $this->warn('No withdrawals found.');
            return Command::SUCCESS;
        }

        $tableData = $withdrawals->map(function ($w) {
            $amount = $w->amount_request instanceof \App\Support\Decimal 
                ? $w->amount_request->toFixed(6) 
                : (string) $w->amount_request;
            return [
                $w->id,
                $w->user_id,
                $w->user->email ?? 'N/A',
                $amount,
                $w->currency,
                $w->status,
                substr($w->to_address, 0, 20) . '...',
                $w->txid ? substr($w->txid, 0, 16) . '...' : '-',
                $w->created_at->format('Y-m-d H:i:s'),
            ];
        })->toArray();

        $this->table(
            ['ID', 'User ID', 'Email', 'Amount', 'Currency', 'Status', 'To Address', 'TXID', 'Created'],
            $tableData
        );

        $this->newLine();
        $this->info("Status Legend:");
        $this->line("  pending   - Waiting for review");
        $this->line("  approved  - Approved, waiting for transfer");
        $this->line("  rejected  - Rejected by admin");
        $this->line("  paid      - Transfer completed");
        $this->line("  failed    - Transfer failed");

        return Command::SUCCESS;
    }

    /**
     * Display withdrawal details.
     */
    private function displayWithdrawal(Withdrawal $withdrawal): void
    {
        $amount = $withdrawal->amount_request instanceof \App\Support\Decimal 
            ? $withdrawal->amount_request->toFixed(6) 
            : (string) $withdrawal->amount_request;
        $fee = $withdrawal->fee instanceof \App\Support\Decimal 
            ? $withdrawal->fee->toFixed(6) 
            : (string) $withdrawal->fee;
        $actual = $withdrawal->amount_actual instanceof \App\Support\Decimal 
            ? $withdrawal->amount_actual->toFixed(6) 
            : (string) $withdrawal->amount_actual;

        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $withdrawal->id],
                ['User ID', $withdrawal->user_id],
                ['User Email', $withdrawal->user->email ?? 'N/A'],
                ['Currency', $withdrawal->currency],
                ['Amount Request', $amount],
                ['Fee', $fee],
                ['Amount Actual', $actual],
                ['Status', $withdrawal->status],
                ['To Address', $withdrawal->to_address],
                ['Chain', $withdrawal->chain ?? 'N/A'],
                ['TXID', $withdrawal->txid ?? '-'],
                ['Review Note', $withdrawal->review_note ?? '-'],
                ['Reviewed At', $withdrawal->reviewed_at ? $withdrawal->reviewed_at->format('Y-m-d H:i:s') : '-'],
                ['Created At', $withdrawal->created_at->format('Y-m-d H:i:s')],
            ]
        );
    }

    /**
     * Approve withdrawal.
     */
    private function approveWithdrawal(Withdrawal $withdrawal, WithdrawService $withdrawService): int
    {
        if ($withdrawal->status !== 'pending') {
            $this->error("Withdrawal status is '{$withdrawal->status}', cannot approve. Only 'pending' withdrawals can be approved.");
            return Command::FAILURE;
        }

        $note = $this->option('note') ?? $this->ask('Review note (optional)', '');

        try {
            DB::transaction(function () use ($withdrawal, $withdrawService, $note) {
                $withdrawService->approve($withdrawal->id);
                
                $withdrawal->update([
                    'review_note' => $note,
                    'reviewed_at' => now(),
                    'reviewed_by' => 1, // System user ID, you can change this
                ]);
            });

            $this->info("✅ Withdrawal #{$withdrawal->id} approved successfully!");
            $this->info("   Note: " . ($note ?: 'No note'));
            $this->newLine();
            $this->info("Next step: Use --action=process to transfer funds");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Failed to approve withdrawal: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Reject withdrawal.
     */
    private function rejectWithdrawal(Withdrawal $withdrawal, WithdrawService $withdrawService): int
    {
        if ($withdrawal->status !== 'pending') {
            $this->error("Withdrawal status is '{$withdrawal->status}', cannot reject. Only 'pending' withdrawals can be rejected.");
            return Command::FAILURE;
        }

        $note = $this->option('note');
        if (!$note) {
            $note = $this->ask('Rejection reason (required)', '');
            if (empty($note)) {
                $this->error('Rejection reason is required');
                return Command::FAILURE;
            }
        }

        try {
            DB::transaction(function () use ($withdrawal, $withdrawService, $note) {
                $withdrawService->reject($withdrawal->id);
                
                // Update review fields after rejection
                $withdrawal->fresh()->update([
                    'review_note' => $note,
                    'reviewed_at' => now(),
                    'reviewed_by' => 1, // System user ID
                ]);
            });

            $this->info("✅ Withdrawal #{$withdrawal->id} rejected successfully!");
            $this->info("   Reason: {$note}");
            $this->info("   Frozen balance has been unfrozen and returned to user.");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Failed to reject withdrawal: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Process withdrawal (transfer funds).
     */
    private function processWithdrawal(
        Withdrawal $withdrawal,
        WithdrawService $withdrawService,
        ?TronWithdrawalService $tronWithdrawalService,
        ?AssetsService $assetsService
    ): int {
        if ($withdrawal->status !== 'approved') {
            $this->error("Withdrawal status is '{$withdrawal->status}', cannot process. Only 'approved' withdrawals can be processed.");
            return Command::FAILURE;
        }

        // Verify amount if requested
        if ($this->option('verify-amount') && $assetsService) {
            $this->info("🔍 Verifying amount...");
            $totalBalance = $assetsService->getTotalBalance($withdrawal->user_id);
            $amountRequest = $withdrawal->amount_request instanceof \App\Support\Decimal 
                ? $withdrawal->amount_request 
                : \App\Support\Decimal::of($withdrawal->amount_request);
            
            if ($totalBalance->lessThan($amountRequest)) {
                $this->error("❌ Amount verification failed!");
                $this->error("   Requested: {$amountRequest->toFixed(6)}");
                $this->error("   Available: {$totalBalance->toFixed(6)}");
                return Command::FAILURE;
            }
            $this->info("✅ Amount verification passed");
            $this->newLine();
        }

        $txid = $this->option('txid');
        $note = $this->option('note') ?? '';

        // If TRC20 USDT and no txid provided, try to send automatically
        if ($withdrawal->chain === 'TRC20' && $withdrawal->currency === 'USDT' && empty($txid)) {
            if (!$tronWithdrawalService) {
                $this->error('TronWithdrawalService not available. Please provide --txid manually.');
                return Command::FAILURE;
            }

            $this->info("💸 Sending USDT from hot wallet...");
            $this->info("   To: {$withdrawal->to_address}");
            $amountActual = $withdrawal->amount_actual instanceof \App\Support\Decimal 
                ? (float) $withdrawal->amount_actual->toFixed(6) 
                : (float) $withdrawal->amount_actual;
            $this->info("   Amount: {$amountActual} USDT");
            
            if (!$this->confirm('Continue with transfer?', true)) {
                $this->info('Transfer cancelled.');
                return Command::FAILURE;
            }

            try {
                $txid = $tronWithdrawalService->sendFromHotWallet(
                    $withdrawal->to_address,
                    $amountActual
                );

                if (empty($txid)) {
                    throw new \Exception('Failed to send transaction. TXID is empty.');
                }

                $this->info("✅ Transaction sent successfully!");
                $this->info("   TXID: {$txid}");
                
                if (empty($note)) {
                    $note = "Transfer completed successfully. TXID: {$txid}";
                } else {
                    $note .= " | TXID: {$txid}";
                }
            } catch (\Exception $e) {
                $this->error("❌ Transfer failed: " . $e->getMessage());
                
                // Update withdrawal with failure status
                try {
                    $withdrawal->update([
                        'status' => 'failed',
                        'review_note' => "Transfer failed: " . $e->getMessage() . ($note ? " | Note: {$note}" : ''),
                        'reviewed_at' => now(),
                    ]);
                } catch (\Exception $updateError) {
                    $this->error("Failed to update withdrawal status: " . $updateError->getMessage());
                }
                
                return Command::FAILURE;
            }
        } else {
            // Manual txid provided or non-TRC20
            if (empty($txid)) {
                $txid = $this->ask('Transaction ID (TXID)', '');
                if (empty($txid)) {
                    $this->error('TXID is required for non-TRC20 withdrawals or when not auto-sending');
                    return Command::FAILURE;
                }
            }

            if (empty($note)) {
                $note = "Transfer completed. TXID: {$txid}";
            } else {
                $note .= " | TXID: {$txid}";
            }
        }

        // Mark as paid
        try {
            DB::transaction(function () use ($withdrawal, $withdrawService, $txid, $note) {
                $withdrawService->markPaid($withdrawal->id, $txid);
                
                $withdrawal->update([
                    'review_note' => $note,
                    'reviewed_at' => now(),
                    'reviewed_by' => 1, // System user ID
                ]);
            });

            $this->info("✅ Withdrawal #{$withdrawal->id} marked as paid successfully!");
            $this->info("   TXID: {$txid}");
            $this->info("   Note: {$note}");
            $this->info("   User balance has been debited.");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Failed to mark withdrawal as paid: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

