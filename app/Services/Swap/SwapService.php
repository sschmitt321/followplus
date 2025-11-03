<?php

namespace App\Services\Swap;

use App\Models\Swap;
use App\Services\Ledger\LedgerService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class SwapService
{
    public function __construct(
        private LedgerService $ledgerService
    ) {
    }

    /**
     * Get swap quote.
     */
    public function quote(
        string $fromCurrency,
        string $toCurrency,
        Decimal|string $amount
    ): array {
        // TODO: 集成实际汇率API，这里返回占位数据
        $rate = $this->getRate($fromCurrency, $toCurrency);
        $amount = Decimal::of($amount);
        $amountTo = $amount->multiply($rate);

        return [
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'rate' => $rate->toFixed(6),
            'amount_from' => $amount->toFixed(6),
            'amount_to' => $amountTo->toFixed(6),
        ];
    }

    /**
     * Confirm swap.
     */
    public function confirm(
        int $userId,
        string $fromCurrency,
        string $toCurrency,
        Decimal|string $amountFrom,
        Decimal|string $amountTo,
        Decimal|string $rate
    ): Swap {
        return DB::transaction(function () use ($userId, $fromCurrency, $toCurrency, $amountFrom, $amountTo, $rate) {
            $amountFrom = Decimal::of($amountFrom);
            $amountTo = Decimal::of($amountTo);
            $rate = Decimal::of($rate);

            // Debit from currency
            $this->ledgerService->debit(
                $userId,
                'spot',
                $fromCurrency,
                $amountFrom,
                'swap',
                null
            );

            // Credit to currency
            $this->ledgerService->credit(
                $userId,
                'spot',
                $toCurrency,
                $amountTo,
                'swap',
                null
            );

            // Create swap record
            return Swap::create([
                'user_id' => $userId,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'rate_snapshot' => $rate->toFixed(6),
                'amount_from' => $amountFrom->toFixed(6),
                'amount_to' => $amountTo->toFixed(6),
                'status' => 'completed',
            ]);
        });
    }

    /**
     * Get exchange rate (placeholder).
     */
    private function getRate(string $from, string $to): Decimal
    {
        // TODO: 集成实际汇率API
        $rates = [
            'USDT' => ['BTC' => '0.000023', 'ETH' => '0.0004', 'USDC' => '1.0'],
            'BTC' => ['USDT' => '43000', 'ETH' => '17.4'],
            'ETH' => ['USDT' => '2500', 'BTC' => '0.057'],
        ];

        return Decimal::of($rates[$from][$to] ?? '1.0');
    }
}

