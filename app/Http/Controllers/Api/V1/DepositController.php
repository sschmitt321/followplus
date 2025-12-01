<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\TronDeposit;
use App\Services\Deposit\DepositService;
use App\Services\Tron\TronWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepositController extends Controller
{
    public function __construct(
        private DepositService $depositService,
        private TronWalletService $tronWalletService
    ) {
    }

    /**
     * Get deposit history.
     * 
     * Returns paginated list of user's deposit records. Includes:
     * - All deposits from deposits table (confirmed and pending)
     * - Pending/confirmed deposits from tron_deposits table that haven't been synced to deposits table yet
     * 
     * @param Request $request Query parameters for filtering
     * @param int|null $request->page Optional. Page number for pagination (default: 1)
     * 
     * @return JsonResponse Returns paginated deposit list with metadata
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $page = $request->get('page', 1);
        $perPage = 20;

        // Get deposits from deposits table
        $deposits = Deposit::where('user_id', $user->id)
            ->get()
            ->map(function ($deposit) {
                return [
                    'id' => $deposit->id,
                    'currency' => $deposit->currency,
                    'amount' => $deposit->amount->toFixed(6),
                    'status' => $deposit->status,
                    'txid' => $deposit->txid,
                    'confirmed_at' => $deposit->confirmed_at?->toIso8601String(),
                    'created_at' => $deposit->created_at->toIso8601String(),
                ];
            });

        // Get pending/confirmed deposits from tron_deposits that haven't been synced to deposits table
        // These are deposits that are still waiting for confirmations or have been confirmed but not yet credited
        $tronDepositsTxids = $deposits->pluck('txid')->filter()->toArray();
        
        $tronDeposits = TronDeposit::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where(function ($query) use ($tronDepositsTxids) {
                // Only include tron_deposits that don't have a corresponding deposit record
                if (!empty($tronDepositsTxids)) {
                    $query->whereNotIn('txid', $tronDepositsTxids);
                }
            })
            ->get()
            ->map(function ($tronDeposit) {
                return [
                    'id' => 'tron_' . $tronDeposit->id, // Prefix to avoid ID conflicts with deposits table
                    'currency' => $tronDeposit->token_symbol ?? 'USDT',
                    'amount' => $tronDeposit->amount->toFixed(6),
                    'status' => 'pending', // Show as pending until synced to deposits table and credited
                    'txid' => $tronDeposit->txid,
                    'confirmed_at' => null, // Not confirmed in deposits table yet
                    'created_at' => $tronDeposit->created_at->toIso8601String(),
                    'confirmations' => $tronDeposit->confirmations,
                    'required_confirmations' => $tronDeposit->required_confirmations,
                ];
            });

        // Merge and sort by created_at descending
        $allDeposits = $deposits->concat($tronDeposits)
            ->sortByDesc('created_at')
            ->values();

        // Manual pagination
        $total = $allDeposits->count();
        $totalPages = (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedDeposits = $allDeposits->slice($offset, $perPage)->values();

        return response()->json([
            'deposits' => $paginatedDeposits,
            'pagination' => [
                'current_page' => (int) $page,
                'total_pages' => $totalPages,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Manual apply deposit (for testing/admin).
     * 
     * Creates and immediately confirms a deposit. Amount will be credited to user's spot account.
     * This endpoint is mainly for testing purposes.
     * 
     * @param Request $request
     * @param string $request->amount Required. Deposit amount as string (e.g., "100.50"). Must be >= 0.
     * @param string $request->currency Required. Currency code (e.g., "USDT", "BTC"). Must exist in currencies table.
     * 
     * @return JsonResponse Returns deposit record with status "confirmed"
     * 
     * Request example:
     * {
     *   "amount": "1000.00",
     *   "currency": "USDT"
     * }
     */
    public function manualApply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|string|min:0', // Deposit amount (string format, e.g., "100.50", must be >= 0)
            'currency' => 'required|string|exists:currencies,name', // Currency code (e.g., "USDT", "BTC", must exist in system)
        ]);

        try {
            $deposit = $this->depositService->manualApply(
                auth()->id(),
                $validated['currency'],
                $validated['amount']
            );

            return response()->json([
                'message' => 'Deposit applied successfully',
                'deposit' => [
                    'id' => $deposit->id,
                    'currency' => $deposit->currency,
                    'amount' => $deposit->amount->toFixed(6),
                    'status' => $deposit->status,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get Tron deposit address.
     * 
     * Returns the user's TRC20 USDT deposit address. If the user doesn't have one yet,
     * a new address will be generated and assigned.
     * 
     * @return JsonResponse Returns deposit address information
     */
    public function getTronAddress(): JsonResponse
    {
        try {
            $user = auth()->user();
            $address = $this->tronWalletService->getOrCreateDepositAddress($user->id);

            return response()->json([
                'address' => $address,
                'chain' => 'TRC20',
                'currency' => 'USDT',
                'qr_code' => null, // TODO: Generate QR code if needed
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
