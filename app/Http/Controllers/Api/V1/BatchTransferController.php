<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AddressLiquidity;
use App\Services\Tron\TronBatchTransferService;
use App\Services\Tron\TronLiquidityService;
use App\Services\Tron\TronTopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class BatchTransferController extends Controller
{
    public function __construct(
        private TronLiquidityService $liquidityService,
        private TronBatchTransferService $transferService,
        private TronTopupService $topupService
    ) {
    }

    /**
     * Get addresses list with filters.
     * 
     * @param Request $request
     * @param string|null $request->status Optional. Filter by status
     * @param int|null $request->page Optional. Page number
     * 
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = AddressLiquidity::query();

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        $perPage = $request->get('per_page', 20);
        $addresses = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'addresses' => $addresses->map(function ($address) {
                return [
                    'id' => $address->id,
                    'address' => $address->address,
                    'trx_balance' => $address->trx_balance->toFixed(8),
                    'usdt_balance' => $address->usdt_balance->toFixed(8),
                    'dt_balance' => $address->dt_balance->toFixed(8),
                    'status' => $address->status,
                    'gas_strategy' => $address->gas_strategy,
                    'last_checked_at' => $address->last_checked_at?->toIso8601String(),
                    'last_tx_hash' => $address->last_tx_hash,
                    'last_topup_hash' => $address->last_topup_hash,
                    'error_code' => $address->error_code,
                    'error_message' => $address->error_message,
                    'created_at' => $address->created_at->toIso8601String(),
                    'updated_at' => $address->updated_at->toIso8601String(),
                ];
            }),
            'pagination' => [
                'current_page' => $addresses->currentPage(),
                'total_pages' => $addresses->lastPage(),
                'total' => $addresses->total(),
            ],
        ]);
    }

    /**
     * Sync addresses from user_tron_wallets table.
     * 
     * @return JsonResponse
     */
    public function syncAddresses(): JsonResponse
    {
        $stats = $this->liquidityService->syncAddressesFromWallets();

        return response()->json([
            'message' => 'Addresses synced successfully',
            'stats' => $stats,
        ], 200);
    }

    /**
     * Import addresses manually (for additional addresses not in user_tron_wallets).
     * 
     * @param Request $request
     * @param array $request->addresses Required. Array of addresses
     * 
     * @return JsonResponse
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'addresses' => 'required|array',
            'addresses.*' => 'required|string|max:128',
        ]);

        $stats = $this->liquidityService->importAddresses($validated['addresses']);

        return response()->json([
            'message' => 'Addresses imported successfully',
            'stats' => $stats,
        ], 200);
    }

    /**
     * Manually trigger balance scan.
     * 
     * @param Request $request
     * @param int|null $request->limit Optional. Limit number of addresses
     * @param string|null $request->status Optional. Comma-separated statuses
     * 
     * @return JsonResponse
     */
    public function scanBalances(Request $request): JsonResponse
    {
        $limit = $request->get('limit') ? (int) $request->get('limit') : null;
        $statuses = $request->get('status') ? explode(',', $request->get('status')) : null;

        $stats = $this->liquidityService->scanBalances($statuses, $limit);

        return response()->json([
            'message' => 'Balance scan completed',
            'stats' => $stats,
        ], 200);
    }

    /**
     * Manually trigger USDT transfers.
     * 
     * @param Request $request
     * @param int|null $request->limit Optional. Limit number of addresses
     * 
     * @return JsonResponse
     */
    public function transferUsdt(Request $request): JsonResponse
    {
        $limit = $request->get('limit') ? (int) $request->get('limit') : null;

        $stats = $this->transferService->processTransfers($limit);

        return response()->json([
            'message' => 'USDT transfers completed',
            'stats' => $stats,
        ], 200);
    }

    /**
     * Manually trigger TRX topups.
     * 
     * @param Request $request
     * @param int|null $request->limit Optional. Limit number of addresses
     * 
     * @return JsonResponse
     */
    public function topupTrx(Request $request): JsonResponse
    {
        $limit = $request->get('limit') ? (int) $request->get('limit') : null;

        $stats = $this->topupService->processTopups($limit);

        return response()->json([
            'message' => 'TRX topups completed',
            'stats' => $stats,
        ], 200);
    }

    /**
     * Get statistics summary.
     * 
     * @return JsonResponse
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total' => AddressLiquidity::count(),
            'by_status' => AddressLiquidity::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'total_trx' => AddressLiquidity::sum('trx_balance'),
            'total_usdt' => AddressLiquidity::sum('usdt_balance'),
            'ready_to_transfer' => AddressLiquidity::where('status', AddressLiquidity::STATUS_READY_TO_TRANSFER)->count(),
            'need_topup' => AddressLiquidity::where('status', AddressLiquidity::STATUS_NEED_TRX_TOPUP)->count(),
            'failed' => AddressLiquidity::where('status', AddressLiquidity::STATUS_FAILED)->count(),
        ];

        return response()->json($stats, 200);
    }
}
