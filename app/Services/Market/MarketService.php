<?php

namespace App\Services\Market;

use App\Models\Symbol;
use App\Models\SymbolTick;
use App\Support\Decimal;

class MarketService
{
    /**
     * Generate fake tick data for all enabled symbols.
     */
    public function generateFakeTicks(): int
    {
        $symbols = Symbol::where('enabled', true)->get();
        $count = 0;

        foreach ($symbols as $symbol) {
            // Get latest tick or use base price
            $latestTick = SymbolTick::where('symbol_id', $symbol->id)
                ->latest('tick_at')
                ->first();

            $basePrice = $this->getBasePrice($symbol->base);
            
            if ($latestTick) {
                // Generate new price based on latest price with random variation
                $variation = (mt_rand(-500, 500) / 10000); // -5% to +5%
                $newPrice = Decimal::of($latestTick->last_price)
                    ->multiply(1 + $variation);
            } else {
                // First tick, use base price with small random variation
                $variation = (mt_rand(-100, 100) / 10000); // -1% to +1%
                $newPrice = Decimal::of($basePrice)
                    ->multiply(1 + $variation);
            }

            // Calculate change percent from base price
            $changePercent = $newPrice->subtract($basePrice)
                ->divide($basePrice)
                ->multiply(100);

            // Create tick
            SymbolTick::create([
                'symbol_id' => $symbol->id,
                'last_price' => $newPrice,
                'change_percent' => $changePercent->toFixed(4),
                'tick_at' => now(),
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Get base price for a symbol base currency.
     */
    private function getBasePrice(string $base): string
    {
        // Base prices in USDT (fake data)
        return match (strtoupper($base)) {
            'BTC' => '45000',
            'ETH' => '2500',
            'BNB' => '300',
            'SOL' => '100',
            default => '1000',
        };
    }

    /**
     * Get latest tick for a symbol.
     */
    public function getLatestTick(int $symbolId): ?SymbolTick
    {
        return SymbolTick::where('symbol_id', $symbolId)
            ->latest('tick_at')
            ->first();
    }

    /**
     * Get tick history for a symbol.
     */
    public function getTickHistory(int $symbolId, int $limit = 100): array
    {
        $ticks = SymbolTick::where('symbol_id', $symbolId)
            ->orderBy('tick_at', 'desc')
            ->limit($limit)
            ->get();

        return $ticks->map(function ($tick) {
            return [
                'last_price' => $tick->last_price->toFixed(6),
                'change_percent' => (float) $tick->change_percent,
                'tick_at' => $tick->tick_at->toIso8601String(),
            ];
        })->toArray();
    }
}

