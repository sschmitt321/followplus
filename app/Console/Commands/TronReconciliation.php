<?php

namespace App\Console\Commands;

use App\Models\TronDeposit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TronReconciliation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tron:reconciliation 
                            {--start= : Start date (Y-m-d H:i:s or Y-m-d)}
                            {--end= : End date (Y-m-d H:i:s or Y-m-d)}
                            {--user= : Filter by user ID}
                            {--status= : Filter by status (pending,confirmed,credited,failed)}
                            {--format=table : Output format (table, json, csv)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate reconciliation report for Tron deposits';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startDate = $this->option('start');
        $endDate = $this->option('end');
        $userId = $this->option('user');
        $status = $this->option('status');
        $format = $this->option('format');

        // Parse dates
        $start = $startDate ? $this->parseDate($startDate) : now()->startOfDay();
        $end = $endDate ? $this->parseDate($endDate)->endOfDay() : now()->endOfDay();

        $this->info("📊 Tron Deposit Reconciliation Report");
        $this->info("Period: {$start->format('Y-m-d H:i:s')} to {$end->format('Y-m-d H:i:s')}");
        $this->newLine();

        // Build query
        $query = TronDeposit::with('user')
            ->whereBetween('created_at', [$start, $end]);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $deposits = $query->orderBy('created_at', 'desc')->get();

        // Calculate statistics
        $totalCount = $deposits->count();
        $totalAmount = $deposits->reduce(function ($carry, $deposit) {
            $amount = $deposit->amount instanceof \App\Support\Decimal 
                ? $deposit->amount->toFloat() 
                : (float) $deposit->amount;
            return $carry + $amount;
        }, 0);
        $byStatus = $deposits->groupBy('status')->map(function ($group) {
            return [
                'count' => $group->count(),
                'amount' => $group->reduce(function ($carry, $deposit) {
                    $amount = $deposit->amount instanceof \App\Support\Decimal 
                        ? $deposit->amount->toFloat() 
                        : (float) $deposit->amount;
                    return $carry + $amount;
                }, 0),
            ];
        });

        // Display summary
        $this->info("📈 Summary:");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Deposits', $totalCount],
                ['Total Amount (USDT)', number_format($totalAmount, 6)],
                ['Pending', ($byStatus['pending']['count'] ?? 0) . ' (' . number_format($byStatus['pending']['amount'] ?? 0, 6) . ' USDT)'],
                ['Confirmed', ($byStatus['confirmed']['count'] ?? 0) . ' (' . number_format($byStatus['confirmed']['amount'] ?? 0, 6) . ' USDT)'],
                ['Credited', ($byStatus['credited']['count'] ?? 0) . ' (' . number_format($byStatus['credited']['amount'] ?? 0, 6) . ' USDT)'],
                ['Failed', ($byStatus['failed']['count'] ?? 0) . ' (' . number_format($byStatus['failed']['amount'] ?? 0, 6) . ' USDT)'],
            ]
        );
        $this->newLine();

        // Display detailed list
        if ($totalCount > 0) {
            $this->info("📋 Detailed List ({$totalCount} deposits):");
            $this->newLine();

            if ($format === 'json') {
                $this->line(json_encode($deposits->map(function ($deposit) {
                    $amount = $deposit->amount instanceof \App\Support\Decimal 
                        ? $deposit->amount->toFixed(6) 
                        : (string) $deposit->amount;
                    return [
                        'id' => $deposit->id,
                        'user_id' => $deposit->user_id,
                        'user_email' => $deposit->user->email ?? 'N/A',
                        'txid' => $deposit->txid,
                        'tron_address' => $deposit->tron_address,
                        'from_address' => $deposit->from_address,
                        'amount' => $amount,
                        'token_symbol' => $deposit->token_symbol,
                        'status' => $deposit->status,
                        'confirmations' => $deposit->confirmations,
                        'required_confirmations' => $deposit->required_confirmations,
                        'created_at' => $deposit->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $deposit->updated_at->format('Y-m-d H:i:s'),
                    ];
                }), JSON_PRETTY_PRINT));
            } elseif ($format === 'csv') {
                $this->line('ID,User ID,User Email,TXID,Tron Address,From Address,Amount,Token,Status,Confirmations,Required,Created At');
                foreach ($deposits as $deposit) {
                    $amount = $deposit->amount instanceof \App\Support\Decimal 
                        ? $deposit->amount->toFixed(6) 
                        : (string) $deposit->amount;
                    $this->line(sprintf(
                        '%d,%d,%s,%s,%s,%s,%s,%s,%s,%d,%d,%s',
                        $deposit->id,
                        $deposit->user_id,
                        $deposit->user->email ?? 'N/A',
                        $deposit->txid,
                        $deposit->tron_address,
                        $deposit->from_address,
                        $amount,
                        $deposit->token_symbol,
                        $deposit->status,
                        $deposit->confirmations,
                        $deposit->required_confirmations,
                        $deposit->created_at->format('Y-m-d H:i:s')
                    ));
                }
            } else {
                // Table format
                $tableData = $deposits->map(function ($deposit) {
                    $amount = $deposit->amount instanceof \App\Support\Decimal 
                        ? $deposit->amount->toFixed(6) 
                        : number_format((float) $deposit->amount, 6);
                    return [
                        $deposit->id,
                        $deposit->user_id,
                        $deposit->user->email ?? 'N/A',
                        substr($deposit->txid, 0, 16) . '...',
                        substr($deposit->tron_address, 0, 20) . '...',
                        substr($deposit->from_address, 0, 20) . '...',
                        $amount,
                        $deposit->token_symbol,
                        $deposit->status,
                        $deposit->confirmations . '/' . $deposit->required_confirmations,
                        $deposit->created_at->format('Y-m-d H:i:s'),
                    ];
                })->toArray();

                $this->table(
                    ['ID', 'User ID', 'Email', 'TXID', 'To Address', 'From Address', 'Amount', 'Token', 'Status', 'Confirms', 'Created'],
                    $tableData
                );
            }

            // Group by user
            $this->newLine();
            $this->info("👥 Summary by User:");
            $byUser = $deposits->groupBy('user_id')->map(function ($group, $userId) {
                $user = $group->first()->user;
                return [
                    'user_id' => $userId,
                    'email' => $user->email ?? 'N/A',
                    'count' => $group->count(),
                    'total_amount' => $group->reduce(function ($carry, $deposit) {
                        $amount = $deposit->amount instanceof \App\Support\Decimal 
                            ? $deposit->amount->toFloat() 
                            : (float) $deposit->amount;
                        return $carry + $amount;
                    }, 0),
                    'credited_amount' => $group->where('status', 'credited')->reduce(function ($carry, $deposit) {
                        $amount = $deposit->amount instanceof \App\Support\Decimal 
                            ? $deposit->amount->toFloat() 
                            : (float) $deposit->amount;
                        return $carry + $amount;
                    }, 0),
                ];
            })->values();

            $userTableData = $byUser->map(function ($user) {
                return [
                    $user['user_id'],
                    $user['email'],
                    $user['count'],
                    number_format($user['total_amount'], 6),
                    number_format($user['credited_amount'], 6),
                ];
            })->toArray();

            $this->table(
                ['User ID', 'Email', 'Deposits', 'Total Amount', 'Credited Amount'],
                $userTableData
            );
        } else {
            $this->warn("No deposits found in the specified period.");
        }

        return Command::SUCCESS;
    }

    /**
     * Parse date string to Carbon instance.
     */
    private function parseDate(string $date): \Carbon\Carbon
    {
        // Try parsing as datetime first
        try {
            return \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $date);
        } catch (\Exception $e) {
            // Try parsing as date only
            try {
                return \Carbon\Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
            } catch (\Exception $e) {
                // Try parsing as any format
                return \Carbon\Carbon::parse($date);
            }
        }
    }
}

