<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Services\Assets\AssetsService;
use Illuminate\Http\JsonResponse;

class WalletsController extends Controller
{
    public function __construct(
        private AssetsService $assetsService
    ) {
    }

    /**
     * Get wallet information with deposit addresses.
     * 
     * Returns comprehensive wallet information for all enabled currencies, including:
     * - Spot and contract account balances (available and frozen)
     * - Deposit addresses and instructions for each currency
     * - Overall assets summary (total balance, principal, profit, bonus)
     * 
     * Each currency includes two account types:
     * - spot: Available and frozen balances in spot account
     * - contract: Available and frozen balances in contract account
     * 
     * @return JsonResponse Returns wallet information:
     * - wallets: Array of wallet objects, one per enabled currency, with balances and deposit info
     * - summary: Overall assets summary including total_balance, principal_balance, profit_balance, bonus_balance
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $currencies = Currency::where('enabled', true)->get();
        $summary = $this->assetsService->getSummary($user->id);

        // Get accounts
        $accounts = \App\Models\Account::where('user_id', $user->id)
            ->with('currencyModel')
            ->get()
            ->groupBy('currency');

        $wallets = [];
        foreach ($currencies as $currency) {
            $spotAccount = $accounts->get($currency->name)?->firstWhere('type', 'spot');
            $contractAccount = $accounts->get($currency->name)?->firstWhere('type', 'contract');

            $wallets[] = [
                'currency' => $currency->name,
                'precision' => $currency->precision,
                'spot' => [
                    'available' => $spotAccount ? $spotAccount->available->toFixed(6) : '0.000000',
                    'frozen' => $spotAccount ? $spotAccount->frozen->toFixed(6) : '0.000000',
                ],
                'contract' => [
                    'available' => $contractAccount ? $contractAccount->available->toFixed(6) : '0.000000',
                    'frozen' => $contractAccount ? $contractAccount->frozen->toFixed(6) : '0.000000',
                ],
                'deposit_address' => $this->getDepositAddress($currency->name),
                'deposit_memo' => $this->getDepositMemo($currency->name),
            ];
        }

        return response()->json([
            'wallets' => $wallets,
            'summary' => [
                'total_balance' => $summary->total_balance->toFixed(6),
                'principal_balance' => $summary->principal_balance->toFixed(6),
                'profit_balance' => $summary->profit_balance->toFixed(6),
                'bonus_balance' => $summary->bonus_balance->toFixed(6),
            ],
        ]);
    }

    /**
     * Get deposit address for currency (placeholder).
     */
    private function getDepositAddress(string $currency): string
    {
        // TODO: 集成实际的充币地址生成逻辑
        return 'T' . str_repeat('0', 33) . '1'; // TRC20 placeholder
    }

    /**
     * Get deposit memo/instructions.
     */
    private function getDepositMemo(string $currency): string
    {
        return "Please deposit {$currency} to the address above. Minimum deposit: 10 {$currency}";
    }
}
