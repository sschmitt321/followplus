<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Symbol;
use App\Services\Market\MarketService;
use Illuminate\Http\JsonResponse;

class MarketController extends Controller
{
    public function __construct(
        private MarketService $marketService
    ) {
    }

    /**
     * Get all enabled symbols.
     */
    public function symbols(): JsonResponse
    {
        $symbols = Symbol::where('enabled', true)->get();

        return response()->json([
            'symbols' => $symbols->map(function ($symbol) {
                return [
                    'id' => $symbol->id,
                    'base' => $symbol->base,
                    'quote' => $symbol->quote,
                    'name' => $symbol->name,
                ];
            }),
        ]);
    }

    /**
     * Get latest tick for a symbol.
     */
    public function tick(int $id): JsonResponse
    {
        $symbol = Symbol::findOrFail($id);
        $tick = $this->marketService->getLatestTick($symbol->id);

        if (!$tick) {
            return response()->json([
                'symbol_id' => $symbol->id,
                'symbol' => $symbol->name,
                'last_price' => null,
                'change_percent' => null,
                'tick_at' => null,
                'message' => 'No tick data available',
            ]);
        }

        return response()->json([
            'symbol_id' => $symbol->id,
            'symbol' => $symbol->name,
            'last_price' => $tick->last_price->toFixed(6),
            'change_percent' => (float) $tick->change_percent,
            'tick_at' => $tick->tick_at->toIso8601String(),
        ]);
    }

    /**
     * Get tick history for a symbol.
     */
    public function tickHistory(int $id): JsonResponse
    {
        $symbol = Symbol::findOrFail($id);
        $history = $this->marketService->getTickHistory($symbol->id, 100);

        return response()->json([
            'symbol_id' => $symbol->id,
            'symbol' => $symbol->name,
            'history' => $history,
        ]);
    }
}

